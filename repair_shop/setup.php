<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/config.php';

$initialPassword = getenv('REPAIR_SHOP_INITIAL_ADMIN_PASSWORD') ?: '';
if (strlen($initialPassword) < 12) {
    fwrite(STDERR, "Set REPAIR_SHOP_INITIAL_ADMIN_PASSWORD to a password of at least 12 characters.\n");
    exit(1);
}

$pdo = db();
$statusCount = $pdo->query('SELECT COUNT(*) FROM repair_statuses')->fetchColumn();

if (!$statusCount) {
    $pdo->exec(
        "INSERT INTO repair_statuses (status_name,customer_label,is_milestone,sort_order)
         VALUES
            ('received','Device received',1,1),
            ('diagnosing','Being diagnosed',0,2),
            ('awaiting_approval','Waiting for your approval',1,3),
            ('awaiting_parts','Waiting for parts',0,4),
            ('in_repair','Under repair',0,5),
            ('ready_for_pickup','Ready for pickup',1,6),
            ('completed','Completed',1,7),
            ('cancelled','Cancelled',1,8)"
    );
    fwrite(STDOUT, "Repair statuses added.\n");
}

$adminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
if (!$adminCount) {
    $statement = $pdo->prepare(
        "INSERT INTO users (username,email,password_hash,full_name,role)
         VALUES ('admin','admin@jakerepair.example',?,'Shop Admin','admin')"
    );
    $statement->execute([password_hash($initialPassword, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Admin created. Sign in as admin and change the password after setup.\n");
} else {
    fwrite(STDOUT, "An admin already exists; no account was created.\n");
}
