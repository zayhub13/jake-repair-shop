<?php
require __DIR__ . '/../config.php';
require_staff();

$area = 'admin';
$title = 'Customers & Devices';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_admin();

    $customerId = (int) ($_POST['id'] ?? 0);
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM job_orders WHERE customer_id=?'
    );
    $statement->execute([$customerId]);

    if ($statement->fetchColumn() > 0) {
        flash('This customer has repair history, so the record is kept.', 'err');
    } else {
        $pdo->prepare('DELETE FROM devices WHERE customer_id=?')
            ->execute([$customerId]);
        $pdo->prepare('DELETE FROM customers WHERE customer_id=?')
            ->execute([$customerId]);

        audit('delete', 'customers', $customerId);
        flash('Customer deleted.');
    }

    go('admin/customers.php');
}

$search = trim($_GET['q'] ?? '');
$searchPattern = "%$search%";
$statement = $pdo->prepare(
    'SELECT c.*,
        (SELECT COUNT(*) FROM job_orders j WHERE j.customer_id=c.customer_id) AS repairs
     FROM customers c
     WHERE c.full_name LIKE ?
        OR c.contact_number LIKE ?
        OR c.email LIKE ?
     ORDER BY c.created_at DESC
     LIMIT 200'
);
$statement->execute(array_fill(0, 3, $searchPattern));
$customers = $statement->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Customers and devices</h1>

<form class="filters card" method="get">
    <input
        name="q"
        value="<?= e($search) ?>"
        placeholder="Search name, phone or email"
    >
    <button class="btn btn-primary">Search</button>
    <a class="btn btn-ghost" href="customers.php">Clear</a>
</form>

<div class="card scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Email</th>
                <th># Repairs</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $customer): ?>
                <tr>
                    <td><?= e($customer['full_name']) ?></td>
                    <td><?= e($customer['contact_number']) ?></td>
                    <td><?= e($customer['email']) ?></td>
                    <td><?= $customer['repairs'] ?></td>
                    <td class="acts">
                        <a
                            class="btn btn-sm btn-ghost"
                            href="customer.php?id=<?= $customer['customer_id'] ?>"
                        >View</a>

                        <?php if (user()['role'] === 'admin' && !$customer['repairs']): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= $customer['customer_id'] ?>"
                                >
                                <button
                                    class="btn btn-sm btn-danger"
                                    data-confirm="Delete this customer and their devices?"
                                >Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$customers): ?>
                <tr>
                    <td colspan="5" class="empty">No customers found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
