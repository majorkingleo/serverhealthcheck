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
        </div>
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
