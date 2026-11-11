<?php
require __DIR__ . '/config.php';

$title = 'Create account';
$errors = [];
$formValues = [
    'name' => '',
    'email' => '',
    'contact' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach ($formValues as $field => $value) {
        $formValues[$field] = trim($_POST[$field] ?? '');
    }

    $password = $_POST['password'] ?? '';

    if ($formValues['name'] === '') {
        $errors[] = 'Enter your full name.';
    }

    if (!filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $formValues['contact'])) {
        $errors[] = 'Enter a valid contact number.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Use a password with at least 8 characters.';
    }

    if ($password !== ($_POST['confirm'] ?? '')) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO users (username,email,password_hash,full_name,role)
                 VALUES (?,?,?,?, 'customer')"
            )->execute([
                $formValues['email'],
                $formValues['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $formValues['name'],
            ]);

            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO customers (user_id,full_name,contact_number,email)
                 VALUES (?,?,?,?)'
            )->execute([
                $userId,
                $formValues['name'],
                $formValues['contact'],
                $formValues['email'],
            ]);

            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'We could not create the account. That email may already be registered.';
        }

        if (!$errors) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'user_id' => $userId,
                'full_name' => $formValues['name'],
                'role' => 'customer',
            ];

            flash('Account created. To protect customer privacy, existing repair records are not linked automatically.');
            go('track.php');
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="narrow small">
    <h1>Create an account</h1>

    <?php foreach ($errors as $error): ?>
        <div class="flash err"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" class="card form">
        <?= csrf_field() ?>

        <label>Full name
            <input name="name" value="<?= e($formValues['name']) ?>" required>
        </label>
        <label>Email
            <input type="email" name="email" value="<?= e($formValues['email']) ?>" required>
        </label>
        <label>Contact number
            <input
                name="contact"
                value="<?= e($formValues['contact']) ?>"
                required
                inputmode="tel"
            >
        </label>
        <label>Password (8+ characters)
            <input
                type="password"
                name="password"
                required
                minlength="8"
                autocomplete="new-password"
            >
        </label>
        <label>Confirm password
            <input
                type="password"
                name="confirm"
                required
                minlength="8"
                autocomplete="new-password"
            >
        </label>

        <button class="btn btn-primary btn-lg">Create account</button>
        <p class="center">Already registered? <a href="login.php">Log in</a></p>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
