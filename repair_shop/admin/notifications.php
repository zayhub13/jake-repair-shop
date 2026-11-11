<?php
require __DIR__ . '/../config.php';
require_staff();

$area = 'admin';
$title = 'Notifications';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'milestone') {
        require_admin();

        $statusId = (int) ($_POST['id'] ?? 0);
        $isMilestone = isset($_POST['on']) ? 1 : 0;
        $pdo->prepare(
            'UPDATE repair_statuses SET is_milestone=? WHERE status_id=?'
        )->execute([$isMilestone, $statusId]);
        flash('Setting saved.');
    }

    go('admin/notifications.php' . ($action === 'milestone' ? '#settings' : ''));
}

$notifications = $pdo->query(
    'SELECT n.*,c.full_name,j.reference_no
     FROM notifications n
     JOIN customers c ON c.customer_id=n.customer_id
     JOIN job_orders j ON j.job_order_id=n.job_order_id
     ORDER BY n.sent_at DESC,n.notification_id DESC
     LIMIT 100'
)->fetchAll();
$repairStatuses = statuses();

require __DIR__ . '/../includes/header.php';
?>

<h1>Notifications</h1>
<div class="note">
    SMS and email notifications remain pending until an external delivery provider and worker are configured.
    This page cannot mark a message as sent without delivery confirmation.
</div>

<div class="card scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Job #</th>
                <th>Message</th>
                <th>Channel</th>
                <th>Status</th>
                <th>Time</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($notifications as $notification): ?>
                <tr>
                    <td><?= e($notification['full_name']) ?></td>
                    <td><?= e($notification['reference_no']) ?></td>
                    <td class="msg">
                        <?= e($notification['message']) ?><br>
                        <small class="muted">
                            <?= $notification['trigger_type'] === 'manual' ? 'Manual' : 'Automated' ?>
                        </small>
                    </td>
                    <td><?= e($notification['channel'] === 'in_app' ? 'WEBSITE' : strtoupper($notification['channel'])) ?></td>
                    <td>
                        <span class="badge b-<?= $notification['delivery_status'] === 'sent' ? 'completed' : 'received' ?>">
                            <?= e($notification['delivery_status']) ?>
                        </span>
                    </td>
                    <td>
                        <?= $notification['sent_at']
                            ? date('M j, g:i A', strtotime($notification['sent_at']))
                            : 'Not sent' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$notifications): ?>
                <tr>
                    <td colspan="6" class="empty">No notifications yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<h2 id="settings">Settings: automatic messages</h2>
<div class="card">
    <p class="muted">
        Switch on the statuses that should message the customer automatically.
        Customers must have agreed to updates.
    </p>

    <?php foreach ($repairStatuses as $status): ?>
        <form method="post" class="toggle">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="milestone">
            <input type="hidden" name="id" value="<?= $status['status_id'] ?>">
            <label>
                <input
                    type="checkbox"
                    name="on"
                    <?= $status['is_milestone'] ? 'checked' : '' ?>
                    data-autosubmit
                    <?= user()['role'] !== 'admin' ? 'disabled' : '' ?>
                >
                <i></i><?= e(pretty_status($status['status_name'])) ?>
            </label>
        </form>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
