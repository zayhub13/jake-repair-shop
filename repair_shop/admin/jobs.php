<?php
require __DIR__ . '/../config.php';
require_staff();

$area = 'admin';
$title = 'Service Requests';

$search = trim($_GET['q'] ?? '');
$statusId = (int) ($_GET['status'] ?? 0);
$technicianId = (int) ($_GET['tech'] ?? 0);
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';

$conditions = ['1=1'];
$parameters = [];

if ($search !== '') {
    $conditions[] = '(j.reference_no LIKE ?
        OR c.full_name LIKE ?
        OR c.contact_number LIKE ?
        OR d.model LIKE ?)';
    $searchPattern = "%$search%";
    $parameters = array_merge($parameters, array_fill(0, 4, $searchPattern));
}

if ($statusId) {
    $conditions[] = 'j.current_status_id=?';
    $parameters[] = $statusId;
}

if ($technicianId) {
    $conditions[] = 'j.assigned_tech_id=?';
    $parameters[] = $technicianId;
}

if ($fromDate) {
    $conditions[] = 'DATE(j.created_at)>=?';
    $parameters[] = $fromDate;
}

if ($toDate) {
    $conditions[] = 'DATE(j.created_at)<=?';
    $parameters[] = $toDate;
}

[$technicianCondition, $technicianParameters] = tech_scope();
$statement = db()->prepare(
    'SELECT j.job_order_id,j.reference_no,j.created_at,
        c.full_name,d.brand,d.model,s.status_name,u.full_name AS tech
     FROM job_orders j
     JOIN customers c ON c.customer_id=j.customer_id
     JOIN devices d ON d.device_id=j.device_id
     JOIN repair_statuses s ON s.status_id=j.current_status_id
     LEFT JOIN technicians t ON t.technician_id=j.assigned_tech_id
     LEFT JOIN users u ON u.user_id=t.user_id
     WHERE ' . implode(' AND ', $conditions) . $technicianCondition . '
     ORDER BY j.created_at DESC
     LIMIT 200'
);
$statement->execute(array_merge($parameters, $technicianParameters));
$jobs = $statement->fetchAll();

$technicians = db()->query(
    'SELECT t.technician_id,u.full_name
     FROM technicians t
     JOIN users u ON u.user_id=t.user_id
     ORDER BY u.full_name'
)->fetchAll();
$repairStatuses = statuses();

require __DIR__ . '/../includes/header.php';
?>

<div class="rowhead">
    <h1>Service requests</h1>
    <a class="btn btn-accent" href="job.php?new=1">Create new service request</a>
</div>

<form class="filters card" method="get">
    <input
        name="q"
        value="<?= e($search) ?>"
        placeholder="Search number, customer, phone, model"
    >

    <select name="status">
        <option value="0">All statuses</option>
        <?php foreach ($repairStatuses as $status): ?>
            <option
                value="<?= $status['status_id'] ?>"
                <?= $statusId == $status['status_id'] ? 'selected' : '' ?>
            ><?= e(pretty_status($status['status_name'])) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="tech">
        <option value="0">All technicians</option>
        <?php foreach ($technicians as $technician): ?>
            <option
                value="<?= $technician['technician_id'] ?>"
                <?= $technicianId == $technician['technician_id'] ? 'selected' : '' ?>
            ><?= e($technician['full_name']) ?></option>
        <?php endforeach; ?>
    </select>

    <input type="date" name="from" value="<?= e($fromDate) ?>" aria-label="From date">
    <input type="date" name="to" value="<?= e($toDate) ?>" aria-label="To date">
    <button class="btn btn-primary">Apply filters</button>
    <a class="btn btn-ghost" href="jobs.php">Clear</a>
</form>

<div class="card scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th>Job #</th>
                <th>Customer</th>
                <th>Device</th>
                <th>Status</th>
                <th>Technician</th>
                <th>Date</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><?= e($job['reference_no']) ?></td>
                    <td><?= e($job['full_name']) ?></td>
                    <td><?= e($job['brand'] . ' ' . $job['model']) ?></td>
                    <td><?= badge($job['status_name']) ?></td>
                    <td><?= e($job['tech'] ?: 'Unassigned') ?></td>
                    <td><?= date('M j, Y', strtotime($job['created_at'])) ?></td>
                    <td>
                        <a
                            class="btn btn-sm btn-ghost"
                            href="job.php?id=<?= $job['job_order_id'] ?>"
                        >Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$jobs): ?>
                <tr>
                    <td colspan="7" class="empty">No service requests match these filters.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
