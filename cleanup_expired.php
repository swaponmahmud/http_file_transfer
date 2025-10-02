<?php
// cleanup.php — delete expired files + remove rows
require_once __DIR__ . '/db.php';

$pdo = db();
$stmt = $pdo->query("SELECT * FROM files WHERE expires_at < NOW()");
$rows = $stmt->fetchAll();

$storageDir = __DIR__ . '/storage';
$removed = 0;
foreach ($rows as $r) {
    $path = $storageDir . '/' . $r['stored_name'];
    if (is_file($path)) { @unlink($path); }
    $del = $pdo->prepare("DELETE FROM files WHERE id = :id");
    $del->execute([':id'=>$r['id']]);
    $removed++;
}
echo "Cleanup done. Removed {$removed} file(s).\n";
