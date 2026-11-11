<?php
require __DIR__ . '/../config.php';
require_staff();

$area = 'admin';
$title = 'Parts Inventory';
$pdo = db();
$isAdmin = user()['role'] === 'admin';
$threshold = (int) get_setting('inventory_low_stock_threshold', 5);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$isAdmin) {
        flash('Only admins can modify inventory.', 'err');
        go('admin/inventory.php');
    }

    $action = $_POST['action'] ?? '';
    $partId = (int) ($_POST['id'] ?? 0);

    if ($action === 'threshold') {
        $threshold = max(0, (int) ($_POST['threshold'] ?? $threshold));
        set_setting('inventory_low_stock_threshold', $threshold);
        flash('Low-stock threshold saved.');
        go('admin/inventory.php');
    }

    if ($action === 'add') {
        $partNumber = trim((string) ($_POST['number'] ?? ''));
        $partName = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $unitCost = (float) ($_POST['cost'] ?? 0);
        $stockQty = (int) ($_POST['qty'] ?? 0);

        if ($partNumber === '' || $partName === '' || $unitCost < 0 || $stockQty < 0) {
            flash('Enter a valid part number, name, cost and stock quantity.', 'err');
            go('admin/inventory.php');
        }

        try {
            $pdo->prepare(
                'INSERT INTO parts (part_number,part_name,description,unit_cost,stock_qty)
                 VALUES (?,?,?,?,?)'
            )->execute([$partNumber, $partName, $description !== '' ? $description : null, $unitCost, $stockQty]);
            audit('create', 'parts', $pdo->lastInsertId());
            flash('Part added.');
        } catch (PDOException $exception) {
            flash('That part number already exists.', 'err');
        }
        go('admin/inventory.php');
    }

    if ($action === 'edit') {
        $partNumber = trim((string) ($_POST['number'] ?? ''));
        $partName = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $unitCost = (float) ($_POST['cost'] ?? 0);

        if ($partNumber === '' || $partName === '' || $unitCost < 0) {
            flash('Enter a valid part number, name and cost.', 'err');
            go('admin/inventory.php');
        }

        try {
            $pdo->prepare(
                'UPDATE parts SET part_number=?,part_name=?,description=?,unit_cost=? WHERE part_id=?'
            )->execute([$partNumber, $partName, $description !== '' ? $description : null, $unitCost, $partId]);
            audit('update', 'parts', $partId);
            flash('Part updated.');
        } catch (PDOException $exception) {
            flash('That part number already exists.', 'err');
        }
        go('admin/inventory.php');
    }

    if ($action === 'delete') {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM job_order_parts WHERE part_id=?');
        $statement->execute([$partId]);

        if ($statement->fetchColumn() > 0) {
            flash('This part was used on a service request, so it is kept.', 'err');
        } else {
            $pdo->prepare('DELETE FROM parts WHERE part_id=?')->execute([$partId]);
            audit('delete', 'parts', $partId);
            flash('Part deleted.');
        }
        go('admin/inventory.php');
    }

    if ($action === 'receive') {
        $quantity = (int) ($_POST['qty'] ?? 0);
        $unitCost = (float) ($_POST['unit_cost'] ?? 0);
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $note = trim((string) ($_POST['notes'] ?? ''));

        if ($quantity <= 0 || $unitCost < 0) {
            flash('Received quantity must be a positive whole number.', 'err');
            go('admin/inventory.php');
        }

        $statement = $pdo->prepare('SELECT stock_qty, unit_cost FROM parts WHERE part_id=?');
        $statement->execute([$partId]);
        $part = $statement->fetch();

        if (!$part) {
            flash('Choose a valid part.', 'err');
            go('admin/inventory.php');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE parts SET stock_qty=stock_qty + ?, unit_cost = ? WHERE part_id=?'
            )->execute([$quantity, $unitCost, $partId]);

            $pdo->prepare(
                'INSERT INTO stock_movements (part_id, movement_type, quantity, unit_cost, supplier_id, admin_user_id, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $partId,
                'receipt',
                $quantity,
                $unitCost,
                $supplierId ?: null,
                user()['user_id'],
                $note !== '' ? $note : null,
            ]);

            audit('receive_stock', 'parts', $partId);
            $pdo->commit();
            flash('Stock received and recorded.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        go('admin/inventory.php');
    }
}

$parts = $pdo->query(
    'SELECT p.*, COALESCE((SELECT SUM(quantity) FROM stock_movements sm WHERE sm.part_id=p.part_id AND sm.movement_type IN (\'receipt\',\'usage\')),0) AS movement_total
     FROM parts p ORDER BY p.part_name'
)->fetchAll();
$editPart = null;

if (isset($_GET['edit']) && $isAdmin) {
    $requestedPartId = (int) $_GET['edit'];
    foreach ($parts as $part) {
        if ((int) $part['part_id'] === $requestedPartId) {
            $editPart = $part;
            break;
        }
    }
}

