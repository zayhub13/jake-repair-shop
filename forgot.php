<?php
require __DIR__ . '/config.php';

$title = 'Forgot password';
$isComplete = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $identity = trim($_POST['identity'] ?? '');
    $attemptKey = login_attempt_key('password-reset:' . $identity);

    if (!login_is_rate_limited($attemptKey)) {
        record_login_attempt($attemptKey);
        $statement = db()->prepare(
            'SELECT * FROM users WHERE (username=? OR email=?) AND is_active=1'
        );
        $statement->execute([$identity, $identity]);
        $account = $statement->fetch();
    } else {
        $account = false;
    }

    if ($account && APP_PUBLIC_URL !== '') {
        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);

        db()->prepare(
            'INSERT INTO password_resets (user_id,token_hash,expires_at)
             VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([$account['user_id'], $tokenHash]);

        $resetLink = APP_PUBLIC_URL . BASE_URL . 'reset.php?token=' . $token;

        $mailSent = mail(
            $account['email'],
            SHOP_NAME . ' password reset',
            "Use this link within 1 hour to set a new password:\n"
                . $resetLink
                . "\n\nIf you did not ask for this, ignore this email."
        );
        if (!$mailSent) {
            log_system_error('Password reset email could not be sent', [
                'user_id' => $account['user_id'],
            ]);
        }

    } elseif ($account) {
        log_system_error('Password reset URL is not configured', [
            'user_id' => $account['user_id'],
        ]);
    }

    // Use the same response whether or not the account exists.
    $isComplete = true;
}

require __DIR__ . '/includes/header.php';
?>

<div class="narrow small">
    <h1>Forgot your password?</h1>

    <?php if ($isComplete): ?>
        <div class="flash ok">
            If that account exists and password recovery is configured, a reset link will be sent.
            The link works for 1 hour.
        </div>

        <p class="center"><a href="login.php">Back to log in</a></p>
    <?php else: ?>
        <form method="post" class="card form">
            <?= csrf_field() ?>
            <p class="muted">
                Enter your email or username and we will send you a link to set a new password.
            </p>
            <label>Email or username
                <input name="identity" required autofocus>
            </label>
            <button class="btn btn-primary btn-lg">Send reset link</button>
            <p class="center"><a href="login.php">Back to log in</a></p>
        </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
