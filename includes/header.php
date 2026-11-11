<?php
require_once __DIR__ . '/../config.php';

$area = $area ?? 'public';
$title = $title ?? SHOP_NAME;
$currentUser = user();
$page = basename($_SERVER['SCRIPT_NAME']);
$flashMessage = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | <?= e(SHOP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/style.css?v=4">
</head>
<body class="<?= e($area) ?>">

<?php if ($area === 'public'): ?>
    <header class="topbar">
        <div class="wrap bar">
            <a class="brand" href="<?= BASE_URL ?>index.php">
                <img
                    class="brand-logo"
                    src="<?= BASE_URL ?>assets/shop-logo.png"
                    alt="<?= e(SHOP_NAME) ?>"
                >
            </a>

            <button
                class="burger"
                id="navToggle"
                aria-label="Open menu"
                aria-expanded="false"
            >Menu</button>

            <nav id="mainNav">
                <a
                    href="<?= BASE_URL ?>index.php"
                    class="<?= $page === 'index.php' ? 'on' : '' ?>"
                >Home</a>
                <a
                    href="<?= BASE_URL ?>track.php"
                    class="<?= $page === 'track.php' ? 'on' : '' ?>"
                >Track Repair</a>
                <a
                    href="<?= BASE_URL ?>submit.php"
                    class="<?= $page === 'submit.php' ? 'on' : '' ?>"
                >Submit Request</a>

                <?php if ($currentUser): ?>
                    <?php if (is_staff()): ?>
                        <a href="<?= BASE_URL ?>admin/index.php">Dashboard</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>logout.php" data-confirm="Are you sure you want to log out?">
                        Log out (<?= e(explode(' ', $currentUser['full_name'])[0]) ?>)
                    </a>
                <?php else: ?>
                    <a
                        href="<?= BASE_URL ?>login.php"
                        class="<?= $page === 'login.php' ? 'on' : '' ?>"
                    >Login</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="wrap page">
<?php else: ?>
    <?php
    $navigationItems = [
        ['index.php', 'Dashboard'],
        ['jobs.php', 'Service Requests'],
        ['customers.php', 'Customers & Devices'],
        ['devices.php', 'Devices / History'],
        ['notifications.php', 'Notifications & Settings'],
        ['inventory.php', 'Parts Inventory'],
        ['reports.php', 'Reports'],
    ];

    if ($currentUser['role'] === 'admin') {
        $navigationItems[] = ['suppliers.php', 'Suppliers'];
        $navigationItems[] = ['staff.php', 'Staff'];
        $navigationItems[] = ['audit.php', 'Audit Log'];
    }
    ?>

    <div class="shell">
        <aside class="side">
            <a class="brand" href="<?= BASE_URL ?>admin/index.php">
                <img
                    class="brand-logo"
                    src="<?= BASE_URL ?>assets/shop-logo.png"
                    alt="<?= e(SHOP_NAME) ?>"
                >
            </a>

            <nav>
                <?php foreach ($navigationItems as $item): ?>
                    <a
                        href="<?= BASE_URL ?>admin/<?= e($item[0]) ?>"
                        class="<?= $page === $item[0] ? 'on' : '' ?>"
                    ><?= e($item[1]) ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="who">
                <?= e($currentUser['full_name']) ?>
                <small><?= e($currentUser['role']) ?></small>
                <a href="<?= BASE_URL ?>index.php">View public site</a>
                <a href="<?= BASE_URL ?>logout.php" data-confirm="Are you sure you want to log out?">Log out</a>
            </div>
        </aside>

        <main class="content">
<?php endif; ?>

<?php if ($flashMessage): ?>
    <div class="flash <?= e($flashMessage[1]) ?>" role="status">
        <?= e($flashMessage[0]) ?>
    </div>
<?php endif; ?>
