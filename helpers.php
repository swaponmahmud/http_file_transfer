<?php
// helpers.php — helpers with robust extension/MIME handling

if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// CSRF
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}
function csrf_check($token){
    return hash_equals($_SESSION['csrf_token'] ?? '', $token ?? '');
}

// Return lower-cased, trimmed last extension (may be empty)
function ext_from_name($name){
    $p = pathinfo($name, PATHINFO_EXTENSION);
    return strtolower(trim((string)$p));
}

// Return array of ALL extensions (after each dot), lower-cased
// e.g. "report.v2.final.PDF" => ["v2","final","pdf"]
function exts_from_name_all($name){
    $base = basename((string)$name);
    $parts = explode('.', $base);
    array_shift($parts); // remove leading name
    $out = [];
    foreach ($parts as $e) {
        $e = strtolower(trim($e));
        if ($e !== '') $out[] = $e;
    }
    return $out;
}

// Dangerous extensions blocklist (server-side scripts / executables)
function blocked_ext_list(){
    return [
        'php','phtml','php3','php4','php5','php7','php8','phps','phar',
        'asp','aspx','jsp','cfm','cgi','pl','rb','py',
        'sh','bash','zsh','ps1','psm1','vbs',
        'exe','dll','com','msi','msp','scr','bat','cmd','jar'
    ];
}

/**
 * Decide if a file should be blocked.
 * Rules:
 *  - If explicit safe: pdf (ext or MIME) => allow
 *  - Else, if any extension is in blocked list => block
 *  - Otherwise allow
 */
function is_blocked_file(string $origName, string $mime): bool {
    $exts = exts_from_name_all($origName);
    $blocked = blocked_ext_list();

    // Explicit allow for PDF
    if (in_array('pdf', $exts, true) || strtolower($mime) === 'application/pdf') {
        return false;
    }

    // If any part looks dangerous, block
    foreach ($exts as $e) {
        if (in_array($e, $blocked, true)) {
            return true;
        }
    }
    return false;
}
