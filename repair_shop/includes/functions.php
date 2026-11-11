<?php
function db()
{
    static $pdo;

    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    return $pdo;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function go($path)
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

function flash($message = null, $type = 'ok')
{
    if ($message !== null) {
        $_SESSION['flash'] = [$message, $type];
        return;
    }

    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $message;
}

function csrf_field()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function csrf_check()
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page, and try again.');
    }
}

function user()
{
    return $_SESSION['user'] ?? null;
}

function is_staff()
{
    return !empty($_SESSION['user'])
        && in_array($_SESSION['user']['role'], ['admin', 'staff', 'technician'], true);
}

function log_system_error($message, array $context = [])
{
    $details = [];

    foreach ($context as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $details[] = $key . '=' . (is_scalar($value) ? (string) $value : json_encode($value));
    }

    error_log('[repair_shop] ' . $message . ($details ? ' | ' . implode(' | ', $details) : ''));
}

function save_uploaded_status_image(array $file): ?string
{
    if (empty($file['name']) || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $size = @filesize($file['tmp_name']);
    if ($size === false || $size > 2 * 1024 * 1024) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (!in_array($mime, $allowedTypes, true)) {
        return null;
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return null;
    }

    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $extension = $extensionMap[$mime] ?? 'jpg';
    $fileName = 'status-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $directory = __DIR__ . '/../uploads';

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }
    }

    $target = $directory . '/' . $fileName;
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        return null;
    }

    return $fileName;
}

function save_uploaded_customer_image(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file($file['tmp_name'] ?? '')
    ) {
        return null;
    }

    $size = @filesize($file['tmp_name']);
    if ($size === false || $size > 3 * 1024 * 1024) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $extension = $extensionMap[$mime] ?? null;

    if ($extension === null || @getimagesize($file['tmp_name']) === false) {
        return null;
    }

    $directory = __DIR__ . '/../uploads';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return null;
    }

    $fileName = 'customer-' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $fileName)) {
        return null;
    }

    return $fileName;
}

function login_attempt_key(string $identity): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $secret = APP_SECRET !== '' ? APP_SECRET : 'repair-shop-local-only';

    return hash_hmac('sha256', strtolower(trim($identity)) . '|' . $ip, $secret);
}

function login_is_rate_limited(string $attemptKey): bool
{
    $statement = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE attempt_key=? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $statement->execute([$attemptKey]);

    return (int) $statement->fetchColumn() >= 5;
}

function record_login_attempt(string $attemptKey): void
{
    db()->prepare(
        'INSERT INTO login_attempts (attempt_key,attempted_at) VALUES (?,NOW())'
    )->execute([$attemptKey]);

    if (random_int(1, 100) === 1) {
        db()->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}

function clear_login_attempts(string $attemptKey): void
{
    db()->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([$attemptKey]);
}

function ensure_column_exists(string $table, string $column, string $definition): void
{
    try {
        $statement = db()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );
        $statement->execute([$table, $column]);

        if ((int) $statement->fetchColumn() > 0) {
            return;
        }

        db()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    } catch (Throwable $exception) {
        log_system_error('Failed to ensure schema column exists', ['table' => $table, 'column' => $column]);
    }
}

function ensure_index_dropped(string $table, string $index): void
{
    $statement = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema=DATABASE() AND table_name=? AND index_name=?'
    );
    $statement->execute([$table, $index]);

    if ((int) $statement->fetchColumn() > 0) {
        db()->exec('ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`');
    }
}

function ensure_reference_column_length(): void
{
    $statement = db()->query(
        "SELECT character_maximum_length
         FROM information_schema.columns
         WHERE table_schema=DATABASE()
           AND table_name='job_orders'
           AND column_name='reference_no'"
    );

    if ((int) $statement->fetchColumn() < 50) {
        db()->exec('ALTER TABLE job_orders MODIFY reference_no VARCHAR(50) NOT NULL');
    }
}

function migrate_legacy_customer_photos(): void
{
    if (get_setting('legacy_customer_photos_migrated', '0') === '1') {
        return;
    }

    $jobs = db()->query(
        "SELECT job_order_id,reference_no FROM job_orders
         WHERE customer_photo_path IS NULL
           AND reference_no REGEXP '^JCRS-[0-9]+$'"
    )->fetchAll();
    $extensions = ['jpg', 'jpeg', 'png', 'webp'];

    foreach ($jobs as $job) {
        foreach ($extensions as $extension) {
            $fileName = $job['reference_no'] . '.' . $extension;
            if (is_file(__DIR__ . '/../uploads/' . $fileName)) {
                db()->prepare(
                    'UPDATE job_orders SET customer_photo_path=? WHERE job_order_id=?'
                )->execute([$fileName, $job['job_order_id']]);
                break;
            }
        }
    }

    set_setting('legacy_customer_photos_migrated', '1');
}

