<?php
require __DIR__ . '/config.php';

$title = 'Track Repair';
$reference = strtoupper(trim($_GET['ref'] ?? ''));
$job = null;
$history = [];
$milestones = [];
$customerJobs = [];
$latestWebsiteUpdate = null;

if ($reference !== '') {
    $staffViewer = is_staff() ? 1 : 0;
    $statement = db()->prepare(
        'SELECT j.*,c.full_name,d.brand,d.model,
            s.status_name,s.customer_label,s.sort_order
         FROM job_orders j
         JOIN customers c ON c.customer_id=j.customer_id
         JOIN devices d ON d.device_id=j.device_id
         JOIN repair_statuses s ON s.status_id=j.current_status_id
         WHERE j.reference_no=?
           AND (
                j.reference_no NOT REGEXP \'^JCRS-[0-9]+$\'
                OR ?=1
           )'
    );
    $statement->execute([$reference, $staffViewer]);
    $job = $statement->fetch();

    if ($job) {
        $statement = db()->prepare(
            'SELECT h.*,s.customer_label
             FROM status_history h
             JOIN repair_statuses s ON s.status_id=h.status_id
             WHERE h.job_order_id=?
             ORDER BY h.changed_at DESC,h.history_id DESC'
        );
        $statement->execute([$job['job_order_id']]);
        $history = $statement->fetchAll();

        $statement = db()->prepare(
            "SELECT message,image_path,sent_at
             FROM notifications
             WHERE job_order_id=? AND channel='in_app'
             ORDER BY sent_at DESC,notification_id DESC
             LIMIT 1"
        );
        $statement->execute([$job['job_order_id']]);
        $latestWebsiteUpdate = $statement->fetch();

        $milestones = db()->query(
            "SELECT *
             FROM repair_statuses
             WHERE is_milestone=1 AND status_name<>'cancelled'
             ORDER BY sort_order"
        )->fetchAll();
    }
}

if (user() && user()['role'] === 'customer') {
    $statement = db()->prepare(
        'SELECT j.reference_no,j.created_at,d.brand,d.model,s.status_name
         FROM job_orders j
         JOIN customers c ON c.customer_id=j.customer_id
         JOIN devices d ON d.device_id=j.device_id
         JOIN repair_statuses s ON s.status_id=j.current_status_id
         WHERE c.user_id=?
           AND j.reference_no NOT REGEXP \'^JCRS-[0-9]+$\'
         ORDER BY j.created_at DESC'
    );
    $statement->execute([user()['user_id']]);
    $customerJobs = $statement->fetchAll();
}

require __DIR__ . '/includes/header.php';
?>

<div class="narrow">
    <h1>Track your repair</h1>

    <form class="searchbar" method="get">
        <input
            name="ref"
            value="<?= e($reference) ?>"
            placeholder="Enter service request / reference number"
            aria-label="Reference number"
        >
        <button class="btn btn-primary">Search</button>
    </form>

    <?php if ($reference !== '' && !$job): ?>
        <div class="flash err">
            No repair found for "<?= e($reference) ?>".
            Check the number on your confirmation and try again.
        </div>
    <?php endif; ?>

    <?php if ($job): ?>
        <?php $isCancelled = $job['status_name'] === 'cancelled'; ?>
        <div class="card">
            <div class="rowhead">
                <div>
                    <small class="muted">Device</small>
                    <h2><?= e($job['brand'] . ' ' . $job['model']) ?></h2>
                    <span class="muted">
                        <?= e($job['reference_no']) ?>
                        · received <?= date('M j, Y', strtotime($job['created_at'])) ?>
                    </span>
                </div>
                <?= badge($job['customer_label']) ?>
            </div>
            <?php if (!empty($job['customer_photo_path'])): ?>
                <?php
                $customerImageUrl = BASE_URL . 'image.php?ref='
                    . rawurlencode($job['reference_no']) . '&file='
                    . rawurlencode(basename($job['customer_photo_path']));
                ?>
                <p>
                    <a href="<?= e($customerImageUrl) ?>" target="_blank" rel="noopener">
                        <img class="shot" src="<?= e($customerImageUrl) ?>" alt="Customer photo of the device">
                    </a>
                </p>
            <?php endif; ?>

            <?php if ($isCancelled): ?>
                <div class="flash err">
                    This repair was cancelled.
                    <?= $job['cancel_reason'] ? ' Reason: ' . e($job['cancel_reason']) : '' ?>
                </div>
            <?php else: ?>
                <ol class="stepper">
                    <?php foreach ($milestones as $milestone): ?>
                        <?php
                        $isComplete = $job['sort_order'] >= $milestone['sort_order'];
                        $isCurrent = $isComplete && !array_filter(
                            $milestones,
                            fn($nextMilestone) =>
                                $nextMilestone['sort_order'] > $milestone['sort_order']
                                && $job['sort_order'] >= $nextMilestone['sort_order']
                        );
                        ?>
                        <li class="<?= $isComplete ? 'done' : '' ?> <?= $isCurrent ? 'now' : '' ?>">
                            <span></span><?= e($milestone['customer_label']) ?>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <?php if ($job['estimated_cost'] !== null): ?>
                    <p>
                        Estimated cost:
                        <strong>PHP <?= number_format($job['estimated_cost'], 2) ?></strong>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <h3>History</h3>
            <ul class="timeline">
                <?php foreach ($history as $entry): ?>
                    <li>
                        <strong><?= e($entry['customer_label']) ?></strong>
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

            <div class="note">
                <strong>Latest update from the repair team</strong>
                <?php if ($latestWebsiteUpdate): ?>
                    <p><?= nl2br(e($latestWebsiteUpdate['message'])) ?></p>
                    <?php if (!empty($latestWebsiteUpdate['image_path'])): ?>
                        <?php
                        $imageUrl = BASE_URL . 'image.php?ref='
                            . rawurlencode($job['reference_no']) . '&file='
                            . rawurlencode(basename($latestWebsiteUpdate['image_path']));
                        ?>
                        <a
                            href="<?= e($imageUrl) ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            <img
                                class="shot"
                                src="<?= e($imageUrl) ?>"
                                alt="Photo attached to the latest repair update"
                            >
                        </a>
                    <?php endif; ?>
                    <small>
                        <?= date('M j, Y g:i A', strtotime($latestWebsiteUpdate['sent_at'])) ?>
                    </small>
                <?php else: ?>
                    <p>No website update from the repair team yet.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($customerJobs): ?>
        <h2 class="sec">My repairs</h2>
        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Number</th>
                        <th>Device</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customerJobs as $customerJob): ?>
                        <tr>
                            <td><?= e($customerJob['reference_no']) ?></td>
                            <td><?= e($customerJob['brand'] . ' ' . $customerJob['model']) ?></td>
                            <td><?= badge($customerJob['status_name']) ?></td>
                            <td>
                                <a
                                    class="btn btn-sm btn-ghost"
                                    href="track.php?ref=<?= urlencode($customerJob['reference_no']) ?>"
                                >View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
