<?php
require __DIR__ . '/../config.php';
require_admin();

$area = 'admin';
$title = 'Audit Log';
$pdo = db();

$action = trim($_GET['action'] ?? '');
$conditions = ['1=1'];
$parameters = [];

if ($action !== '') {
    $conditions[] = 'a.action=?';
    $parameters[] = $action;
}

$sql = 'SELECT a.*, u.full_name
        FROM audit_logs a
        LEFT JOIN users u ON u.user_id=a.admin_id
        WHERE ' . implode(' AND ', $conditions) . '
        ORDER BY a.created_at DESC, a.log_id DESC
        LIMIT 200';
$statement = $pdo->prepare($sql);
$statement->execute($parameters);
$rows = $statement->fetchAll();
$actions = $pdo->query(
    'SELECT DISTINCT action FROM audit_logs ORDER BY action'
)->fetchAll(PDO::FETCH_COLUMN);

require __DIR__ . '/../includes/header.php';
?>

<h1>Audit log</h1>

<form class="filters card" method="get">
    <select name="action">
        <option value="">All actions</option>
        <?php foreach ($actions as $availableAction): ?>
            <option <?= $action === $availableAction ? 'selected' : '' ?>>
                <?= e($availableAction) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <a class="btn btn-ghost" href="audit.php">Clear</a>
</form>

<div class="card scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th>When</th>
                <th>Who</th>
                <th>Action</th>
                <th>Record</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= date('M j, Y g:i A', strtotime($row['created_at'])) ?></td>
                    <td><?= e($row['full_name'] ?: 'System') ?></td>
                    <td><?= e($row['action']) ?></td>
                    <td>
                        <?= e($row['target_table']) ?>
                        <?= $row['target_id'] ? ' #' . (int) $row['target_id'] : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="4" class="empty">Nothing logged yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
