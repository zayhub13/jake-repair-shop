<?php
require __DIR__ . '/../config.php';
require_staff();

$area = 'admin';
$pdo = db();
$isNewJob = isset($_GET['new']);
$jobId = (int) ($_GET['id'] ?? 0);

$technicians = $pdo->query(
    'SELECT t.technician_id,u.full_name
     FROM technicians t
     JOIN users u ON u.user_id=t.user_id
     WHERE u.is_active=1
     ORDER BY u.full_name'
)->fetchAll();

$redirectToJob = function () use (&$jobId) {
    go('admin/job.php?id=' . $jobId);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';
    $value = static function ($key) {
        return trim($_POST[$key] ?? '');
    };

    if ($action === 'create') {
        $jobData = [
            'name' => $value('name'),
            'contact' => $value('contact'),
            'email' => $value('email'),
            'brand' => $value('brand'),
            'model' => $value('model'),
            'imei' => $value('imei'),
            'issue' => $value('issue'),
            'notes' => $value('notes'),
            'consent' => isset($_POST['consent']),
        ];

        if (
            $jobData['name'] === ''
            || $jobData['contact'] === ''
            || $jobData['brand'] === ''
            || $jobData['model'] === ''
            || $jobData['issue'] === ''
        ) {
            flash('Fill in name, contact, brand, model and the issue.', 'err');
            go('admin/job.php?new=1');
        }

        $assignedTechnicianId = user()['role'] === 'admin'
            ? ((int) $value('tech') ?: null)
            : my_tech_id();

        try {
            $createdJob = create_job($jobData, $assignedTechnicianId);
            audit('create', 'job_orders', $createdJob['id']);
            flash('Service request ' . $createdJob['ref'] . ' created.');
            go('admin/job.php?id=' . $createdJob['id']);
        } catch (PDOException $exception) {
            flash(
                'Could not save. The contact number or email may belong to a different customer.',
                'err'
            );
            go('admin/job.php?new=1');
        }
    }

    $jobId = (int) ($_POST['id'] ?? 0);

    if (user()['role'] === 'technician') {
        $statement = $pdo->prepare(
            'SELECT assigned_tech_id FROM job_orders WHERE job_order_id=?'
        );
        $statement->execute([$jobId]);
        $assignedTechnicianId = $statement->fetchColumn();

        if (
            !$assignedTechnicianId
            || (int) $assignedTechnicianId !== (int) (my_tech_id() ?: 0)
        ) {
            flash('That job is assigned to someone else.', 'err');
            go('admin/jobs.php');
        }
    }

    if ($action === 'details') {
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'SELECT assigned_tech_id,current_status_id
                 FROM job_orders WHERE job_order_id=? FOR UPDATE'
            );
            $statement->execute([$jobId]);
            $currentJob = $statement->fetch();

            if (!$currentJob) {
                $pdo->rollBack();
                flash('Service request not found.', 'err');
            } else {
                $statement = $pdo->prepare(
                    'SELECT
                        rs.status_name=\'in_repair\'
                        OR EXISTS (
                            SELECT 1
                            FROM status_history h
                            JOIN repair_statuses history_status
                                ON history_status.status_id=h.status_id
                            WHERE h.job_order_id=?
                                AND history_status.status_name=\'in_repair\'
                        )
                     FROM repair_statuses rs
                     WHERE rs.status_id=?'
                );
                $statement->execute([$jobId, $currentJob['current_status_id']]);
                $technicianLocked = (bool) $statement->fetchColumn();
                $assignedTechnicianId = $currentJob['assigned_tech_id'];

                if (user()['role'] === 'admin' && !$technicianLocked) {
                    $assignedTechnicianId = (int) $value('tech') ?: null;
                }

                $pdo->prepare(
                    'UPDATE job_orders
                     SET assigned_tech_id=?,diagnosis=?,estimated_cost=?,condition_notes=?
                     WHERE job_order_id=?'
                )->execute([
                    $assignedTechnicianId,
                    $value('diagnosis') ?: null,
                    $value('cost') === '' ? null : (float) $value('cost'),
                    $value('condition') ?: null,
                    $jobId,
                ]);

                audit('update', 'job_orders', $jobId);
                $pdo->commit();
                flash('Job details saved.');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    } elseif ($action === 'status') {
        $statusId = (int) $value('status_id');
        $statement = $pdo->prepare(
            'SELECT status_name FROM repair_statuses WHERE status_id=?'
        );
        $statement->execute([$statusId]);
        $statusName = $statement->fetchColumn();

        if (!$statusName) {
            flash('Choose a valid status.', 'err');
        } elseif ($statusName === 'cancelled' && $value('cancel_reason') === '') {
            flash('Enter a cancel reason.', 'err');
        } else {
            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare(
                    'SELECT j.assigned_tech_id,j.current_status_id,s.status_name
                     FROM job_orders j
                     JOIN repair_statuses s ON s.status_id=j.current_status_id
                     WHERE j.job_order_id=? FOR UPDATE'
                );
                $statement->execute([$jobId]);
                $currentJob = $statement->fetch();
                if (!$currentJob) {
                    $pdo->rollBack();
                    flash('Service request not found.', 'err');
                    $redirectToJob();
                }

                $allowedTransitions = [
                    'received' => ['diagnosing', 'cancelled'],
                    'diagnosing' => ['awaiting_approval', 'awaiting_parts', 'in_repair', 'cancelled'],
                    'awaiting_approval' => ['awaiting_parts', 'in_repair', 'cancelled'],
                    'awaiting_parts' => ['in_repair', 'cancelled'],
                    'in_repair' => ['awaiting_parts', 'ready_for_pickup', 'cancelled'],
                    'ready_for_pickup' => ['in_repair', 'completed', 'cancelled'],
                    'completed' => [],
                    'cancelled' => [],
                ];

                if (!in_array(
                    $statusName,
                    $allowedTransitions[$currentJob['status_name']] ?? [],
                    true
                )) {
                    $pdo->rollBack();
                    flash('That status change is not allowed from the current status.', 'err');
                } elseif (
                    $statusName === 'in_repair'
                    && !$currentJob['assigned_tech_id']
                ) {
                    $pdo->rollBack();
                    flash('Assign a technician before moving the job to in repair.', 'err');
                } else {
                    set_status(
                        $jobId,
                        $statusId,
                        $value('remarks'),
                        $value('cancel_reason')
                    );
                    audit('status', 'job_orders', $jobId);
                    $pdo->commit();
                    flash('Status updated.');
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } elseif ($action === 'note') {
        $statement = $pdo->prepare(
            'SELECT assigned_tech_id FROM job_orders WHERE job_order_id=?'
        );
        $statement->execute([$jobId]);
        $technicianId = my_tech_id() ?: $statement->fetchColumn();

        if (!$technicianId) {
            flash('Assign a technician first. Notes are saved under a technician.', 'err');
        } elseif ($value('note') === '') {
            flash('Write a note first.', 'err');
        } else {
            $pdo->prepare(
                'INSERT INTO job_notes (job_order_id,technician_id,note_text,parts_used)
                 VALUES (?,?,?,?)'
            )->execute([
                $jobId,
                $technicianId,
                $value('note'),
                $value('parts_used') ?: null,
            ]);
            audit('note', 'job_orders', $jobId);
            flash('Note added.');
        }
    } elseif ($action === 'status_image') {
        $statement = $pdo->prepare(
            'SELECT assigned_tech_id FROM job_orders WHERE job_order_id=?'
        );
        $statement->execute([$jobId]);
        $assignedTechId = (int) $statement->fetchColumn();

        if (user()['role'] !== 'technician' && user()['role'] !== 'admin') {
            flash('Only technicians and admins may attach images.', 'err');
        } elseif ($assignedTechId && user()['role'] === 'technician' && my_tech_id() !== $assignedTechId) {
            flash('This service request is assigned to another technician.', 'err');
        } else {
            $imageName = save_uploaded_status_image($_FILES['image'] ?? []);
            if ($imageName === null) {
                flash('Upload a valid JPG, PNG, GIF or WEBP image up to 2MB.', 'err');
            } else {
                $statusId = (int) ($_POST['status_id'] ?? 0);
                if ($statusId > 0) {
                    $pdo->prepare(
                        'UPDATE status_history SET image_path=? WHERE history_id=(SELECT history_id FROM (SELECT history_id FROM status_history WHERE job_order_id=? AND status_id=? ORDER BY changed_at DESC, history_id DESC LIMIT 1) AS x)'
                    )->execute([$imageName, $jobId, $statusId]);
                }
                flash('Status image uploaded.');
            }
        }
    } elseif ($action === 'notify') {
        $statement = $pdo->prepare(
            'SELECT c.customer_id,c.email,c.consent_given
             FROM job_orders j
             JOIN customers c ON c.customer_id=j.customer_id
             WHERE j.job_order_id=?'
        );
        $statement->execute([$jobId]);
        $customer = $statement->fetch();
        $selectedChannel = $value('channel');
        $channel = [
            'sms' => 'sms',
            'email' => 'email',
            'website' => 'in_app',
        ][$selectedChannel] ?? 'sms';
        $message = $value('message');
        $uploadError = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        $hasImage = $uploadError !== UPLOAD_ERR_NO_FILE;
        $finishedStatus = null;
        $imageName = null;

        if (!$customer) {
            flash('Service request not found.', 'err');
        } elseif ($message === '') {
            flash('Write a message first.', 'err');
        } elseif (
            $hasImage
            && user()['role'] !== 'technician'
            && user()['role'] !== 'admin'
        ) {
            flash('Only technicians and admins may attach finished repair images.', 'err');
        } elseif ($hasImage && $uploadError !== UPLOAD_ERR_OK) {
            flash('The image upload failed. Choose an image up to 2MB and try again.', 'err');
        } elseif ($channel === 'email' && !$customer['email']) {
            flash('This customer has no email on file. Use SMS.', 'err');
        } elseif ($channel !== 'in_app' && !$customer['consent_given']) {
            flash('The customer has not agreed to external notifications.', 'err');
        } else {
            if ($hasImage) {
                $statement = $pdo->prepare(
                    'SELECT j.reference_no,s.status_name,
                        (
                            SELECT h.history_id
                            FROM status_history h
                            WHERE h.job_order_id=j.job_order_id
                                AND h.status_id=j.current_status_id
                            ORDER BY h.changed_at DESC,h.history_id DESC
                            LIMIT 1
                        ) AS history_id
                     FROM job_orders j
                     JOIN repair_statuses s ON s.status_id=j.current_status_id
                     WHERE j.job_order_id=?'
                );
                $statement->execute([$jobId]);
                $finishedStatus = $statement->fetch();

                if (
                    !$finishedStatus
                    || !in_array($finishedStatus['status_name'], ['ready_for_pickup', 'completed'], true)
                    || !$finishedStatus['history_id']
                ) {
                    flash('Set the status to Ready for pickup or Completed before attaching a finished repair photo.', 'err');
                } else {
                    $imageName = save_uploaded_status_image($_FILES['image']);

                    if ($imageName === null) {
                        flash('Upload a valid JPG, PNG, GIF or WEBP image up to 2MB.', 'err');
                    } else {
                        try {
                            $pdo->prepare(
                                'UPDATE status_history SET image_path=? WHERE history_id=?'
                            )->execute([$imageName, $finishedStatus['history_id']]);
                        } catch (Throwable $exception) {
                            @unlink(__DIR__ . '/../uploads/' . $imageName);
                            throw $exception;
                        }
                    }
                }
            }

            if (!$hasImage || $imageName !== null) {
                notify($jobId, $customer['customer_id'], 'manual', $message, $channel, $imageName);
                audit('notify', 'job_orders', $jobId);
                if ($channel === 'in_app') {
                    flash($hasImage
                        ? 'Website update and photo are now visible on the customer tracking page.'
                        : 'Website update is now visible on the customer tracking page.');
                } else {
                    flash($hasImage
                        ? 'Photo attached and message recorded as pending delivery.'
                        : 'Message recorded as pending delivery.');
                }
            }
        }
    } elseif ($action === 'part') {
        $partId = (int) $value('part_id');
        $quantity = filter_var($value('qty'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($quantity === false) {
            flash('Enter a valid positive quantity.', 'err');
        } else {
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare(
                    'SELECT unit_cost FROM parts WHERE part_id=? AND stock_qty>=? FOR UPDATE'
                );
                $statement->execute([$partId, $quantity]);
                $part = $statement->fetch();

                if (!$part) {
                    $pdo->rollBack();
                    flash('Not enough stock for that part.', 'err');
                } else {
                    $pdo->prepare(
                        'INSERT INTO job_order_parts
                            (job_order_id,part_id,quantity_used,unit_price)
                         VALUES (?,?,?,?)'
                    )->execute([$jobId, $partId, $quantity, $part['unit_cost']]);

                    $pdo->prepare(
                        'UPDATE parts SET stock_qty=stock_qty-? WHERE part_id=?'
                    )->execute([$quantity, $partId]);

                    $pdo->prepare(
                        'INSERT INTO stock_movements
                            (part_id,movement_type,quantity,unit_cost,admin_user_id,notes)
                         VALUES (?,?,?,?,?,?)'
                    )->execute([
                        $partId,
                        'usage',
                        -$quantity,
                        $part['unit_cost'],
                        user()['user_id'],
                        'Used on job #' . $jobId,
                    ]);

                    audit('add_part', 'job_orders', $jobId);
                    $pdo->commit();
                    flash('Part added and stock updated.');
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    }

    $redirectToJob();
}

if ($isNewJob) {
    $title = 'New service request';
    require __DIR__ . '/../includes/header.php';
    ?>

    <h1>Create new service request</h1>

    <form method="post" class="card form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <h3>Customer</h3>
        <div class="two">
            <label>Full name *
                <input name="name" required>
            </label>
            <label>Contact number *
                <input name="contact" required inputmode="tel">
            </label>
        </div>
        <label>Email
            <input type="email" name="email">
        </label>

        <h3>Device</h3>
        <div class="two">
            <label>Brand *
                <input name="brand" required>
            </label>
            <label>Model *
                <input name="model" required>
            </label>
        </div>
        <label>IMEI / serial
            <input name="imei">
        </label>
        <label>Issue description *
            <textarea name="issue" rows="3" required></textarea>
        </label>
        <label>Condition notes (scratches, accessories left, passcode given)
            <textarea name="notes" rows="2"></textarea>
        </label>

        <?php if (user()['role'] === 'admin'): ?>
            <label>Assigned technician
                <select name="tech">
                    <option value="0">Unassigned</option>
                    <?php foreach ($technicians as $technician): ?>
                        <option value="<?= $technician['technician_id'] ?>">
                            <?= e($technician['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <label class="check">
            <input type="checkbox" name="consent">
            Customer agreed to SMS or email updates
        </label>

        <div class="actions">
            <button class="btn btn-primary">Create service request</button>
            <a class="btn btn-ghost" href="jobs.php">Cancel</a>
        </div>
    </form>

    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$statement = $pdo->prepare(
    'SELECT j.*,c.full_name,c.contact_number,c.email,c.consent_given,
        c.customer_id AS customer_id,
        d.brand,d.model,d.imei_serial,rs.status_name,
        (
            rs.status_name=\'in_repair\'
            OR EXISTS (
                SELECT 1
                FROM status_history h
                JOIN repair_statuses history_status
                    ON history_status.status_id=h.status_id
                WHERE h.job_order_id=j.job_order_id
                    AND history_status.status_name=\'in_repair\'
            )
        ) AS technician_locked
     FROM job_orders j
     JOIN customers c ON c.customer_id=j.customer_id
     JOIN devices d ON d.device_id=j.device_id
     JOIN repair_statuses rs ON rs.status_id=j.current_status_id
     WHERE j.job_order_id=?'
);
$statement->execute([$jobId]);
$job = $statement->fetch();

if (!$job) {
    flash('Service request not found.', 'err');
    go('admin/jobs.php');
}

if (
    user()['role'] === 'technician'
    && (
        !$job['assigned_tech_id']
        || (int) $job['assigned_tech_id'] !== (int) (my_tech_id() ?: 0)
    )
) {
    flash('That job is assigned to someone else.', 'err');
    go('admin/jobs.php');
}

$title = $job['reference_no'];

$statement = $pdo->prepare(
    'SELECT n.*,u.full_name
     FROM job_notes n
     JOIN technicians t ON t.technician_id=n.technician_id
     JOIN users u ON u.user_id=t.user_id
     WHERE n.job_order_id=?
     ORDER BY n.created_at DESC'
);
$statement->execute([$jobId]);
$notes = $statement->fetchAll();

$statement = $pdo->prepare(
    'SELECT h.*,s.status_name
     FROM status_history h
     JOIN repair_statuses s ON s.status_id=h.status_id
     WHERE h.job_order_id=?
     ORDER BY h.changed_at DESC,h.history_id DESC'
);
$statement->execute([$jobId]);
$history = $statement->fetchAll();

$statement = $pdo->prepare(
    'SELECT jp.*,p.part_name
     FROM job_order_parts jp
     JOIN parts p ON p.part_id=jp.part_id
     WHERE jp.job_order_id=?'
);
$statement->execute([$jobId]);
$usedParts = $statement->fetchAll();
$availableParts = $pdo->query(
    'SELECT * FROM parts WHERE stock_qty>0 ORDER BY part_name'
)->fetchAll();

$photoUrl = !empty($job['customer_photo_path'])
    ? BASE_URL . 'image.php?ref=' . rawurlencode($job['reference_no'])
        . '&file=' . rawurlencode(basename($job['customer_photo_path']))
    : null;
$partsTotal = array_sum(array_map(
    fn($part) => $part['quantity_used'] * $part['unit_price'],
    $usedParts
));
$messageTemplates = [
    '' => 'Quick message...',
    'Your phone is ready for pickup at ' . SHOP_NAME
        . '. Please bring your reference number ' . $job['reference_no'] . '.' => 'Ready for pickup',
    'We found the problem and need your approval for the repair cost. Please reply or call us.'
        => 'Ask for approval',
    'Your repair is waiting for a part. We will message you when it arrives.'
        => 'Waiting for parts',
];

require __DIR__ . '/../includes/header.php';
?>

<div class="rowhead">
    <div>
        <a href="jobs.php" class="back">Back to service requests</a>
        <h1><?= e($job['reference_no']) ?> <?= badge($job['status_name']) ?></h1>
    </div>
    <button class="btn btn-ghost" onclick="window.print()">Print job slip</button>
</div>

<div class="grid2">
    <div>
        <div class="card">
            <h3>Customer</h3>
            <p>
                <strong>
                    <a href="customer.php?id=<?= $job['customer_id'] ?>">
                        <?= e($job['full_name']) ?>
                    </a>
                </strong>
                <br><?= e($job['contact_number']) ?>
                <?= $job['email'] ? '<br>' . e($job['email']) : '' ?><br>
                <small class="muted">
                    <?= $job['consent_given'] ? 'Agreed to notifications' : 'No notification consent' ?>
                </small>
            </p>

            <h3>Device</h3>
            <p>
                <?= e($job['brand'] . ' ' . $job['model']) ?>
                <?= $job['imei_serial'] ? '<br>IMEI/Serial: ' . e($job['imei_serial']) : '' ?>
            </p>

            <h3>Reported issue</h3>
            <p><?= nl2br(e($job['reported_issue'])) ?></p>

            <?php if ($photoUrl): ?>
                <a href="<?= $photoUrl ?>" target="_blank">
                    <img class="shot" src="<?= $photoUrl ?>" alt="Customer photo of the damage">
                </a>
            <?php endif; ?>
        </div>

        <form method="post" class="card form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="details">
            <input type="hidden" name="id" value="<?= $jobId ?>">

            <h3>Diagnosis and cost</h3>
            <label>Assigned technician
                <select
                    name="tech"
                    <?= user()['role'] === 'technician' || $job['technician_locked'] ? 'disabled' : '' ?>
                >
                    <option value="0">Unassigned</option>
                    <?php foreach ($technicians as $technician): ?>
                        <option
                            value="<?= $technician['technician_id'] ?>"
                            <?= $job['assigned_tech_id'] == $technician['technician_id'] ? 'selected' : '' ?>
                        ><?= e($technician['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($job['technician_locked']): ?>
                <small class="muted">
                    Technician assignment is locked because this job has entered in repair.
                </small>
            <?php endif; ?>
            <?php if (user()['role'] === 'technician'): ?>
                <input
                    type="hidden"
                    name="tech"
                    value="<?= (int) $job['assigned_tech_id'] ?>"
                >
            <?php endif; ?>
            <label>Diagnosis
                <textarea name="diagnosis" rows="3"><?= e($job['diagnosis']) ?></textarea>
            </label>
            <label>Condition notes
                <textarea name="condition" rows="2"><?= e($job['condition_notes']) ?></textarea>
            </label>
            <label>Estimated cost (PHP)
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="cost"
                    value="<?= e($job['estimated_cost']) ?>"
                >
            </label>
            <button class="btn btn-primary">Save details</button>
        </form>

        <div class="card">
            <h3>Parts used</h3>
            <?php if ($usedParts): ?>
                <table class="tbl">
                    <tbody>
                        <?php foreach ($usedParts as $part): ?>
                            <tr>
                                <td><?= e($part['part_name']) ?></td>
                                <td>x<?= $part['quantity_used'] ?></td>
                                <td class="num">
                                    PHP <?= number_format(
                                        $part['unit_price'] * $part['quantity_used'],
                                        2
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="2"><strong>Parts total</strong></td>
                            <td class="num">
                                <strong>PHP <?= number_format($partsTotal, 2) ?></strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="muted">No parts added yet.</p>
            <?php endif; ?>

            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="part">
                <input type="hidden" name="id" value="<?= $jobId ?>">
                <select name="part_id" required aria-label="Part">
                    <option value="">Choose a part</option>
                    <?php foreach ($availableParts as $part): ?>
                        <option value="<?= $part['part_id'] ?>">
                            <?= e($part['part_name']) ?> (<?= $part['stock_qty'] ?> in stock)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input
                    type="number"
                    name="qty"
                    value="1"
                    min="1"
                    aria-label="Quantity"
                    class="qty"
                >
                <button class="btn btn-sm btn-primary">Add part</button>
            </form>
        </div>
    </div>

    <div>
        <form method="post" class="card form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= $jobId ?>">

            <h3>Update status</h3>
            <label>New status
                <select name="status_id" id="statusSelect">
                    <?php foreach (statuses() as $status): ?>
                        <option
                            value="<?= $status['status_id'] ?>"
                            <?= $job['current_status_id'] == $status['status_id'] ? 'selected' : '' ?>
                        ><?= e(pretty_status($status['status_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Remarks (shown to the customer)
                <input name="remarks" maxlength="255">
            </label>
            <label id="cancelRow" hidden>Cancel reason
                <input
                    name="cancel_reason"
                    maxlength="255"
                    value="<?= e($job['cancel_reason']) ?>"
                >
            </label>
            <button class="btn btn-primary">Update status</button>
            <small class="muted">
                Milestone statuses send a message automatically when the customer agreed to updates.
            </small>
        </form>

        <form method="post" enctype="multipart/form-data" class="card form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="notify">
            <input type="hidden" name="id" value="<?= $jobId ?>">

            <h3>Notify customer</h3>
            <select data-fill="msg" aria-label="Message template">
                <?php foreach ($messageTemplates as $message => $label): ?>
                    <option value="<?= e($message) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <textarea
                name="message"
                id="msg"
                rows="3"
                required
                placeholder="Write your message"
            ></textarea>
            <label>Finished repair photo (optional)
                <input
                    type="file"
                    name="image"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                >
            </label>
            <small class="muted">
                Available when the status is Ready for pickup or Completed. JPG, PNG, GIF or WEBP, up to 2MB.
            </small>
            <div class="two">
                <select name="channel">
                    <option value="sms">SMS</option>
                    <option value="email">Email</option>
                    <option value="website">Website tracking page</option>
                </select>
                <button class="btn btn-accent">Send message</button>
            </div>
        </form>

        <form method="post" class="card form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="note">
            <input type="hidden" name="id" value="<?= $jobId ?>">

            <h3>Notes and work log</h3>
            <textarea
                name="note"
                rows="3"
                required
                placeholder="What did you check or do?"
            ></textarea>
            <input
                name="parts_used"
                placeholder="Parts used (optional text)"
                maxlength="255"
            >
            <button class="btn btn-primary">Add note</button>

            <?php foreach ($notes as $note): ?>
                <div class="logitem">
                    <?= nl2br(e($note['note_text'])) ?>
                    <?= $note['parts_used'] ? '<br><em>Parts: ' . e($note['parts_used']) . '</em>' : '' ?>
                    <small>
                        <?= e($note['full_name']) ?>
                        · <?= date('M j, g:i A', strtotime($note['created_at'])) ?>
                    </small>
                </div>
            <?php endforeach; ?>
        </form>

        <div class="card">
            <h3>Status history</h3>
            <ul class="timeline">
                <?php foreach ($history as $entry): ?>
                    <li>
                        <strong><?= e(pretty_status($entry['status_name'])) ?></strong>
                        <?= $entry['remarks'] ? ' · ' . e($entry['remarks']) : '' ?>
                        <?php if (!empty($entry['image_path'])): ?>
                            <?php
                            $imageUrl = BASE_URL . 'image.php?ref='
                                . rawurlencode($job['reference_no']) . '&file='
                                . rawurlencode(basename($entry['image_path']));
                            ?>
                            <br>
                            <a
                                href="<?= e($imageUrl) ?>"
                                target="_blank"
                                rel="noopener"
                            >
                                <img
                                    class="shot"
                                    src="<?= e($imageUrl) ?>"
                                    alt="Finished repair photo"
                                >
                            </a>
                        <?php endif; ?>
                        <small>
                            <?= date('M j, Y g:i A', strtotime($entry['changed_at'])) ?>
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
