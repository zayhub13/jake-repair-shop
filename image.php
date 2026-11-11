<?php
require __DIR__ . '/config.php';

$reference = strtoupper(trim((string) ($_GET['ref'] ?? '')));
$fileName = (string) ($_GET['file'] ?? '');
if ($reference === '' || $fileName === '' || basename($fileName) !== $fileName) {
    http_response_code(404);
    exit;
}

$statement = db()->prepare(
    'SELECT j.job_order_id,j.customer_photo_path
     FROM job_orders j
     WHERE j.reference_no=?'
);
$statement->execute([$reference]);
$job = $statement->fetch();
if (!$job) {
    http_response_code(404);
    exit;
}

$legacyReference = preg_match('/^JCRS-[0-9]+$/', $reference) === 1;
if ($legacyReference && !is_staff()) {
    http_response_code(404);
    exit;
}

$statement = db()->prepare(
    'SELECT
        (j.customer_photo_path=?)
        OR EXISTS (
            SELECT 1 FROM status_history h
            WHERE h.job_order_id=j.job_order_id AND h.image_path=?
        )
        OR EXISTS (
            SELECT 1 FROM notifications n
            WHERE n.job_order_id=j.job_order_id AND n.image_path=?
        )
     FROM job_orders j
     WHERE j.job_order_id=?'
);
$statement->execute([$fileName, $fileName, $fileName, $job['job_order_id']]);
if (!(bool) $statement->fetchColumn()) {
    http_response_code(404);
    exit;
}

$uploadDirectory = realpath(__DIR__ . '/uploads');
$imagePath = realpath(__DIR__ . '/uploads/' . $fileName);
if (!$uploadDirectory || !$imagePath || dirname($imagePath) !== $uploadDirectory || !is_file($imagePath)) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($imagePath);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($imagePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($imagePath);
