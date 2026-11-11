<?php
require __DIR__ . '/config.php';

$title = 'Login';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $identity = trim((string) ($_POST['identity'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $attemptKey = login_attempt_key($identity);

    try {
        if (login_is_rate_limited($attemptKey)) {
            $error = 'Incorrect username, email, phone number or password.';
        } else {
            $statement = db()->prepare(
                'SELECT DISTINCT u.*
                 FROM users u
                 LEFT JOIN customers c ON c.user_id=u.user_id
                 WHERE (u.username=? OR u.email=? OR c.contact_number=?)
                   AND u.is_active=1'
            );
            $statement->execute([$identity, $identity, $identity]);
            $accounts = $statement->fetchAll();
            $account = count($accounts) === 1 ? $accounts[0] : false;

            if ($account && password_verify($password, $account['password_hash'])) {
                clear_login_attempts($attemptKey);
                session_regenerate_id(true);

                $_SESSION['user'] = [
                    'user_id' => $account['user_id'],
                    'full_name' => $account['full_name'],
                    'role' => $account['role'],
                ];

                $destination = in_array($account['role'], ['admin', 'staff', 'technician'], true)
                    ? 'admin/index.php'
                    : 'track.php';
                go($destination);
            }

            record_login_attempt($attemptKey);
            $error = 'Incorrect username, email, phone number or password.';
        }
    } catch (Throwable $exception) {
        log_system_error('Login query failed', ['error' => $exception->getMessage()]);
        $error = 'We could not sign you in right now. Please try again in a moment.';
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="narrow small">
    <h1>Log in</h1>

    <?php if ($error): ?>
        <div class="flash err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="card form">
        <?= csrf_field() ?>

        <label>Email, username or phone number
            <input
                name="identity"
                required
                autofocus
                autocomplete="username"
                placeholder="you@example.com, admin or 09XX XXX XXXX"
            >
        </label>

        <label>Password
            <input
                type="password"
                name="password"
                required
                autocomplete="current-password"
            >
        </label>

        <a href="forgot.php" class="center">Forgot your password?</a>
        <button class="btn btn-primary btn-lg">Log in</button>

        <p class="center">New here? <a href="register.php">Create an account</a></p>
        <a class="btn btn-ghost" href="index.php">Continue as guest</a>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
