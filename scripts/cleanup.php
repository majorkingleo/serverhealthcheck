<?php
/**
 * Cleanup script – deletes rows older than 6 months from all _stats tables.
 * Run via cron, e.g.:
 *   0 3 * * 0  php /srv/htdocs/serverhealthcheck/scripts/cleanup.php
 */

require_once __DIR__ . '/../conf/db.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Tables that use a `timestamp` column
$timestamp_tables = [
    'health_checks_stats',
];

// Tables that use a `run_at` column
$run_at_tables = [
    'smart_results',
    'smart_metrics',
    'disk_usage',
    'cpu_stats',
    'ram_stats',
    'process_stats',
    'mariadb_table_stats',
    'service_stats',
    'cert_stats',
    'update_stats',
    'zombie_stats',
];

$cutoff = 'DATE_SUB(NOW(), INTERVAL 6 MONTH)';
$total  = 0;

foreach ($timestamp_tables as $table) {
    $stmt = $pdo->query("DELETE FROM `$table` WHERE `timestamp` < $cutoff");
    $rows = $stmt->rowCount();
    $total += $rows;
    echo "  $table: deleted $rows rows\n";
}

foreach ($run_at_tables as $table) {
    $stmt = $pdo->query("DELETE FROM `$table` WHERE `run_at` < $cutoff");
    $rows = $stmt->rowCount();
    $total += $rows;
    echo "  $table: deleted $rows rows\n";
}

echo "Cleanup complete – $total rows removed (cutoff: 6 months ago)\n";