$suppliers = $pdo->query('SELECT supplier_id,supplier_name FROM suppliers ORDER BY supplier_name')->fetchAll();
$movementHistory = $pdo->query(
    'SELECT sm.*, s.supplier_name, u.full_name AS admin_name
     FROM stock_movements sm
     LEFT JOIN suppliers s ON s.supplier_id = sm.supplier_id
     LEFT JOIN users u ON u.user_id = sm.admin_user_id
     ORDER BY sm.created_at DESC
     LIMIT 20'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Parts inventory</h1>

<?php if ($isAdmin): ?>
    <form method="post" class="filters card" aria-label="Low stock threshold">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="threshold">
        <label>Low-stock threshold
            <input type="number" min="0" name="threshold" value="<?= (int) $threshold ?>" required>
        </label>
        <button class="btn btn-primary">Save threshold</button>
    </form>
<?php endif; ?>

<?php if ($editPart): ?>
    <form method="post" class="card form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= $editPart['part_id'] ?>">
        <h3>Edit part</h3>
        <div class="two">
            <label>Part number
                <input name="number" value="<?= e($editPart['part_number']) ?>" required>
            </label>
            <label>Part name
                <input name="name" value="<?= e($editPart['part_name']) ?>" required>
            </label>
        </div>
        <label>Description
            <textarea name="description" rows="2"><?= e($editPart['description'] ?? '') ?></textarea>
        </label>
        <label>Unit cost (PHP)
            <input type="number" step="0.01" min="0" name="cost" value="<?= e($editPart['unit_cost']) ?>" required>
        </label>
        <div class="actions">
            <button class="btn btn-primary">Save part</button>
            <a class="btn btn-ghost" href="inventory.php">Cancel</a>
        </div>
    </form>
<?php endif; ?>

<?php if ($isAdmin): ?>
    <form method="post" class="card form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <h3>Add part</h3>
        <div class="two">
            <label>Part number
                <input name="number" placeholder="e.g. LCD-123" required>
            </label>
            <label>Part name
                <input name="name" placeholder="Display assembly" required>
            </label>
        </div>
        <label>Description
            <textarea name="description" rows="2" placeholder="Optional description"></textarea>
        </label>
        <div class="two">
            <label>Unit cost (PHP)
                <input type="number" step="0.01" min="0" name="cost" placeholder="0.00" required>
            </label>
            <label>Starting stock
                <input type="number" min="0" name="qty" placeholder="0" required>
            </label>
        </div>
        <button class="btn btn-primary">Add part</button>
    </form>

    <form method="post" class="card form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="receive">
        <h3>Receive stock</h3>
        <div class="two">
            <label>Part
                <select name="id" required>
                    <option value="">Choose a part</option>
                    <?php foreach ($parts as $part): ?>
                        <option value="<?= $part['part_id'] ?>"><?= e($part['part_name']) ?> (<?= e($part['part_number']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Supplier
                <select name="supplier_id">
                    <option value="0">No supplier recorded</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= $supplier['supplier_id'] ?>"><?= e($supplier['supplier_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="two">
            <label>Quantity received
                <input type="number" min="1" step="1" name="qty" required>
            </label>
            <label>Unit cost (PHP)
                <input type="number" min="0" step="0.01" name="unit_cost" value="0.00" required>
            </label>
        </div>
        <label>Notes
            <input name="notes" maxlength="255" placeholder="Optional receiving notes">
        </label>
        <button class="btn btn-primary">Record receipt</button>
    </form>
<?php endif; ?>

<div class="card scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th>Part #</th>
                <th>Name</th>
                <th>Description</th>
                <th>Unit cost</th>
                <th>In stock</th>
                <th>Low stock</th>
                <?php if ($isAdmin): ?><th>Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($parts as $part): ?>
                <?php $lowStock = (int) $part['stock_qty'] <= (int) $threshold; ?>
                <tr>
                    <td><?= e($part['part_number']) ?></td>
                    <td><?= e($part['part_name']) ?></td>
                    <td><?= e($part['description'] ?? '') ?: '—' ?></td>
                    <td>PHP <?= number_format((float) $part['unit_cost'], 2) ?></td>
                    <td><?= (int) $part['stock_qty'] ?></td>
                    <td>
                        <?php if ($lowStock): ?>
                            <span class="badge b-cancelled">Low stock</span>
                        <?php else: ?>
                            <span class="muted">Good</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($isAdmin): ?>
                        <td>
                            <div class="acts">
                                <a class="btn btn-sm btn-ghost" href="inventory.php?edit=<?= $part['part_id'] ?>">Edit</a>
                                <form method="post" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $part['part_id'] ?>">
                                    <button class="btn btn-sm btn-danger" data-confirm="Delete this part?">Delete</button>
                                </form>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$parts): ?>
                <tr>
                    <td colspan="<?= $isAdmin ? 7 : 6 ?>" class="empty">No parts yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($isAdmin && $movementHistory): ?>
    <div class="card">
        <h3>Recent stock movement history</h3>
        <table class="tbl">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Part</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Supplier</th>
                    <th>Admin</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movementHistory as $movement): ?>
                    <tr>
                        <td><?= date('M j, Y g:i A', strtotime($movement['created_at'])) ?></td>
                        <td><?= e($movement['part_id']) ?></td>
                        <td><?= e($movement['movement_type']) ?></td>
                        <td><?= (int) $movement['quantity'] ?></td>
                        <td><?= e($movement['supplier_name'] ?: '—') ?></td>
                        <td><?= e($movement['admin_name'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
