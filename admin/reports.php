<?php
require __DIR__ . '/../config.php';
require_admin();

$area = 'admin';
$title = 'Reports';
$pdo = db();

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');

$statement = $pdo->prepare(
    'SELECT s.status_name,COUNT(*) AS total
     FROM job_orders j
     JOIN repair_statuses s ON s.status_id=j.current_status_id
     WHERE DATE(j.created_at) BETWEEN ? AND ?
     GROUP BY s.status_id
     ORDER BY s.sort_order'
);
$statement->execute([$fromDate, $toDate]);
$jobsByStatus = $statement->fetchAll();

$statement = $pdo->prepare(
    "SELECT COALESCE(u.full_name,'Unassigned') AS name,COUNT(*) AS total
     FROM job_orders j
     LEFT JOIN technicians t ON t.technician_id=j.assigned_tech_id
     LEFT JOIN users u ON u.user_id=t.user_id
     WHERE DATE(j.created_at) BETWEEN ? AND ?
     GROUP BY name"
);
$statement->execute([$fromDate, $toDate]);
$jobsByTechnician = $statement->fetchAll();

$statement = $pdo->prepare(
    "SELECT COALESCE(SUM(j.estimated_cost),0)
     FROM job_orders j
     JOIN repair_statuses s ON s.status_id=j.current_status_id
     WHERE s.status_name='completed'
       AND DATE(j.created_at) BETWEEN ? AND ?"
);
$statement->execute([$fromDate, $toDate]);
$completedRepairValue = $statement->fetchColumn();
$jobCount = array_sum(array_column($jobsByStatus, 'total'));

require __DIR__ . '/../includes/header.php';
?>

<div class="rowhead">
    <h1>Reports</h1>
    <button class="btn btn-ghost" onclick="window.print()">Print report</button>
</div>

<form class="filters card" method="get">
    <label>From
        <input type="date" name="from" value="<?= e($fromDate) ?>">
    </label>
    <label>To
        <input type="date" name="to" value="<?= e($toDate) ?>">
    </label>
    <button class="btn btn-primary">Update report</button>
</form>

<div class="stats">
    <a>
        <b>PHP <?= number_format($completedRepairValue, 2) ?></b>
        Estimated value of completed repairs
    </a>
    <a><b><?= $jobCount ?></b>Job orders in range</a>
</div>

<div class="grid2">
    <div class="card">
        <h3>Jobs by status</h3>
        <table class="tbl">
            <tbody>
                <?php foreach ($jobsByStatus as $row): ?>
                    <tr>
                        <td><?= badge($row['status_name']) ?></td>
                        <td class="num"><?= $row['total'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Jobs by technician</h3>
        <table class="tbl">
            <tbody>
                <?php foreach ($jobsByTechnician as $row): ?>
                    <tr>
                        <td><?= e($row['name']) ?></td>
                        <td class="num"><?= $row['total'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
