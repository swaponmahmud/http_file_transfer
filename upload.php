<?php
// upload.php — unlimited file upload, only dangerous extensions blocked
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/* ---------- Oversize POST detection (server-side limit check) ---------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    empty($_POST) && empty($_FILES) &&
    (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    $postMax = ini_get('post_max_size') ?: 'unknown';
    $uplMax  = ini_get('upload_max_filesize') ?: 'unknown';
    http_response_code(413);
    exit("Upload too large. Server limits — post_max_size={$postMax}, upload_max_filesize={$uplMax}. 
    Please increase php.ini or Apache/Nginx limits.");
}

/* ---------- Normal flow ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    http_response_code(405); 
    exit('Method not allowed'); 
}
if (!csrf_check($_POST['csrf_token'] ?? '')) exit('Invalid CSRF token');

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? 'unknown';
    exit('No file or upload error (code: '.h((string)$err).')');
}

$origName = $_FILES['file']['name'] ?? 'file';
$tmp      = $_FILES['file']['tmp_name'] ?? '';
$size     = (int)($_FILES['file']['size'] ?? 0);
$mime     = $tmp && is_file($tmp) ? (mime_content_type($tmp) ?: 'application/octet-stream') : 'application/octet-stream';
$ext      = ext_from_name($origName);

// ✅ নতুন ভ্যালিডেশন: কেবল বিপজ্জনক ফাইল এক্সটেনশন ব্লক
if (is_blocked_ext($ext)) {
    exit('This file type is blocked on the server for security.');
}

// storage dir
$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);

// random stored filename
$storedName = bin2hex(random_bytes(16)) . ($ext ? ".{$ext}" : '');
$dest = $storageDir . '/' . $storedName;
if (!move_uploaded_file($tmp, $dest)) exit('Failed to save file');

// expiry
$expire_days   = max(1, min(30, (int)($_POST['expire_days'] ?? 3)));
$expires_at    = (new DateTime())->modify("+{$expire_days} days")->format('Y-m-d H:i:s');
$max_downloads = isset($_POST['max_downloads']) && $_POST['max_downloads'] !== '' ? (int)$_POST['max_downloads'] : null;

// DB insert
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

// share link
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base   = rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/\\');
$link   = $base . "/dl.php?id={$id}";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Link generated</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
  <div class="card mx-auto" style="max-width:760px;">
    <div class="card-body">
      <h5 class="card-title">Share this link</h5>
      <p>Send this link to your friend. It will expire on <strong><?=h($expires_at)?></strong>.</p>
      <div class="input-group mb-3">
        <input class="form-control" id="shareLink" value="<?=h($link)?>">
        <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('shareLink').value)">Copy</button>
      </div>
      <a class="btn btn-secondary" href="index.php">Upload another</a>
    </div>
  </div>
</div>
</body>
</html>
