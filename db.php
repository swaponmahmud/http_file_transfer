<?php
// db.php — PDO connection
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    // 👉 আপনার DB ঠিক করে দিন
    $host = '127.0.0.1';
    $db   = 'filetransfer';
    $user = 'root';
    $pass = '';

    $dsn  = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, $user, $pass, $opts);
    return $pdo;
}
