<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

define('DB_HOST', getenv('REPAIR_SHOP_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('REPAIR_SHOP_DB_NAME') ?: 'cellphone_repair');
define('DB_USER', getenv('REPAIR_SHOP_DB_USER') ?: 'root');
define('DB_PASS', getenv('REPAIR_SHOP_DB_PASS') ?: '');

define('SHOP_NAME', "Jake's Cellphone Repair Shop");
define('SHOP_TAGLINE', 'Drop it off broken. Track it online. Pick it up fixed.');
define('SHOP_ADDRESS', 'Valenzuela City, Metro Manila');
define('SHOP_PHONE', '0900 000 0000');
define('SHOP_EMAIL', 'hello@jakerepair.example');
define('SHOP_HOURS', 'Mon to Sat, 9:00 AM to 7:00 PM');

$documentRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
$applicationDirectory = str_replace('\\', '/', __DIR__);
define(
    'BASE_URL',
    rtrim(substr($applicationDirectory, strlen($documentRoot)), '/') . '/'
);
define(
    'APP_PUBLIC_URL',
    rtrim((string) (getenv('REPAIR_SHOP_PUBLIC_URL') ?: ''), '/')
);
define(
    'APP_SECRET',
    (string) (getenv('REPAIR_SHOP_APP_SECRET') ?: DB_PASS)
);

require_once __DIR__ . '/includes/functions.php';
initialize_application_schema();
