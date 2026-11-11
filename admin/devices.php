<?php
require __DIR__ . '/../config.php';
require_staff();

$area = 'admin';
$title = 'Devices / History';
$pdo = db();
$search = trim($_GET['q'] ?? '');
$searchPattern = "%$search%";

$statement = $pdo->prepare(
    'SELECT d.*,
        c.full_name,
        c.customer_id AS customer_id,
        sp.supplier_name,
        (SELECT COUNT(*) FROM job_orders j WHERE j.device_id=d.device_id) AS jobs,
        (SELECT j.job_order_id
         FROM job_orders j
         WHERE j.device_id=d.device_id
         ORDER BY j.created_at DESC
         LIMIT 1) AS last_job
     FROM devices d
     JOIN customers c ON c.customer_id=d.customer_id
     LEFT JOIN suppliers sp ON sp.supplier_id=d.supplier_id
     WHERE d.brand LIKE ?
        OR d.model LIKE ?
        OR d.imei_serial LIKE ?
        OR c.full_name LIKE ?
     ORDER BY d.device_id DESC
     LIMIT 200'
);
$statement->execute(array_fill(0, 4, $searchPattern));
$devices = $statement->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Devices and repair history</h1>

<form class="filters card" method="get">
    <input
        name="q"
        value="<?= e($search) ?>"
        placeholder="Search brand, model, IMEI or owner"
    >
    <button class="btn btn-primary">Search</button>
    <a class="btn btn-ghost" href="devices.php">Clear</a>
</form>

<div class="card scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th>Device</th>
                <th>IMEI / serial</th>
                <th>Owner</th>
                <th>Supplier</th>
                <th># Repairs</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($devices as $device): ?>
                <tr>
                    <td><?= e($device['brand'] . ' ' . $device['model']) ?></td>
                    <td><?= e($device['imei_serial']) ?></td>
                    <td>
                        <a href="customer.php?id=<?= $device['customer_id'] ?>">
                            <?= e($device['full_name']) ?>
                        </a>
                    </td>
                    <td><?= e($device['supplier_name']) ?></td>
                    <td><?= $device['jobs'] ?></td>
                    <td class="acts">
                        <?php if ($device['last_job']): ?>
                            <a
                                class="btn btn-sm btn-ghost"
                                href="job.php?id=<?= $device['last_job'] ?>"
                            >Latest job</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$devices): ?>
                <tr>
                    <td colspan="6" class="empty">No devices found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
