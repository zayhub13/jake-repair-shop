<?php
require __DIR__ . '/config.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$title = 'Set new password';
$errors = [];
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$tokenHash = hash('sha256', $token);

$statement = db()->prepare(
    'SELECT *
     FROM password_resets
     WHERE token_hash=?
       AND used_at IS NULL
       AND expires_at>NOW()'
);
$statement->execute([$tokenHash]);
$reset = $statement->fetch();

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $password = $_POST['password'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = 'Use a password with at least 8 characters.';
    }

    if ($password !== ($_POST['confirm'] ?? '')) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        $pdo->prepare(
            'UPDATE users SET password_hash=? WHERE user_id=?'
        )->execute([
            password_hash($password, PASSWORD_DEFAULT),
            $reset['user_id'],
        ]);

        $pdo->prepare(
            'UPDATE password_resets SET used_at=NOW() WHERE reset_id=?'
        )->execute([$reset['reset_id']]);

        $pdo->commit();

        flash('Password changed. You can log in now.');
        go('login.php');
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="narrow small">
    <h1>Set a new password</h1>

    <?php if (!$reset): ?>
        <div class="flash err">This reset link is invalid or has expired.</div>
        <p class="center">
            <a class="btn btn-primary" href="forgot.php">Request a new link</a>
        </p>
    <?php else: ?>
        <?php foreach ($errors as $error): ?>
            <div class="flash err"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" class="card form">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <label>New password (8+ characters)
                <input
                    type="password"
                    name="password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    autofocus
                >
            </label>
            <label>Confirm new password
                <input
                    type="password"
                    name="confirm"
                    required
                    minlength="8"
                    autocomplete="new-password"
                >
            </label>

            <button class="btn btn-primary btn-lg">Save password</button>
        </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
