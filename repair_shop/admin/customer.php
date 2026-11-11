<?php
require __DIR__ . '/../config.php';
require_staff();

$area = 'admin';
$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? 'profile';

    if ($action === 'device') {
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');

        if ($brand === '' || $model === '') {
            flash('Enter the device brand and model.', 'err');
        } else {
            $supplierId = (int) ($_POST['supplier'] ?? 0) ?: null;
            $imei = trim($_POST['imei'] ?? '') ?: null;

            $pdo->prepare(
                'INSERT INTO devices (customer_id,supplier_id,brand,model,imei_serial) VALUES (?,?,?,?,?)'
            )->execute([$id, $supplierId, $brand, $model, $imei]);

            audit('add_device', 'customers', $id);
            flash('Device added.');
        }

        go('admin/customer.php?id=' . $id);
    }

    if ($action === 'device_delete') {
        $deviceId = (int) ($_POST['device_id'] ?? 0);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM job_orders WHERE device_id=?');
        $statement->execute([$deviceId]);

        if ($statement->fetchColumn() > 0) {
            flash('This device has repair history, so it is kept.', 'err');
        } else {
            $pdo->prepare('DELETE FROM devices WHERE device_id=? AND customer_id=?')
                ->execute([$deviceId, $id]);
            audit('delete_device', 'customers', $id);
            flash('Device removed.');
        }

        go('admin/customer.php?id=' . $id);
    }

    try {
        $pdo->prepare(
            'UPDATE customers SET full_name=?,contact_number=?,email=?,address=?,consent_given=? WHERE customer_id=?'
        )->execute([
            trim($_POST['name'] ?? ''),
            trim($_POST['contact'] ?? ''),
            trim($_POST['email'] ?? '') ?: null,
            trim($_POST['address'] ?? '') ?: null,
            isset($_POST['consent']) ? 1 : 0,
            $id,
        ]);

        audit('update', 'customers', $id);
        flash('Customer updated.');
    } catch (PDOException $exception) {
        flash('That contact number or email is already used by another customer.', 'err');
    }

    go('admin/customer.php?id=' . $id);
}

$statement = $pdo->prepare('SELECT * FROM customers WHERE customer_id=?');
$statement->execute([$id]);
$customer = $statement->fetch();

if (!$customer) {
    flash('Customer not found.', 'err');
    go('admin/customers.php');
}

$title = $customer['full_name'];

$suppliers = $pdo->query(
    'SELECT supplier_id,supplier_name FROM suppliers ORDER BY supplier_name'
)->fetchAll();

$statement = $pdo->prepare(
    'SELECT d.*,
        (SELECT COUNT(*) FROM job_orders j WHERE j.device_id=d.device_id) AS jobs,
        s.supplier_name
     FROM devices d
     LEFT JOIN suppliers s ON s.supplier_id=d.supplier_id
     WHERE d.customer_id=?'
);
$statement->execute([$id]);
$devices = $statement->fetchAll();

$statement = $pdo->prepare(
    'SELECT j.job_order_id,j.reference_no,j.created_at,d.brand,d.model,s.status_name
     FROM job_orders j
     JOIN devices d ON d.device_id=j.device_id
     JOIN repair_statuses s ON s.status_id=j.current_status_id
     WHERE j.customer_id=?
     ORDER BY j.created_at DESC'
);
$statement->execute([$id]);
$history = $statement->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<a href="customers.php" class="back">Back to customers</a>
<h1>Customer details</h1>

<div class="grid2">
    <form method="post" class="card form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <h3>Profile</h3>
        <label>Full name
            <input name="name" value="<?= e($customer['full_name']) ?>" required>
        </label>
        <label>Contact number
            <input name="contact" value="<?= e($customer['contact_number']) ?>" required>
        </label>
        <label>Email
            <input type="email" name="email" value="<?= e($customer['email']) ?>">
        </label>
        <label>Address
            <input name="address" value="<?= e($customer['address']) ?>">
        </label>
        <label class="check">
            <input type="checkbox" name="consent" <?= $customer['consent_given'] ? 'checked' : '' ?>>
            Agreed to SMS/email updates
        </label>
        <button class="btn btn-primary">Save changes</button>
    </form>

    <div>
        <div class="card">
            <h3>Devices</h3>
            <?php foreach ($devices as $device): ?>
                <div class="logitem">
                    <strong><?= e($device['brand'] . ' ' . $device['model']) ?></strong>
                    <?php if ($device['imei_serial']): ?>
                        <br><small class="muted">IMEI/Serial: <?= e($device['imei_serial']) ?></small>
                    <?php endif; ?>
                    <?php if ($device['supplier_name']): ?>
                        <br><small class="muted">Supplier: <?= e($device['supplier_name']) ?></small>
                    <?php endif; ?>

                    <?php if (!$device['jobs']): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="device_delete">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                            <button class="btn btn-sm btn-danger" data-confirm="Remove this device?">
                                Remove
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (!$devices): ?>
                <p class="muted">No devices yet.</p>
            <?php endif; ?>

            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="device">
                <input type="hidden" name="id" value="<?= $id ?>">
                <h3>Add a device</h3>
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
                <label>Supplier (optional)
                    <select name="supplier">
                        <option value="0">None</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= $supplier['supplier_id'] ?>">
                                <?= e($supplier['supplier_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-primary">Add device</button>
            </form>
        </div>

        <div class="card scroll">
            <h3>Linked repair history</h3>
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Job #</th>
                        <th>Device</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $job): ?>
                        <tr>
                            <td><?= e($job['reference_no']) ?></td>
                            <td><?= e($job['brand'] . ' ' . $job['model']) ?></td>
                            <td><?= badge($job['status_name']) ?></td>
                            <td>
                                <a class="btn btn-sm btn-ghost" href="job.php?id=<?= $job['job_order_id'] ?>">
                                    Open
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$history): ?>
                        <tr>
                            <td colspan="4" class="empty">No repairs yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
