<?php
require_once '../conf/db.php';

header('Content-Type: image/svg+xml');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$status = 'UNKNOWN';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Worst status across all enabled checks in last 24 hours
    $row = $pdo->query(
        "SELECT h.status
         FROM health_checks h
         INNER JOIN checks c ON c.script_name = h.check_name
         WHERE c.enabled = 1
           AND h.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY FIELD(h.status, 'ERROR', 'WARN', 'UNKNOWN', 'OK') ASC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $status = $row['status'];
    }

    // Timed-out checks count as ERROR
    $timeout = $pdo->query(
        "SELECT COUNT(*) FROM checks
         WHERE enabled = 1
           AND next_run IS NOT NULL
           AND next_run < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    )->fetchColumn();

    if ($timeout > 0) {
        $status = 'ERROR';
    }
} catch (Exception $e) {
    $status = 'UNKNOWN';
}

// Green check / Orange triangle+! / Red circle+× / Gray circle+?
switch ($status) {
    case 'OK':
        echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
  <circle cx="16" cy="16" r="16" fill="#22c55e"/>
  <polyline points="8,17 13,23 24,10" fill="none" stroke="#ffffff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
SVG;
        break;
    case 'WARN':
        echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
  <polygon points="16,2 31,30 1,30" fill="#f97316"/>
  <rect x="14.5" y="11" width="3" height="9" rx="1.5" fill="#ffffff"/>
  <circle cx="16" cy="26" r="2" fill="#ffffff"/>
</svg>
SVG;
        break;
    case 'ERROR':
        echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
  <circle cx="16" cy="16" r="16" fill="#ef4444"/>
  <line x1="10" y1="10" x2="22" y2="22" stroke="#ffffff" stroke-width="3.5" stroke-linecap="round"/>
  <line x1="22" y1="10" x2="10" y2="22" stroke="#ffffff" stroke-width="3.5" stroke-linecap="round"/>
</svg>
SVG;
        break;
    default:
        echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
  <circle cx="16" cy="16" r="16" fill="#6b7280"/>
  <text x="16" y="23" text-anchor="middle" font-family="sans-serif" font-size="20" font-weight="bold" fill="#ffffff">?</text>
</svg>
SVG;
        break;
}
