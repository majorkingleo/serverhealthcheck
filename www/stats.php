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
            <div class="chart-container" style="max-width: none; flex: 1 1 100%;">
                <div class="chart-container-header">
                    <h2><?php echo htmlspecialchars($title); ?> &mdash; Status Timeline (Last 30 days)</h2>
                </div>
                <canvas id="statsChart"></canvas>
            </div>

            <?php if ($is_smart): ?>
            <div class="chart-container" style="max-width: none; flex: 1 1 100%;">
                <div class="chart-container-header">
                    <h2>SMART &mdash; Disk Health per Day (Last 30 days)</h2>
                </div>
                <canvas id="smartHealthChart"></canvas>
            </div>

            <div class="chart-container" style="max-width: none; flex: 1 1 100%;">
                <div class="chart-container-header">
                    <h2>SMART &mdash; Temperature per Day (Last 30 days)</h2>
                </div>
                <canvas id="smartTempChart"></canvas>
            </div>
            <?php endif; ?>
        </div>
    </main>

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

        new Chart(document.getElementById('statsChart').getContext('2d'), {
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
        });

        <?php if ($is_smart): ?>

        // --- SMART health chart (PASSED counts per device per day) ---
        <?php
        // Palette for devices
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
                // Show PASSED count; negative for failures so they stand out
                $data[] = ($failed > 0) ? -$failed : $passed;
            }
            echo "smartHealthDatasets.push({ label: " . json_encode($device) . ", data: " . json_encode($data) . ", borderColor: " . json_encode($color) . ", backgroundColor: " . json_encode($color . '22') . ", fill: false });\n";
        }
        ?>

        new Chart(document.getElementById('smartHealthChart').getContext('2d'), {
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
        });

        // --- SMART metrics chart (avg temperature per device per day) ---
        <?php
        echo "const smartMetricDatasets = [];\n";
        foreach ($smart_metric_devices as $idx => $device) {
            $color = $palette[$idx % count($palette)];
            $data = [];
            for ($i = 0; $i < 30; $i++) {
                $date = date('Y-m-d', strtotime("$start +$i days"));
                // Use first metric (temp)
                $metric = $smart_metric_names[0] ?? 'temp';
                $val = $smart_metrics_data[$date][$device][$metric] ?? 'null';
                $data[] = $val;
            }
            echo "smartMetricDatasets.push({ label: " . json_encode($device) . ", data: " . json_encode($data) . ", borderColor: " . json_encode($color) . ", backgroundColor: " . json_encode($color . '22') . ", fill: false, spanGaps: true });\n";
        }
        $unit = htmlspecialchars($smart_metric_units[$smart_metric_names[0] ?? 'temp'] ?? '');
        ?>

        new Chart(document.getElementById('smartTempChart').getContext('2d'), {
            type: 'line',
            data: { labels: smartDates, datasets: smartMetricDatasets },
            options: {
                responsive: true,
                scales: {
                    y: { title: { display: true, text: 'Temperature (<?php echo $unit; ?>)' } }
                }
            }
        });

        <?php endif; ?>

        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-theme');
        }
    </script>
</body>
</html>
