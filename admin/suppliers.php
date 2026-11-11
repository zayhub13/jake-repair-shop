<?php
require __DIR__ . '/../config.php';
require_admin();

$area = 'admin';
$title = 'Suppliers';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';
    $supplierId = (int) ($_POST['id'] ?? 0);
    $value = static function ($key) {
        return trim($_POST[$key] ?? '');
    };

    if ($action === 'delete') {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM devices WHERE supplier_id=?'
        );
        $statement->execute([$supplierId]);

        if ($statement->fetchColumn() > 0) {
            flash('This supplier is linked to devices, so it is kept.', 'err');
        } else {
            $pdo->prepare('DELETE FROM suppliers WHERE supplier_id=?')
                ->execute([$supplierId]);
            audit('delete', 'suppliers', $supplierId);
            flash('Supplier deleted.');
        }
    } elseif ($value('name') === '') {
        flash('Enter the supplier name.', 'err');
    } elseif ($action === 'update') {
        $pdo->prepare(
            'UPDATE suppliers
             SET supplier_name=?,contact_person=?,contact_number=?,email=?,address=?
             WHERE supplier_id=?'
        )->execute([
            $value('name'),
            $value('person') ?: null,
            $value('contact') ?: null,
            $value('email') ?: null,
            $value('address') ?: null,
            $supplierId,
        ]);

        audit('update', 'suppliers', $supplierId);
        flash('Supplier updated.');
    } else {
        $pdo->prepare(
            'INSERT INTO suppliers
                (supplier_name,contact_person,contact_number,email,address)
             VALUES (?,?,?,?,?)'
        )->execute([
            $value('name'),
            $value('person') ?: null,
            $value('contact') ?: null,
            $value('email') ?: null,
            $value('address') ?: null,
        ]);

        audit('create', 'suppliers', (int) $pdo->lastInsertId());
        flash('Supplier added.');
    }

    go('admin/suppliers.php');
}

$editSupplier = null;

if (isset($_GET['edit'])) {
    $statement = $pdo->prepare(
        'SELECT * FROM suppliers WHERE supplier_id=?'
    );
    $statement->execute([(int) $_GET['edit']]);
    $editSupplier = $statement->fetch() ?: null;
}

$suppliers = $pdo->query(
    'SELECT s.*,
        (SELECT COUNT(*) FROM devices d WHERE d.supplier_id=s.supplier_id) AS devices
     FROM suppliers s
     ORDER BY supplier_name'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Suppliers</h1>

<form method="post" class="card form">
    <?= csrf_field() ?>
    <input
        type="hidden"
        name="action"
        value="<?= $editSupplier ? 'update' : 'add' ?>"
    >
    <input
        type="hidden"
        name="id"
        value="<?= (int) ($editSupplier['supplier_id'] ?? 0) ?>"
    >

    <h3><?= $editSupplier ? 'Edit supplier' : 'Add a supplier' ?></h3>

    <div class="two">
        <label>Supplier name *
            <input
                name="name"
                value="<?= e($editSupplier['supplier_name'] ?? '') ?>"
                required
            >
        </label>
        <label>Contact person
            <input
                name="person"
                value="<?= e($editSupplier['contact_person'] ?? '') ?>"
            >
        </label>
    </div>
    <div class="two">
        <label>Contact number
            <input
                name="contact"
                value="<?= e($editSupplier['contact_number'] ?? '') ?>"
                inputmode="tel"
            >
        </label>
        <label>Email
            <input
                type="email"
                name="email"
                value="<?= e($editSupplier['email'] ?? '') ?>"
            >
        </label>
    </div>
    <label>Address
        <input name="address" value="<?= e($editSupplier['address'] ?? '') ?>">
    </label>

    <div class="actions">
        <button class="btn btn-primary">
            <?= $editSupplier ? 'Save changes' : 'Add supplier' ?>
        </button>
        <?php if ($editSupplier): ?>
            <a class="btn btn-ghost" href="suppliers.php">Cancel</a>
        <?php endif; ?>
    </div>
</form>

<div class="card scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th>Supplier</th>
                <th>Contact person</th>
                <th>Number</th>
                <th>Email</th>
                <th>Devices</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($suppliers as $supplier): ?>
                <tr>
                    <td>
                        <?= e($supplier['supplier_name']) ?>
                        <?php if ($supplier['address']): ?>
                            <br><small class="muted"><?= e($supplier['address']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($supplier['contact_person']) ?></td>
                    <td><?= e($supplier['contact_number']) ?></td>
                    <td><?= e($supplier['email']) ?></td>
                    <td><?= $supplier['devices'] ?></td>
                    <td class="acts">
                        <a
                            class="btn btn-sm btn-ghost"
                            href="suppliers.php?edit=<?= $supplier['supplier_id'] ?>"
                        >Edit</a>
                        <?php if (!$supplier['devices']): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= $supplier['supplier_id'] ?>"
                                >
                                <button
                                    class="btn btn-sm btn-danger"
                                    data-confirm="Delete this supplier?"
                                >Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$suppliers): ?>
                <tr>
                    <td colspan="6" class="empty">No suppliers yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
