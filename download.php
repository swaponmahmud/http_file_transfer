<?php
// download.php — serve by id
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
if (!csrf_check($_POST['csrf_token'] ?? '')) exit('Invalid CSRF');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) exit('Invalid link');

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM files WHERE id = :id LIMIT 1");
$stmt->execute([':id'=>$id]);
$file = $stmt->fetch();
if (!$file) exit('Invalid link');

$now     = new DateTime();
$expires = new DateTime($file['expires_at']);
if ($now > $expires) exit('Expired');

if ($file['max_downloads'] !== null && (int)$file['downloads'] >= (int)$file['max_downloads']) {
    exit('Download limit reached');
}

$storageDir = __DIR__ . '/storage';
$path = $storageDir . '/' . $file['stored_name'];
if (!is_file($path)) exit('File missing');

// increment downloads
$pdo->beginTransaction();
$upd = $pdo->prepare("UPDATE files SET downloads = downloads + 1 WHERE id = :id");
$upd->execute([':id'=>$file['id']]);
$pdo->commit();

// clean buffers
while (ob_get_level()) { ob_end_clean(); }

// serve
$fname = $file['orig_name'];
$mime  = $file['mime'] ?: 'application/octet-stream';
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="'.basename($fname).'"');
header('Content-Length: ' . (string)$file['size']);
header('Cache-Control: no-cache, must-revalidate');
readfile($path);
exit;