function migrate_pending_notifications(): void
{
    if (get_setting('external_notification_status_migrated', '0') === '1') {
        return;
    }

    db()->exec(
        "UPDATE notifications
         SET delivery_status='pending',sent_at=NULL
         WHERE delivery_status='queued'"
    );
    set_setting('external_notification_status_migrated', '1');
}

function unlink_unverified_customer_accounts(): void
{
    if (get_setting('customer_account_links_migrated', '0') === '1') {
        return;
    }

    db()->exec(
        "UPDATE customers c
         JOIN users u ON u.user_id=c.user_id
         SET c.user_id=NULL
         WHERE u.role='customer'"
    );
    set_setting('customer_account_links_migrated', '1');
}

function ensure_table_exists(string $table, string $ddl): void
{
    try {
        $statement = db()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );
        $statement->execute([$table]);

        if ((int) $statement->fetchColumn() > 0) {
            return;
        }

        db()->exec($ddl);
    } catch (Throwable $exception) {
        log_system_error('Failed to ensure schema table exists', ['table' => $table]);
    }
}

function get_setting(string $name, $default = null)
{
    ensure_table_exists(
        'settings',
        'CREATE TABLE settings (
            setting_name VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $statement = db()->prepare('SELECT setting_value FROM settings WHERE setting_name=? LIMIT 1');
    $statement->execute([$name]);
    $value = $statement->fetchColumn();

    return $value === false || $value === null ? $default : $value;
}

function set_setting(string $name, $value): void
{
    ensure_table_exists(
        'settings',
        'CREATE TABLE settings (
            setting_name VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    db()->prepare(
        'INSERT INTO settings (setting_name, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    )->execute([$name, (string) $value]);
}

function initialize_application_schema(): void
{
    ensure_table_exists(
        'settings',
        'CREATE TABLE settings (
            setting_name VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    ensure_column_exists('parts', 'description', 'TEXT NULL');
    ensure_column_exists('status_history', 'image_path', 'VARCHAR(255) NULL');
    ensure_column_exists('notifications', 'image_path', 'VARCHAR(255) NULL');
    ensure_column_exists('job_orders', 'customer_photo_path', 'VARCHAR(255) NULL');
    ensure_reference_column_length();
    ensure_index_dropped('customers', 'uq_customers_contact');
    ensure_index_dropped('customers', 'uq_customers_email');
    ensure_table_exists(
        'login_attempts',
        'CREATE TABLE login_attempts (
            attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            attempt_key CHAR(64) NOT NULL,
            attempted_at DATETIME NOT NULL,
            INDEX idx_login_attempt_key_time (attempt_key, attempted_at),
            INDEX idx_login_attempt_time (attempted_at)
        ) ENGINE=InnoDB'
    );
    ensure_table_exists(
        'stock_movements',
        'CREATE TABLE stock_movements (
            movement_id INT AUTO_INCREMENT PRIMARY KEY,
            part_id INT NOT NULL,
            movement_type ENUM(\'receipt\',\'usage\',\'adjustment\') NOT NULL DEFAULT \'receipt\',
            quantity INT NOT NULL,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            supplier_id INT NULL,
            admin_user_id INT NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_stock_part FOREIGN KEY (part_id) REFERENCES parts (part_id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_stock_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_stock_admin FOREIGN KEY (admin_user_id) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT chk_stock_amount CHECK (quantity <> 0)
        ) ENGINE=InnoDB'
    );
    migrate_legacy_customer_photos();
    migrate_pending_notifications();
    unlink_unverified_customer_accounts();
}

function require_staff()
{
    if (!user() || !is_staff()) {
        flash('Please log in with a staff account.', 'err');
        go('login.php');
    }
}

function require_admin()
{
    require_staff();

    if (user()['role'] !== 'admin') {
        flash('Only admins can open that page.', 'err');
        go('admin/index.php');
    }
}

function my_tech_id()
{
    $statement = db()->prepare(
        'SELECT technician_id FROM technicians WHERE user_id=?'
    );
    $statement->execute([user()['user_id']]);

    return $statement->fetchColumn() ?: null;
}

function tech_scope()
{
    if (user() && user()['role'] === 'technician') {
        return [' AND j.assigned_tech_id=?', [my_tech_id() ?: 0]];
    }

    return ['', []];
}

function statuses()
{
    return db()->query(
        'SELECT * FROM repair_statuses ORDER BY sort_order'
    )->fetchAll();
}

function pretty_status($name)
{
    return ucfirst(str_replace('_', ' ', $name));
}

function badge($name)
{
    $class = strtolower(trim(preg_replace('/[^a-z]+/i', '-', $name), '-'));
    $label = e(pretty_status($name));

    return '<span class="badge b-' . $class . '">' . $label . '</span>';
}

function audit($action, $table, $id)
{
    if (!user() || !is_staff()) {
        return;
    }

    db()->prepare(
        'INSERT INTO audit_logs (admin_id,action,target_table,target_id) VALUES (?,?,?,?)'
    )->execute([user()['user_id'], $action, $table, $id]);
}

function notify($jobId, $customerId, $trigger, $message, $channel = 'sms', $imagePath = null)
{
    $userId = user() ? user()['user_id'] : null;
    $deliveredInApp = $channel === 'in_app';
    $deliveryStatus = $deliveredInApp ? 'sent' : 'pending';

    db()->prepare(
        "INSERT INTO notifications
            (job_order_id,customer_id,user_id,channel,trigger_type,message,image_path,delivery_status,sent_at)
         VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute([
        $jobId,
        $customerId,
        $userId,
        $channel,
        $trigger,
        $message,
        $imagePath,
        $deliveryStatus,
        $deliveredInApp ? date('Y-m-d H:i:s') : null,
    ]);
}

function create_job(array $data, $technicianId = null)
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $customerId = null;

        if (!empty($data['user_id'])) {
            $statement = $pdo->prepare(
                'SELECT customer_id FROM customers WHERE user_id=?'
            );
            $statement->execute([$data['user_id']]);
            $customerId = $statement->fetchColumn();
        }

        if (!$customerId) {
            $pdo->prepare(
                'INSERT INTO customers
                    (user_id,full_name,contact_number,email,consent_given)
                 VALUES (?,?,?,?,?)'
            )->execute([
                $data['user_id'] ?? null,
                $data['name'],
                $data['contact'],
                $data['email'] ?: null,
                empty($data['consent']) ? 0 : 1,
            ]);
            $customerId = $pdo->lastInsertId();
        } else {
            $pdo->prepare(
                'UPDATE customers SET consent_given=? WHERE customer_id=?'
            )->execute([empty($data['consent']) ? 0 : 1, $customerId]);
        }

        $pdo->prepare(
            'INSERT INTO devices (customer_id,brand,model,imei_serial) VALUES (?,?,?,?)'
        )->execute([
            $customerId,
            $data['brand'],
            $data['model'],
            $data['imei'] ?: null,
        ]);
        $deviceId = $pdo->lastInsertId();

        $firstStatusId = $pdo->query(
            'SELECT status_id FROM repair_statuses ORDER BY sort_order LIMIT 1'
        )->fetchColumn();

        $pdo->prepare(
            'INSERT INTO job_orders
                (reference_no,customer_id,device_id,assigned_tech_id,current_status_id,reported_issue,condition_notes)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            'JCRS-' . strtoupper(bin2hex(random_bytes(16))),
            $customerId,
            $deviceId,
            $technicianId ?: null,
            $firstStatusId,
            $data['issue'],
            $data['notes'] ?: null,
        ]);

        $jobId = (int) $pdo->lastInsertId();
        $reference = $pdo->query(
            'SELECT reference_no FROM job_orders WHERE job_order_id=' . $jobId
        )->fetchColumn();

        $pdo->prepare(
            'INSERT INTO status_history (job_order_id,status_id,remarks) VALUES (?,?,?)'
        )->execute([$jobId, $firstStatusId, 'Service request created']);

        $statement = $pdo->prepare(
            'SELECT consent_given,email FROM customers WHERE customer_id=?'
        );
        $statement->execute([$customerId]);
        $customer = $statement->fetch();

        if ($customer['consent_given']) {
            $message = "We received your {$data['brand']} {$data['model']}. "
                . "Your reference number is $reference. Track it at any time on our website.";

            notify(
                $jobId,
                $customerId,
                'received',
                $message,
                $customer['email'] ? 'email' : 'sms'
            );
        }

        $pdo->commit();

        return ['id' => $jobId, 'ref' => $reference];
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function set_status($jobId, $statusId, $remarks = '', $cancelReason = null)
{
    $pdo = db();

    $statement = $pdo->prepare(
        'SELECT * FROM repair_statuses WHERE status_id=?'
    );
    $statement->execute([$statusId]);
    $status = $statement->fetch();

    $pdo->prepare(
        'UPDATE job_orders SET current_status_id=?, cancel_reason=? WHERE job_order_id=?'
    )->execute([
        $statusId,
        $status['status_name'] === 'cancelled' ? $cancelReason : null,
        $jobId,
    ]);

    $pdo->prepare(
        'INSERT INTO status_history (job_order_id,status_id,remarks) VALUES (?,?,?)'
    )->execute([$jobId, $statusId, $remarks ?: null]);

    if ($status['is_milestone']) {
        $statement = $pdo->prepare(
            'SELECT j.reference_no,c.customer_id,c.consent_given,c.email
             FROM job_orders j
             JOIN customers c ON c.customer_id=j.customer_id
             WHERE j.job_order_id=?'
        );
        $statement->execute([$jobId]);
        $job = $statement->fetch();

        if ($job['consent_given']) {
            $trigger = 'status_' . strtolower(
                preg_replace('/\W+/', '_', $status['status_name'])
            );
            $message = "Update on {$job['reference_no']}: "
                . $status['customer_label']
                . '.'
                . ($remarks ? " $remarks" : '');

            notify(
                $jobId,
                $job['customer_id'],
                $trigger,
                $message,
                $job['email'] ? 'email' : 'sms'
            );
        }
    }
}
