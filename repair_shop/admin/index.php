<?php
require __DIR__ . '/../config.php';
require_staff();

$area = 'admin';
$title = 'Dashboard';
[$technicianCondition, $technicianParameters] = tech_scope();
$pdo = db();

$countJobs = function ($condition, $extraParameters = []) use (
    $pdo,
    $technicianCondition,
    $technicianParameters
) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM job_orders j
         JOIN repair_statuses s ON s.status_id=j.current_status_id
         WHERE ' . $condition . $technicianCondition
    );
    $statement->execute(array_merge($extraParameters, $technicianParameters));

    return $statement->fetchColumn();
};

$totalJobs = $countJobs('1=1');
$pendingJobs = $countJobs("s.status_name='received'");
$inProgressJobs = $countJobs(
    "s.status_name IN ('diagnosing','awaiting_approval','awaiting_parts','in_repair')"
);
$readyJobs = $countJobs("s.status_name='ready_for_pickup'");

$statement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM status_history h
     JOIN repair_statuses s ON s.status_id=h.status_id
     JOIN job_orders j ON j.job_order_id=h.job_order_id
     WHERE s.status_name='completed'
       AND DATE(h.changed_at)=CURDATE()
       $technicianCondition"
);
$statement->execute($technicianParameters);
$completedToday = $statement->fetchColumn();

$statement = $pdo->prepare(
    'SELECT j.job_order_id,j.reference_no,j.created_at,
        c.full_name,d.brand,d.model,s.status_name
     FROM job_orders j
     JOIN customers c ON c.customer_id=j.customer_id
     JOIN devices d ON d.device_id=j.device_id
     JOIN repair_statuses s ON s.status_id=j.current_status_id
     WHERE 1=1 ' . $technicianCondition . '
     ORDER BY j.created_at DESC
     LIMIT 8'
);
$statement->execute($technicianParameters);
$recentJobs = $statement->fetchAll();
$receivedStatusId = $pdo->query(
    "SELECT status_id FROM repair_statuses WHERE status_name='received'"
)->fetchColumn();

require __DIR__ . '/../includes/header.php';
?>

<div class="rowhead">
    <h1>Dashboard</h1>
    <a class="btn btn-accent" href="job.php?new=1">Create new service request</a>
</div>

<div class="stats">
    <a href="jobs.php"><b><?= $totalJobs ?></b>Total service requests</a>
    <a href="jobs.php?status=<?= (int) $receivedStatusId ?>">
        <b><?= $pendingJobs ?></b>Pending (new)
    </a>
    <a href="jobs.php"><b><?= $inProgressJobs ?></b>In progress</a>
    <a href="jobs.php"><b><?= $readyJobs ?></b>Ready for pickup</a>
    <a href="jobs.php"><b><?= $completedToday ?></b>Completed today</a>
</div>

<h2>Recent service requests</h2>

<div class="card scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th>Job #</th>
                <th>Customer</th>
                <th>Device</th>
                <th>Status</th>
                <th>Date</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentJobs as $job): ?>
                <tr>
                    <td><?= e($job['reference_no']) ?></td>
                    <td><?= e($job['full_name']) ?></td>
                    <td><?= e($job['brand'] . ' ' . $job['model']) ?></td>
                    <td><?= badge($job['status_name']) ?></td>
                    <td><?= date('M j, Y', strtotime($job['created_at'])) ?></td>
                    <td>
                        <a
                            class="btn btn-sm btn-ghost"
                            href="job.php?id=<?= $job['job_order_id'] ?>"
                        >Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentJobs): ?>
                <tr>
                    <td colspan="6" class="empty">
                        No service requests yet. New requests from the website will show up here.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
