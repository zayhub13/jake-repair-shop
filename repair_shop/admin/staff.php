<?php
require __DIR__ . '/../config.php';
require_admin();

$area = 'admin';
$title = 'Staff';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $userId = (int) ($_POST['id'] ?? 0);
        $currentUserId = user()['user_id'];
        $pdo->prepare(
            'UPDATE users
             SET is_active=1-is_active
             WHERE user_id=? AND user_id<>?'
        )->execute([$userId, $currentUserId]);
        flash('Account updated.');
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = trim($_POST['name'] ?? '');

        if (
            strlen($password) < 8
            || $username === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            flash(
                'Enter a username, a valid email and a password of 8+ characters.',
                'err'
            );
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare(
                    "INSERT INTO users (username,email,password_hash,full_name,role)
                     VALUES (?,?,?,?, 'technician')"
                )->execute([
                    $username,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $fullName,
                ]);

                $userId = $pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO technicians (user_id,specialization,contact_number)
                     VALUES (?,?,?)'
                )->execute([
                    $userId,
                    trim($_POST['spec'] ?? ''),
                    trim($_POST['contact'] ?? ''),
                ]);

                $pdo->commit();

                audit('create', 'users', 0);
                flash('Technician account created.');
            } catch (PDOException $exception) {
                $pdo->rollBack();
                flash('That username or email is already taken.', 'err');
            }
        }
    }

    go('admin/staff.php');
}

$staffMembers = $pdo->query(
    "SELECT u.*,t.specialization
     FROM users u
     LEFT JOIN technicians t ON t.user_id=u.user_id
     WHERE u.role IN ('admin','technician')
     ORDER BY u.role,u.full_name"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Staff accounts</h1>

<form method="post" class="card form">
    <?= csrf_field() ?>
    <h3>Add a technician</h3>

    <div class="two">
        <label>Full name
            <input name="name" required>
        </label>
        <label>Specialization
            <input name="spec" placeholder="Screens, board repair...">
        </label>
    </div>
    <div class="two">
        <label>Username
            <input name="username" required>
        </label>
        <label>Email
            <input type="email" name="email" required>
        </label>
    </div>
    <div class="two">
        <label>Contact number
            <input name="contact">
        </label>
        <label>Temporary password
            <input type="password" name="password" minlength="8" required>
        </label>
    </div>
    <button class="btn btn-primary">Create technician</button>
</form>

<div class="card scroll">
    <table class="tbl">
        <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Username</th>
                <th>Specialization</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($staffMembers as $staffMember): ?>
                <tr>
                    <td><?= e($staffMember['full_name']) ?></td>
                    <td><?= e($staffMember['role']) ?></td>
                    <td><?= e($staffMember['username']) ?></td>
                    <td><?= e($staffMember['specialization']) ?></td>
                    <td><?= $staffMember['is_active'] ? 'Active' : 'Disabled' ?></td>
                    <td>
                        <?php if ($staffMember['user_id'] != user()['user_id']): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= $staffMember['user_id'] ?>"
                                >
                                <button class="btn btn-sm btn-ghost">
                                    <?= $staffMember['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
