<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();

$check_name = $_GET['check'] ?? '';
if ($check_name === '') {
    header('Location: index.php');
    exit;
}

// Resolve display title
$stmt = $pdo->prepare("SELECT title FROM checks WHERE script_name = ?");
$stmt->execute([$check_name]);
$title = $stmt->fetchColumn() ?: $check_name;

// Timeline: status counts grouped by day for last 30 days
$stmt = $pdo->prepare(
    "SELECT DATE(timestamp) AS date, status, COUNT(*) AS count
     FROM health_checks_stats
     WHERE check_name = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY DATE(timestamp), status
     ORDER BY date"
);
$stmt->execute([$check_name]);

$timeline = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $timeline[$row['date']][$row['status']] = (int)$row['count'];
}

$is_smart = ($check_name === 'check_smart.py');
$is_disk  = ($check_name === 'check_disk.py');
$is_cpu   = ($check_name === 'check_cpu.py');
$is_ram   = ($check_name === 'check_ram.py');
$is_proc  = ($check_name === 'check_processes.py');
$is_db      = ($check_name === 'check_mariadb.py');
$is_svc     = ($check_name === 'check_services.py');
$is_cert       = ($check_name === 'check_cert.py');
$is_cert_files = ($check_name === 'check_cert_files.py');
$is_updates    = ($check_name === 'check_updates.py');
$is_zombies    = ($check_name === 'check_zombies.py');

if ($is_smart) {
    // Per-device health per day: count PASSED/FAILED
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date, device, health, COUNT(*) AS count
         FROM smart_results
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at), device, health
         ORDER BY date, device"
    );
    $smart_health = [];
    $smart_devices = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $smart_health[$row['date']][$row['device']][$row['health']] = (int)$row['count'];
        $smart_devices[$row['device']] = true;
    }
    $smart_devices = array_keys($smart_devices);
    sort($smart_devices);

    // Per-device temperature per day: avg value
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date, device, metric, unit, ROUND(AVG(value),1) AS avg_val
         FROM smart_metrics
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at), device, metric
         ORDER BY date, device, metric"
    );
    $smart_metrics_data = [];
    $smart_metric_devices = [];
    $smart_metric_units = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $smart_metrics_data[$row['date']][$row['device']][$row['metric']] = (float)$row['avg_val'];
        $smart_metric_devices[$row['device']] = true;
        $smart_metric_units[$row['metric']] = $row['unit'];
    }
    $smart_metric_devices = array_keys($smart_metric_devices);
    sort($smart_metric_devices);
    $smart_metric_names = array_keys($smart_metric_units);
}

if ($is_disk) {
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date, mountpoint, ROUND(AVG(used_mb)) AS avg_used, ROUND(AVG(total_mb)) AS avg_total
         FROM disk_usage
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at), mountpoint
         ORDER BY date, mountpoint"
    );
    $disk_usage = [];
    $disk_mounts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $disk_usage[$row['date']][$row['mountpoint']] = [
            'used'  => (int)$row['avg_used'],
            'total' => (int)$row['avg_total'],
        ];
        $disk_mounts[$row['mountpoint']] = true;
    }
    $disk_mounts = array_keys($disk_mounts);
    sort($disk_mounts);
}

if ($is_cpu) {
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date,
                ROUND(AVG(load1), 2)  AS avg1,
                ROUND(AVG(load5), 2)  AS avg5,
                ROUND(AVG(load15), 2) AS avg15
         FROM cpu_stats
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at)
         ORDER BY date"
    );
    $cpu_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cpu_data[$row['date']] = [
            'load1'  => (float)$row['avg1'],
            'load5'  => (float)$row['avg5'],
            'load15' => (float)$row['avg15'],
        ];
    }
}

if ($is_ram) {
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date, ROUND(AVG(used_mb)) AS avg_used, ROUND(AVG(total_mb)) AS avg_total
         FROM ram_stats
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at)
         ORDER BY date"
    );
    $ram_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ram_data[$row['date']] = [
            'used'  => (int)$row['avg_used'],
            'total' => (int)$row['avg_total'],
        ];
    }
}

if ($is_proc) {
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date,
                ROUND(AVG(process_count)) AS avg_count,
                MAX(process_count) AS max_count,
                MIN(process_count) AS min_count
         FROM process_stats
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at)
         ORDER BY date"
    );
    $proc_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $proc_data[$row['date']] = [
            'avg' => (int)$row['avg_count'],
            'max' => (int)$row['max_count'],
            'min' => (int)$row['min_count'],
        ];
    }
}

