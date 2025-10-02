<?php
// upload_ajax.php — AJAX endpoint (JSON), progress-friendly
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

// Debug on (প্রয়োজনে বন্ধ করতে পারো)
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);

// Server dropped body (limits) → 413
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    empty($_POST) && empty($_FILES) &&
    (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    http_response_code(413);
    echo json_encode(['ok'=>false, 'error'=>'Server 413: Increase php.ini or web server limits (post_max_size / upload_max_filesize / client_max_body_size).']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }

// CSRF (session/cookie সমস্যা হলে এখানে ফেল করবে)
if (!csrf_check($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']); exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? 'unknown';
    echo json_encode(['ok'=>false,'error'=>'No file or upload error (code: '.$err.')']); exit;
}

$origName = $_FILES['file']['name'] ?? 'file';
$tmp      = $_FILES['file']['tmp_name'] ?? '';
$size     = (int)($_FILES['file']['size'] ?? 0);
$mime     = $tmp && is_file($tmp) ? (mime_content_type($tmp) ?: 'application/octet-stream') : 'application/octet-stream';

// Allow PDF and most files; block only dangerous types (helpers.php -> is_blocked_file)
if (function_exists('is_blocked_file') && is_blocked_file($origName, $mime)) {
    echo json_encode([
        'ok'=>false,
        'error'=>'Blocked for security (exts='.implode(',', exts_from_name_all($origName)).', mime='.$mime.')'
    ]);
    exit;
}

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true)) {
    echo json_encode(['ok'=>false,'error'=>'Cannot create storage directory']); exit;
}

$ext = ext_from_name($origName);
$storedName = bin2hex(random_bytes(16)) . ($ext ? ".{$ext}" : '');
$dest = $storageDir . '/' . $storedName;
if (!move_uploaded_file($tmp, $dest)) {
    echo json_encode(['ok'=>false,'error'=>'Failed to save file (check permissions on storage/)']); exit;
}

// inputs
$expire_days   = max(1, min(30, (int)($_POST['expire_days'] ?? 3)));
$expires_at    = (new DateTime())->modify("+{$expire_days} days")->format('Y-m-d H:i:s');
$max_downloads = isset($_POST['max_downloads']) && $_POST['max_downloads'] !== '' ? (int)$_POST['max_downloads'] : null;

// DB insert
try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO files (orig_name, stored_name, mime, size, max_downloads, expires_at)
                           VALUES (:orig,:stored,:mime,:size,:maxd,:exp)");
    $stmt->execute([
        ':orig'   => $origName,
        ':stored' => $storedName,
        ':mime'   => $mime,
        ':size'   => $size,
        ':maxd'   => $max_downloads,
        ':exp'    => $expires_at
    ]);
    $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    @unlink($dest);
    echo json_encode(['ok'=>false,'error'=>'DB error: '.$e->getMessage()]); exit;
}

// Share link
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base   = rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/\\');
$link   = $base . "/dl.php?id={$id}";

echo json_encode(['ok'=>true, 'link'=>$link, 'expires_at'=>$expires_at, 'size'=>$size, 'name'=>$origName]);
