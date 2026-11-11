<?php
require __DIR__ . '/config.php';

$title = 'Submit Repair Request';
$services = require __DIR__ . '/includes/services.php';
$requestedService = trim($_GET['service'] ?? '');
$selectedService = array_key_exists($requestedService, $services)
    ? $requestedService
    : null;
$currentUser = user();
$customer = null;
$accountIdentity = null;

if ($currentUser && $currentUser['role'] === 'customer') {
    $statement = db()->prepare(
        'SELECT * FROM customers WHERE user_id=?'
    );
    $statement->execute([$currentUser['user_id']]);
    $customer = $statement->fetch();
    if (!$customer) {
        $statement = db()->prepare(
            'SELECT full_name,email FROM users WHERE user_id=?'
        );
        $statement->execute([$currentUser['user_id']]);
        $accountIdentity = $statement->fetch();
    }
}

$formValues = [
    'name' => $customer['full_name'] ?? $accountIdentity['full_name'] ?? '',
    'contact' => $customer['contact_number'] ?? '',
    'email' => $customer['email'] ?? $accountIdentity['email'] ?? '',
    'brand' => '',
    'model' => '',
    'imei' => '',
    'issue' => $selectedService
        ? 'Problem type: ' . $selectedService . '. ' . $services[$selectedService] . '. '
        : '',
    'dropoff' => '',
    'consent' => 0,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach ($formValues as $field => $value) {
        $formValues[$field] = trim($_POST[$field] ?? '');
    }
    $formValues['consent'] = isset($_POST['consent']) ? 1 : 0;

    if ($formValues['name'] === '') {
        $errors[] = 'Enter your full name.';
    }

    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $formValues['contact'])) {
        $errors[] = 'Enter a valid contact number (7 to 20 digits).';
    }

    if (
        $formValues['email'] !== ''
        && !filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'That email address does not look right.';
    }

    if ($formValues['brand'] === '' || $formValues['model'] === '') {
        $errors[] = 'Enter the device brand and model.';
    }

    if (strlen($formValues['issue']) < 10) {
        $errors[] = 'Describe the problem in a few words (at least 10 characters).';
    }

    if (!$errors) {
        try {
            $notes = $formValues['dropoff']
                ? 'Preferred drop-off date: ' . $formValues['dropoff']
                : '';

            $job = create_job([
                'user_id' => $currentUser && $currentUser['role'] === 'customer'
                    ? $currentUser['user_id']
                    : null,
                'name' => $formValues['name'],
                'contact' => $formValues['contact'],
                'email' => $formValues['email'],
                'brand' => $formValues['brand'],
                'model' => $formValues['model'],
                'imei' => $formValues['imei'],
                'issue' => $formValues['issue'],
                'notes' => $notes,
                'consent' => $formValues['consent'],
            ]);

            $photoError = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($photoError !== UPLOAD_ERR_NO_FILE) {
                $photoPath = save_uploaded_customer_image($_FILES['photo']);
                if ($photoPath !== null) {
                    try {
                        db()->prepare(
                            'UPDATE job_orders SET customer_photo_path=? WHERE job_order_id=?'
                        )->execute([$photoPath, $job['id']]);
                    } catch (Throwable $exception) {
                        @unlink(__DIR__ . '/uploads/' . $photoPath);
                        log_system_error('Could not link repair request photo', [
                            'job_id' => $job['id'],
                        ]);
                    }
                } else {
                    log_system_error('Repair request photo rejected or upload failed', [
                        'job_id' => $job['id'],
                        'upload_error' => $photoError,
                    ]);
                }
            }

            go('submit.php?done=' . urlencode($job['ref']));
        } catch (Throwable $exception) {
            $errors[] = 'We could not save your request. Please try again in a moment.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['done'])): ?>
    <div class="card confirm">
        <div class="tick">&#10003;</div>
        <h1>Request received</h1>
        <p>Keep this reference number. You need it to check your repair status.</p>
        <div class="refbox"><?= e($_GET['done']) ?></div>

        <div class="actions">
            <button class="btn btn-ghost" data-copy="<?= e($_GET['done']) ?>">
                Copy number
            </button>
            <a
                class="btn btn-primary"
                href="track.php?ref=<?= urlencode($_GET['done']) ?>"
            >Track this repair</a>
        </div>

        <p class="muted">
            Bring your phone to the shop so our technician can check it.
            If you agreed to updates, we will message you when the status changes.
        </p>
    </div>
<?php else: ?>
    <div class="narrow">
        <h1>Submit a repair request</h1>
        <p class="muted">Takes about two minutes. Fields marked * are required.</p>

        <?php if ($selectedService): ?>
            <div class="service-summary">
                <span>Selected repair type</span>
                <strong><?= e($selectedService) ?></strong>
                <p><?= e($services[$selectedService]) ?>. We added this to your problem description; you can edit it below.</p>
                <a href="index.php#repair-services">Choose a different repair</a>
            </div>
        <?php endif; ?>

        <?php foreach ($errors as $error): ?>
            <div class="flash err"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" enctype="multipart/form-data" class="card form" novalidate>
            <?= csrf_field() ?>

            <label>Full name *
                <input
                    name="name"
                    value="<?= e($formValues['name']) ?>"
                    required
                    autocomplete="name"
                >
            </label>

            <div class="two">
                <label>Contact number *
                    <input
                        name="contact"
                        value="<?= e($formValues['contact']) ?>"
                        required
                        inputmode="tel"
                        placeholder="09XX XXX XXXX"
                    >
                </label>
                <label>Email
                    <input
                        type="email"
                        name="email"
                        value="<?= e($formValues['email']) ?>"
                        placeholder="you@example.com"
                    >
                </label>
            </div>

            <div class="two">
                <label>Device brand *
                    <input
                        name="brand"
                        value="<?= e($formValues['brand']) ?>"
                        required
                        placeholder="e.g. Samsung"
                    >
                </label>
                <label>Device model *
                    <input
                        name="model"
                        value="<?= e($formValues['model']) ?>"
                        required
                        placeholder="e.g. Galaxy A54"
                    >
                </label>
            </div>

            <label>IMEI or serial number (optional)
                <input
                    name="imei"
                    value="<?= e($formValues['imei']) ?>"
                    placeholder="Dial *#06# to see the IMEI"
                >
            </label>

            <label>What is wrong with the phone? *
                <textarea
                    name="issue"
                    rows="4"
                    required
                    placeholder="Example: screen cracked after a drop, touch works only on the left side"
                ><?= e($formValues['issue']) ?></textarea>
            </label>

            <label>Preferred drop-off date
                <select name="dropoff">
                    <option value="">No preference</option>
                    <?php for ($dayOffset = 0, $availableDays = 0; $availableDays < 12; $dayOffset++): ?>
                        <?php
                        $dateTimestamp = strtotime("+$dayOffset day");
                        if (date('N', $dateTimestamp) == 7) {
                            continue;
                        }

                        $availableDays++;
                        $dateValue = date('Y-m-d', $dateTimestamp);
                        ?>
                        <option
                            value="<?= $dateValue ?>"
                            <?= $formValues['dropoff'] === $dateValue ? 'selected' : '' ?>
                        >
                            <?= date('D, M j', $dateTimestamp) ?>
                            <?= $dayOffset === 0 ? ' (today)' : '' ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>

            <label class="upload" for="photo">
                <span id="photoName">Tap to add a photo of the damage (optional)</span>
                <input
                    type="file"
                    id="photo"
                    name="photo"
                    accept="image/jpeg,image/png,image/webp"
                >
            </label>

            <label class="check">
                <input
                    type="checkbox"
                    name="consent"
                    value="1"
                    <?= $formValues['consent'] ? 'checked' : '' ?>
                >
                Send me SMS or email updates about this repair
            </label>

            <button class="btn btn-primary btn-lg">Submit repair request</button>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