if ($is_svc) {
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date,
                ROUND(AVG(failed_count))   AS avg_failed,
                ROUND(AVG(active_count))   AS avg_active,
                ROUND(AVG(inactive_count)) AS avg_inactive
         FROM service_stats
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at)
         ORDER BY date"
    );
    $svc_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $svc_data[$row['date']] = [
            'failed'   => (int)$row['avg_failed'],
            'active'   => (int)$row['avg_active'],
            'inactive' => (int)$row['avg_inactive'],
        ];
    }

    // Latest state per unit for the service-states widget
    $stmt2 = $pdo->query(
        "SELECT unit_name, state FROM service_unit_states WHERE state != 'inactive' ORDER BY state, unit_name"
    );
    $svc_units = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

if ($is_db) {
    // Widget 1: total rows per database (schema) per day
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date, table_schema, ROUND(SUM(row_count)) AS total_rows
         FROM mariadb_table_stats
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at), table_schema
         ORDER BY date, table_schema"
    );
    $db_schema_data = [];
    $db_schemas = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $db_schema_data[$row['date']][$row['table_schema']] = (int)$row['total_rows'];
        $db_schemas[$row['table_schema']] = true;
    }
    $db_schemas = array_keys($db_schemas);
    sort($db_schemas);

    // Widget per schema: rows per table per day, indexed by [date][schema][table]
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date, table_schema, table_name, ROUND(AVG(row_count)) AS avg_rows
         FROM mariadb_table_stats
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at), table_schema, table_name
         ORDER BY date, table_schema, table_name"
    );
    $db_table_data = [];    // [date][schema][table] = rows
    $db_schema_tables = []; // [schema] = [table, ...]
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $db_table_data[$row['date']][$row['table_schema']][$row['table_name']] = (int)$row['avg_rows'];
        $db_schema_tables[$row['table_schema']][$row['table_name']] = true;
    }
    foreach ($db_schema_tables as $schema => $tbls) {
        ksort($tbls);
        $db_schema_tables[$schema] = array_keys($tbls);
    }
}

if ($is_cert) {
    $p_stmt = $pdo->prepare("SELECT parameters FROM checks WHERE script_name = 'check_cert.py'");
    $p_stmt->execute();
    $cert_params = preg_split('/\s+/', trim($p_stmt->fetchColumn() ?: '14 7'));
    $cert_warn = (int)($cert_params[0] ?? 14);
    $cert_crit = (int)($cert_params[1] ?? 7);

    // Latest days_left per live TLS host (port > 0)
    $stmt = $pdo->query(
        "SELECT c1.host, c1.port, c1.days_left
         FROM cert_stats c1
         INNER JOIN (
             SELECT host, port, MAX(run_at) AS latest
             FROM cert_stats
             WHERE port > 0
             GROUP BY host, port
         ) c2 ON c1.host = c2.host AND c1.port = c2.port AND c1.run_at = c2.latest
         ORDER BY c1.host"
    );
    $cert_current = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($is_cert_files) {
    $p_stmt = $pdo->prepare("SELECT parameters FROM checks WHERE script_name = 'check_cert_files.py'");
    $p_stmt->execute();
    $cert_params = preg_split('/\s+/', trim($p_stmt->fetchColumn() ?: '14 7'));
    $cert_warn = (int)($cert_params[0] ?? 14);
    $cert_crit = (int)($cert_params[1] ?? 7);

    // Latest days_left per file-based cert (port = 0)
    $stmt = $pdo->query(
        "SELECT c1.host, c1.port, c1.days_left
         FROM cert_stats c1
         INNER JOIN (
             SELECT host, port, MAX(run_at) AS latest
             FROM cert_stats
             WHERE port = 0
             GROUP BY host, port
         ) c2 ON c1.host = c2.host AND c1.port = c2.port AND c1.run_at = c2.latest
         ORDER BY c1.host"
    );
    $cert_current = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($is_updates) {
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date, ROUND(AVG(pending_count)) AS avg_pending
         FROM update_stats
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at)
         ORDER BY date"
    );
    $update_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $update_data[$row['date']] = (int)$row['avg_pending'];
    }
}

if ($is_zombies) {
    $stmt = $pdo->query(
        "SELECT DATE(run_at) AS date,
                ROUND(AVG(zombie_count)) AS avg_count,
                MAX(zombie_count) AS max_count
         FROM zombie_stats
         WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(run_at)
         ORDER BY date"
    );
    $zombie_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $zombie_data[$row['date']] = [
            'avg' => (int)$row['avg_count'],
            'max' => (int)$row['max_count'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - Stats</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="subpage">
    <a href="index.php" class="sticky-back-btn">Back to Dashboard</a>

    <main>
        <div class="chart-widgets">
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2><?php echo htmlspecialchars($title); ?> &mdash; Status Timeline (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="stats" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="statsChart"></canvas>
            </div>

            <?php if ($is_smart): ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>SMART &mdash; Disk Health per Day (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="smartHealth" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="smartHealthChart"></canvas>
            </div>

            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>SMART &mdash; Temperature per Day (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="smartTemp" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="smartTempChart"></canvas>
            </div>
            <?php endif; ?>

            <?php if ($is_disk): ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>Disk Usage per Mount (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="diskUsage" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="diskUsageChart"></canvas>
            </div>
            <?php endif; ?>

            <?php if ($is_cpu): ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>CPU Load Average (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="cpuLoad" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="cpuLoadChart"></canvas>
            </div>
            <?php endif; ?>

            <?php if ($is_ram): ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>RAM Usage (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="ramUsage" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="ramUsageChart"></canvas>
            </div>
            <?php endif; ?>

            <?php if ($is_proc): ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>Process Count (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="procCount" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="procCountChart"></canvas>
            </div>
            <?php endif; ?>

            <?php if ($is_svc): ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>Service Status (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="svcStatus" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="svcStatusChart"></canvas>
            </div>
            <?php endif; ?>

            <?php if ($is_db): ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>MariaDB Total Rows per Database (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="dbSchemaRows" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="dbSchemaRowsChart"></canvas>
            </div>
            <?php foreach ($db_schemas as $schema):
                $jsKey = 'dbTableRows_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $schema);
            ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>MariaDB <?= htmlspecialchars($schema) ?> &mdash; Rows per Table (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="<?= htmlspecialchars($jsKey) ?>" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="<?= htmlspecialchars($jsKey) ?>Chart"></canvas>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($is_updates): ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>Pending Updates (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="pendingUpdates" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="pendingUpdatesChart"></canvas>
            </div>
            <?php endif; ?>

            <?php if ($is_zombies): ?>
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>Zombie Processes (Last 30 days)</h2>
                    <button class="chart-expand-btn" data-chart="zombieCount" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="zombieCountChart"></canvas>
            </div>
            <?php endif; ?>
        </div>

        <?php if (($is_cert || $is_cert_files) && !empty($cert_current)): ?>
        <div class="checks-list">
            <h2>TLS Certificates <span class="checks-subtitle">(current state)</span></h2>
            <div class="status-widgets">
                <?php foreach ($cert_current as $cert):
                    $days = (int)$cert['days_left'];
                    if ($days <= $cert_crit)       { $status = 'ERROR'; $icon = '&#10007;'; }
                    elseif ($days <= $cert_warn)   { $status = 'WARN';  $icon = '&#9888;';  }
                    else                           { $status = 'OK';    $icon = '&#10003;'; }
                    $s = strtolower($status);
                ?>
                <span class="status-widget status-<?= $s ?>">
                    <span class="status-widget-icon"><?= $icon ?></span>
                    <span class="status-widget-title"><?= htmlspecialchars($cert['host']) ?></span>
                    <span class="status-widget-badge"><?= $days ?>d</span>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_svc && !empty($svc_units)): ?>
        <div class="checks-list">
            <h2>Service States <span class="checks-subtitle">(last check)</span></h2>
            <div class="status-widgets">
                <?php foreach ($svc_units as $unit):
                    $state = $unit['state'];
                    $css   = match(true) {
                        $state === 'active'   => 'ok',
                        $state === 'failed'   => 'error',
                        in_array($state, ['activating', 'reloading', 'deactivating']) => 'warn',
                        default               => 'unknown',
                    };
                    $icon  = $state === 'active' ? '&#10003;' : ($state === 'failed' ? '&#10007;' : '?');
                ?>
                <span class="status-widget status-<?= $css ?>">
                    <span class="status-widget-icon"><?= $icon ?></span>
                    <span class="status-widget-title"><?= htmlspecialchars($unit['unit_name']) ?></span>
                    <span class="status-widget-badge"><?= htmlspecialchars($state) ?></span>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <div id="widget-overlay" class="widget-overlay" hidden>
        <div class="widget-overlay-inner">
            <div class="widget-overlay-header">
                <span id="widget-overlay-title" class="widget-overlay-chart-title"></span>
                <button id="widget-overlay-close" class="widget-overlay-close" aria-label="Close">&times;</button>
            </div>
            <canvas id="widget-overlay-canvas"></canvas>
        </div>
    </div>

    <script>
        const dates = [];
        const okData = [], warnData = [], errorData = [], unknownData = [];

        <?php
        $start = date('Y-m-d', strtotime('-29 days'));
        for ($i = 0; $i < 30; $i++) {
            $date = date('Y-m-d', strtotime("$start +$i days"));
            echo "dates.push(" . json_encode($date) . ");\n";
            echo "okData.push("      . ($timeline[$date]['OK']      ?? 0) . ");\n";
            echo "warnData.push("    . ($timeline[$date]['WARN']    ?? 0) . ");\n";
            echo "errorData.push("   . ($timeline[$date]['ERROR']   ?? 0) . ");\n";
            echo "unknownData.push(" . ($timeline[$date]['UNKNOWN'] ?? 0) . ");\n";
        }
        ?>

        const statsChartConfig = {
            type: 'line',
            data: {
                labels: dates,
                datasets: [
                    { label: 'OK',      data: okData,      borderColor: '#28a745', backgroundColor: 'rgba(40,167,69,0.08)',   fill: true },
                    { label: 'WARN',    data: warnData,    borderColor: '#ffc107', backgroundColor: 'rgba(255,193,7,0.08)',   fill: true },
                    { label: 'ERROR',   data: errorData,   borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,0.08)',   fill: true },
                    { label: 'UNKNOWN', data: unknownData, borderColor: '#6c757d', backgroundColor: 'rgba(108,117,125,0.08)', fill: true }
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        };
        new Chart(document.getElementById('statsChart').getContext('2d'), statsChartConfig);

        <?php if ($is_smart): ?>

        // --- SMART health chart (PASSED counts per device per day) ---
        <?php
        $palette = ['#007bff','#fd7e14','#20c997','#e83e8c','#6f42c1','#17a2b8','#ffc107','#dc3545'];
        echo "const smartDates = " . json_encode(array_map(fn($i) => date('Y-m-d', strtotime("$start +$i days")), range(0, 29))) . ";\n";
        echo "const smartHealthDatasets = [];\n";
        foreach ($smart_devices as $idx => $device) {
            $color = $palette[$idx % count($palette)];
            $data = [];
            for ($i = 0; $i < 30; $i++) {
                $date = date('Y-m-d', strtotime("$start +$i days"));
                $passed = $smart_health[$date][$device]['PASSED'] ?? 0;
                $failed = $smart_health[$date][$device]['FAILED'] ?? 0;
                $data[] = ($failed > 0) ? -$failed : $passed;
            }
            echo "smartHealthDatasets.push({ label: " . json_encode($device) . ", data: " . json_encode($data) . ", borderColor: " . json_encode($color) . ", backgroundColor: " . json_encode($color . '22') . ", fill: false });\n";
        }
        ?>

        const smartHealthChartConfig = {
            type: 'bar',
            data: { labels: smartDates, datasets: smartHealthDatasets },
            options: {
                responsive: true,
                plugins: { tooltip: { callbacks: { label: ctx => {
                    const v = ctx.parsed.y;
                    return ctx.dataset.label + ': ' + (v < 0 ? Math.abs(v) + ' FAILED' : v + ' PASSED');
                }}}},
                scales: {
                    y: { ticks: { precision: 0 },
                         title: { display: true, text: 'PASSED (+) / FAILED (-)' } }
                }
            }
        };
        new Chart(document.getElementById('smartHealthChart').getContext('2d'), smartHealthChartConfig);

        // --- SMART metrics chart (avg temperature per device per day) ---
        <?php
        echo "const smartMetricDatasets = [];\n";
        foreach ($smart_metric_devices as $idx => $device) {
            $color = $palette[$idx % count($palette)];
            $data = [];
            for ($i = 0; $i < 30; $i++) {
                $date = date('Y-m-d', strtotime("$start +$i days"));
                $metric = $smart_metric_names[0] ?? 'temp';
                $val = $smart_metrics_data[$date][$device][$metric] ?? 'null';
                $data[] = $val;
            }
            echo "smartMetricDatasets.push({ label: " . json_encode($device) . ", data: " . json_encode($data) . ", borderColor: " . json_encode($color) . ", backgroundColor: " . json_encode($color . '22') . ", fill: false, spanGaps: true });\n";
        }
        $unit = htmlspecialchars($smart_metric_units[$smart_metric_names[0] ?? 'temp'] ?? '');
        ?>

        const smartTempChartConfig = {
            type: 'line',
            data: { labels: smartDates, datasets: smartMetricDatasets },
            options: {
                responsive: true,
                scales: {
                    y: { title: { display: true, text: 'Temperature (<?php echo $unit; ?>)' } }
                }
            }
        };
        new Chart(document.getElementById('smartTempChart').getContext('2d'), smartTempChartConfig);

        <?php endif; ?>

        <?php if ($is_disk): ?>
        // --- Disk usage chart (used % per mountpoint per day) ---
        <?php
        $diskPalette = ['#007bff','#fd7e14','#20c997','#e83e8c','#6f42c1','#17a2b8','#ffc107','#dc3545'];
        echo "const diskDates = " . json_encode(array_map(fn($i) => date('Y-m-d', strtotime("$start +$i days")), range(0, 29))) . ";\n";
        echo "const diskDatasets = [];\n";
        foreach ($disk_mounts as $idx => $mp) {
            $color = $diskPalette[$idx % count($diskPalette)];
            $data = [];
            for ($i = 0; $i < 30; $i++) {
                $date = date('Y-m-d', strtotime("$start +$i days"));
                $used  = $disk_usage[$date][$mp]['used']  ?? null;
                $total = $disk_usage[$date][$mp]['total'] ?? null;
                $pct   = ($total && $used !== null) ? round($used / $total * 100, 1) : null;
                $data[] = $pct;
            }
            echo "diskDatasets.push({ label: " . json_encode($mp) . ", data: " . json_encode($data) . ", borderColor: " . json_encode($color) . ", backgroundColor: " . json_encode($color . '22') . ", fill: false, spanGaps: true });\n";
        }
        ?>

        const diskUsageChartConfig = {
            type: 'line',
            data: { labels: diskDates, datasets: diskDatasets },
            options: {
                responsive: true,
                scales: {
                    y: {
                        min: 0, max: 100,
                        title: { display: true, text: 'Usage (%)' },
                        ticks: { callback: v => v + '%' }
                    }
                },
                plugins: {
                    tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + (ctx.parsed.y !== null ? ctx.parsed.y + '%' : 'N/A') } }
                }
            }
        };
        new Chart(document.getElementById('diskUsageChart').getContext('2d'), diskUsageChartConfig);

        <?php endif; ?>

        <?php if ($is_cpu): ?>
        // --- CPU load average chart ---
        <?php
        echo "const cpuDates = " . json_encode(array_map(fn($i) => date('Y-m-d', strtotime("$start +$i days")), range(0, 29))) . ";\n";
        $load1Data  = array_map(fn($i) => $cpu_data[date('Y-m-d', strtotime("$start +$i days"))]['load1']  ?? null, range(0, 29));
        $load5Data  = array_map(fn($i) => $cpu_data[date('Y-m-d', strtotime("$start +$i days"))]['load5']  ?? null, range(0, 29));
        $load15Data = array_map(fn($i) => $cpu_data[date('Y-m-d', strtotime("$start +$i days"))]['load15'] ?? null, range(0, 29));
        echo "const cpuLoad1  = " . json_encode($load1Data)  . ";\n";
        echo "const cpuLoad5  = " . json_encode($load5Data)  . ";\n";
        echo "const cpuLoad15 = " . json_encode($load15Data) . ";\n";
        ?>

        const cpuLoadChartConfig = {
            type: 'line',
            data: {
                labels: cpuDates,
                datasets: [
                    { label: 'Load 1m',  data: cpuLoad1,  borderColor: '#007bff', backgroundColor: '#007bff22', fill: false, spanGaps: true },
                    { label: 'Load 5m',  data: cpuLoad5,  borderColor: '#fd7e14', backgroundColor: '#fd7e1422', fill: false, spanGaps: true },
                    { label: 'Load 15m', data: cpuLoad15, borderColor: '#20c997', backgroundColor: '#20c99722', fill: false, spanGaps: true },
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Load Average' } } }
            }
        };
        new Chart(document.getElementById('cpuLoadChart').getContext('2d'), cpuLoadChartConfig);

        <?php endif; ?>

        <?php if ($is_ram): ?>
        // --- RAM usage chart ---
        <?php
        echo "const ramDates = " . json_encode(array_map(fn($i) => date('Y-m-d', strtotime("$start +$i days")), range(0, 29))) . ";\n";
        $ramPct = array_map(function($i) use ($start, $ram_data) {
            $date  = date('Y-m-d', strtotime("$start +$i days"));
            $used  = $ram_data[$date]['used']  ?? null;
            $total = $ram_data[$date]['total'] ?? null;
            return ($total && $used !== null) ? round($used / $total * 100, 1) : null;
        }, range(0, 29));
        echo "const ramPct = " . json_encode($ramPct) . ";\n";
        ?>

        const ramUsageChartConfig = {
            type: 'line',
            data: {
                labels: ramDates,
                datasets: [
                    { label: 'RAM Used', data: ramPct, borderColor: '#6f42c1', backgroundColor: '#6f42c122', fill: true, spanGaps: true }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        min: 0, max: 100,
                        title: { display: true, text: 'Usage (%)' },
                        ticks: { callback: v => v + '%' }
                    }
                },
                plugins: { tooltip: { callbacks: { label: ctx => 'RAM Used: ' + ctx.parsed.y + '%' } } }
            }
        };
        new Chart(document.getElementById('ramUsageChart').getContext('2d'), ramUsageChartConfig);

        <?php endif; ?>

        <?php if ($is_proc): ?>
        // --- Process count chart ---
        <?php
        echo "const procDates = " . json_encode(array_map(fn($i) => date('Y-m-d', strtotime("$start +$i days")), range(0, 29))) . ";\n";
        $procAvg = array_map(fn($i) => $proc_data[date('Y-m-d', strtotime("$start +$i days"))]['avg'] ?? null, range(0, 29));
        $procMax = array_map(fn($i) => $proc_data[date('Y-m-d', strtotime("$start +$i days"))]['max'] ?? null, range(0, 29));
        $procMin = array_map(fn($i) => $proc_data[date('Y-m-d', strtotime("$start +$i days"))]['min'] ?? null, range(0, 29));
        echo "const procAvg = " . json_encode($procAvg) . ";\n";
        echo "const procMax = " . json_encode($procMax) . ";\n";
        echo "const procMin = " . json_encode($procMin) . ";\n";
        ?>

        const procCountChartConfig = {
            type: 'line',
            data: {
                labels: procDates,
                datasets: [
                    { label: 'Avg',  data: procAvg, borderColor: '#007bff', backgroundColor: '#007bff22', fill: false, spanGaps: true },
                    { label: 'Max',  data: procMax, borderColor: '#dc3545', backgroundColor: '#dc354522', fill: false, spanGaps: true, borderDash: [4,3] },
                    { label: 'Min',  data: procMin, borderColor: '#28a745', backgroundColor: '#28a74522', fill: false, spanGaps: true, borderDash: [4,3] },
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Process Count' }, ticks: { precision: 0 } } }
            }
        };
        new Chart(document.getElementById('procCountChart').getContext('2d'), procCountChartConfig);

        <?php endif; ?>

        <?php if ($is_svc): ?>
        // --- Service status chart ---
        <?php
        echo "const svcDates = " . json_encode(array_map(fn($i) => date('Y-m-d', strtotime("$start +$i days")), range(0, 29))) . ";\n";
        $svcActive   = array_map(fn($i) => $svc_data[date('Y-m-d', strtotime("$start +$i days"))]['active']   ?? null, range(0, 29));
        $svcInactive = array_map(fn($i) => $svc_data[date('Y-m-d', strtotime("$start +$i days"))]['inactive'] ?? null, range(0, 29));
        $svcFailed   = array_map(fn($i) => $svc_data[date('Y-m-d', strtotime("$start +$i days"))]['failed']   ?? null, range(0, 29));
        echo "const svcActive   = " . json_encode($svcActive)   . ";\n";
        echo "const svcInactive = " . json_encode($svcInactive) . ";\n";
        echo "const svcFailed   = " . json_encode($svcFailed)   . ";\n";
        ?>

        const svcStatusChartConfig = {
            type: 'line',
            data: {
                labels: svcDates,
                datasets: [
                    { label: 'Active',   data: svcActive,   borderColor: '#28a745', backgroundColor: '#28a74522', fill: false, spanGaps: true },
                    { label: 'Inactive', data: svcInactive, borderColor: '#6c757d', backgroundColor: '#6c757d22', fill: false, spanGaps: true, borderDash: [4,3] },
                    { label: 'Failed',   data: svcFailed,   borderColor: '#dc3545', backgroundColor: '#dc354522', fill: false, spanGaps: true },
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Service Count' }, ticks: { precision: 0 } } }
            }
        };
        new Chart(document.getElementById('svcStatusChart').getContext('2d'), svcStatusChartConfig);

        <?php endif; ?>

        <?php if ($is_db): ?>
        // --- MariaDB: total rows per schema per day ---
        <?php
        $dbPalette = ['#007bff','#fd7e14','#20c997','#e83e8c','#6f42c1','#17a2b8','#ffc107','#dc3545','#28a745','#343a40'];
        echo "const dbDates = " . json_encode(array_map(fn($i) => date('Y-m-d', strtotime("$start +$i days")), range(0, 29))) . ";\n";
        echo "const dbSchemaDatasets = [];\n";
        foreach ($db_schemas as $idx => $schema) {
            $color = $dbPalette[$idx % count($dbPalette)];
            $data = array_map(
                fn($i) => $db_schema_data[date('Y-m-d', strtotime("$start +$i days"))][$schema] ?? null,
                range(0, 29)
            );
            echo "dbSchemaDatasets.push({ label: " . json_encode($schema) . ", data: " . json_encode($data) . ", borderColor: " . json_encode($color) . ", backgroundColor: " . json_encode($color . '22') . ", fill: false, spanGaps: true });\n";
        }
        ?>

        const dbSchemaRowsChartConfig = {
            type: 'line',
            data: { labels: dbDates, datasets: dbSchemaDatasets },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Total Rows' }, ticks: { precision: 0 } } }
            }
        };
        new Chart(document.getElementById('dbSchemaRowsChart').getContext('2d'), dbSchemaRowsChartConfig);

        // --- MariaDB: one chart per schema showing rows per table ---
        <?php foreach ($db_schemas as $schema):
            $jsKey  = 'dbTableRows_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $schema);
            $jsVar  = $jsKey . 'ChartConfig';
            $tables = $db_schema_tables[$schema] ?? [];
            echo "const {$jsKey}Datasets = [];\n";
            foreach ($tables as $idx => $tbl) {
                $color = $dbPalette[$idx % count($dbPalette)];
                $data  = array_map(
                    fn($i) => $db_table_data[date('Y-m-d', strtotime("$start +$i days"))][$schema][$tbl] ?? null,
                    range(0, 29)
                );
                echo "{$jsKey}Datasets.push({ label: " . json_encode($tbl) . ", data: " . json_encode($data) . ", borderColor: " . json_encode($color) . ", backgroundColor: " . json_encode($color . '22') . ", fill: false, spanGaps: true });\n";
            }
        ?>
        const <?= $jsVar ?> = {
            type: 'line',
            data: { labels: dbDates, datasets: <?= $jsKey ?>Datasets },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Row Count' }, ticks: { precision: 0 } } }
            }
        };
        new Chart(document.getElementById('<?= $jsKey ?>Chart').getContext('2d'), <?= $jsVar ?>);
        <?php endforeach; ?>

        <?php endif; ?>

        <?php if ($is_updates): ?>
        // --- Pending updates chart ---
        <?php
        echo "const updateDates = " . json_encode(array_map(fn($i) => date('Y-m-d', strtotime("$start +$i days")), range(0, 29))) . ";\n";
        $updateCounts = array_map(fn($i) => $update_data[date('Y-m-d', strtotime("$start +$i days"))] ?? null, range(0, 29));
        echo "const updateCounts = " . json_encode($updateCounts) . ";\n";
        ?>
        const pendingUpdatesChartConfig = {
            type: 'line',
            data: {
                labels: updateDates,
                datasets: [
                    { label: 'Pending Updates', data: updateCounts, borderColor: '#fd7e14', backgroundColor: '#fd7e1422', fill: true, spanGaps: true }
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Package Count' }, ticks: { precision: 0 } } }
            }
        };
        new Chart(document.getElementById('pendingUpdatesChart').getContext('2d'), pendingUpdatesChartConfig);
        <?php endif; ?>

        <?php if ($is_zombies): ?>
        // --- Zombie process count chart ---
        <?php
        echo "const zombieDates = " . json_encode(array_map(fn($i) => date('Y-m-d', strtotime("$start +$i days")), range(0, 29))) . ";\n";
        $zombieAvg = array_map(fn($i) => $zombie_data[date('Y-m-d', strtotime("$start +$i days"))]['avg'] ?? null, range(0, 29));
        $zombieMax = array_map(fn($i) => $zombie_data[date('Y-m-d', strtotime("$start +$i days"))]['max'] ?? null, range(0, 29));
        echo "const zombieAvg = " . json_encode($zombieAvg) . ";\n";
        echo "const zombieMax = " . json_encode($zombieMax) . ";\n";
        ?>
        const zombieCountChartConfig = {
            type: 'line',
            data: {
                labels: zombieDates,
                datasets: [
                    { label: 'Avg', data: zombieAvg, borderColor: '#6f42c1', backgroundColor: '#6f42c122', fill: false, spanGaps: true },
                    { label: 'Max', data: zombieMax, borderColor: '#dc3545', backgroundColor: '#dc354522', fill: false, spanGaps: true, borderDash: [4,3] },
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Zombie Count' }, ticks: { precision: 0 } } }
            }
        };
        new Chart(document.getElementById('zombieCountChart').getContext('2d'), zombieCountChartConfig);
        <?php endif; ?>

        // Chart expand overlay
        const chartConfigs = {
            stats:       { config: statsChartConfig,       title: <?php echo json_encode(htmlspecialchars($title) . ' — Status Timeline (Last 30 days)'); ?> },
            <?php if ($is_smart): ?>
            smartHealth: { config: smartHealthChartConfig, title: 'SMART — Disk Health per Day (Last 30 days)' },
            smartTemp:   { config: smartTempChartConfig,   title: 'SMART — Temperature per Day (Last 30 days)' },
            <?php endif; ?>
            <?php if ($is_disk): ?>
            diskUsage:   { config: diskUsageChartConfig,   title: 'Disk Usage per Mount (Last 30 days)' },
            <?php endif; ?>
            <?php if ($is_cpu): ?>
            cpuLoad:     { config: cpuLoadChartConfig,     title: 'CPU Load Average (Last 30 days)' },
            <?php endif; ?>
            <?php if ($is_ram): ?>
            ramUsage:    { config: ramUsageChartConfig,    title: 'RAM Usage (Last 30 days)' },
            <?php endif; ?>
            <?php if ($is_proc): ?>
            procCount:   { config: procCountChartConfig,   title: 'Process Count (Last 30 days)' },
            <?php endif; ?>
            <?php if ($is_svc): ?>
            svcStatus:   { config: svcStatusChartConfig,   title: 'Service Status (Last 30 days)' },
            <?php endif; ?>
            <?php if ($is_db): ?>
            dbSchemaRows: { config: dbSchemaRowsChartConfig, title: 'MariaDB Total Rows per Database (Last 30 days)' },
            <?php foreach ($db_schemas as $schema):
                $jsKey = 'dbTableRows_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $schema);
                $jsVar = $jsKey . 'ChartConfig';
            ?>
            <?= $jsKey ?>: { config: <?= $jsVar ?>, title: <?= json_encode('MariaDB ' . $schema . ' — Rows per Table (Last 30 days)') ?> },
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($is_updates): ?>
            pendingUpdates: { config: pendingUpdatesChartConfig, title: 'Pending Updates (Last 30 days)' },
            <?php endif; ?>
            <?php if ($is_zombies): ?>
            zombieCount:    { config: zombieCountChartConfig,    title: 'Zombie Processes (Last 30 days)' },
            <?php endif; ?>
        };

        const overlay      = document.getElementById('widget-overlay');
        const overlayTitle = document.getElementById('widget-overlay-title');
        const overlayCanvas = document.getElementById('widget-overlay-canvas');
        const overlayClose = document.getElementById('widget-overlay-close');
        let overlayChartInstance = null;

        document.querySelectorAll('.chart-expand-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const key = btn.dataset.chart;
                const entry = chartConfigs[key];
                if (!entry) return;
                const config = JSON.parse(JSON.stringify(entry.config));
                overlayTitle.textContent = entry.title;

                if (overlayChartInstance) { overlayChartInstance.destroy(); overlayChartInstance = null; }
                overlay.hidden = false;
                document.body.style.overflow = 'hidden';
                overlayChartInstance = new Chart(overlayCanvas.getContext('2d'), config);
            });
        });

        function closeOverlay() {
            overlay.hidden = true;
            document.body.style.overflow = '';
            if (overlayChartInstance) { overlayChartInstance.destroy(); overlayChartInstance = null; }
        }

        overlayClose.addEventListener('click', closeOverlay);
        overlay.addEventListener('click', e => { if (e.target === overlay) closeOverlay(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeOverlay(); });

        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-theme');
        }
    </script>
</body>
</html>
