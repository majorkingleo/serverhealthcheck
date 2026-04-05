<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();

// Get recent health checks using configured titles from checks table
$stmt = $pdo->query("SELECT h.check_name, COALESCE(c.title, h.check_name) AS check_title, h.status, COUNT(*) as count FROM health_checks h INNER JOIN checks c ON c.script_name = h.check_name WHERE h.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND c.enabled = 1 GROUP BY h.check_name, check_title, h.status ORDER BY check_title, h.status");
$checks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Detect timed-out checks: next_run is overdue by more than 5 minutes
$timeout_stmt = $pdo->query(
    "SELECT script_name, COALESCE(title, script_name) AS check_title
     FROM checks
     WHERE enabled = 1
       AND next_run IS NOT NULL
       AND next_run < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
);
$timed_out = [];
foreach ($timeout_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $timed_out[$row['script_name']] = $row['check_title'];
}

// Get status counts for pie chart
$status_counts = ['OK' => 0, 'WARN' => 0, 'ERROR' => 0, 'UNKNOWN' => 0];
foreach ($checks as $check) {
    $status_counts[$check['status']] += $check['count'];
}

// Aggregate to one widget per check (worst status wins); TIMEOUT overrides all
$status_priority = ['ERROR' => 3, 'WARN' => 2, 'UNKNOWN' => 1, 'OK' => 0];
$check_widgets = [];
foreach ($checks as $check) {
    $name = $check['check_name'];
    if (!isset($check_widgets[$name])) {
        $check_widgets[$name] = ['title' => $check['check_title'], 'status' => $check['status']];
    } elseif (($status_priority[$check['status']] ?? 0) > ($status_priority[$check_widgets[$name]['status']] ?? 0)) {
        $check_widgets[$name]['status'] = $check['status'];
    }
}
// Add timed-out checks (may not appear in recent 24h results if they never ran)
foreach ($timed_out as $script_name => $title) {
    if (!isset($check_widgets[$script_name])) {
        $check_widgets[$script_name] = ['title' => $title, 'status' => 'TIMEOUT'];
    } else {
        $check_widgets[$script_name]['status'] = 'TIMEOUT';
    }
}

// Get timeline data for last 7 days
$timeline_stmt = $pdo->query("SELECT DATE(timestamp) as date, status, COUNT(*) as count FROM health_checks WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(timestamp), status ORDER BY date");
$timeline = [];
while ($row = $timeline_stmt->fetch(PDO::FETCH_ASSOC)) {
    $timeline[$row['date']][$row['status']] = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Health Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <header>
        <h1>Server Health Dashboard</h1>
        <div class="header-controls">
            <button id="theme-toggle">Toggle Theme</button>
            <a href="change_password.php">Change Password</a>
            <?php if (!empty($_SESSION['is_admin'])): ?>
                <a href="user_management.php">User Management</a>
                <a href="job_config.php">Job Config</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <main>
        <div class="chart-widgets">
            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>Overall Status (Last 24h)</h2>
                    <button class="chart-expand-btn" data-chart="status" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="statusChart"></canvas>
            </div>

            <div class="chart-container">
                <div class="chart-container-header">
                    <h2>Status Timeline (Last 7 days)</h2>
                    <button class="chart-expand-btn" data-chart="timeline" title="Expand" aria-label="Expand">+</button>
                </div>
                <canvas id="timelineChart"></canvas>
            </div>
        </div>

        <div class="checks-list">
            <h2>Recent Checks <span class="checks-subtitle">(last 24h)</span></h2>
            <div class="status-widgets">
                <?php foreach ($check_widgets as $check_name => $widget): ?>
                    <?php $s = strtolower($widget['status']); ?>
                    <a class="status-widget status-<?php echo $s; ?>" href="stats.php?check=<?php echo urlencode($check_name); ?>">
                        <span class="status-widget-icon"><?php
                            echo $widget['status'] === 'OK'      ? '&#10003;' :
                                ($widget['status'] === 'WARN'    ? '&#9888;'  :
                                ($widget['status'] === 'ERROR'   ? '&#10007;' :
                                ($widget['status'] === 'TIMEOUT' ? '&#8987;'  : '?')));
                        ?></span>
                        <span class="status-widget-title"><?php echo htmlspecialchars($widget['title']); ?></span>
                        <span class="status-widget-badge"><?php echo htmlspecialchars($widget['status']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
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
        // Status Pie Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusChartConfig = {
            type: 'pie',
            data: {
                labels: ['OK', 'WARN', 'ERROR', 'UNKNOWN'],
                datasets: [{
                    data: [<?php echo implode(',', $status_counts); ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d']
                }]
            }
        };
        new Chart(statusCtx, statusChartConfig);

        // Timeline Line Chart
        const timelineCtx = document.getElementById('timelineChart').getContext('2d');
        const dates = [];
        const okData = [], warnData = [], errorData = [], unknownData = [];

        <?php
        $start = date('Y-m-d', strtotime('-7 days'));
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("$start +$i days"));
            echo "dates.push('$date');\n";
            echo "okData.push(" . ($timeline[$date]['OK'] ?? 0) . ");\n";
            echo "warnData.push(" . ($timeline[$date]['WARN'] ?? 0) . ");\n";
            echo "errorData.push(" . ($timeline[$date]['ERROR'] ?? 0) . ");\n";
            echo "unknownData.push(" . ($timeline[$date]['UNKNOWN'] ?? 0) . ");\n";
        }
        ?>

        const timelineChartConfig = {
            type: 'line',
            data: {
                labels: dates,
                datasets: [
                    { label: 'OK', data: okData, borderColor: '#28a745', fill: false },
                    { label: 'WARN', data: warnData, borderColor: '#ffc107', fill: false },
                    { label: 'ERROR', data: errorData, borderColor: '#dc3545', fill: false },
                    { label: 'UNKNOWN', data: unknownData, borderColor: '#6c757d', fill: false }
                ]
            },
            options: {
                scales: {
                    y: { beginAtZero: true }
                }
            }
        };
        new Chart(timelineCtx, timelineChartConfig);

        // Theme toggle
        document.getElementById('theme-toggle').addEventListener('click', () => {
            document.body.classList.toggle('dark-theme');
            localStorage.setItem('theme', document.body.classList.contains('dark-theme') ? 'dark' : 'light');
        });

        // Load saved theme
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-theme');
        }

        // Chart expand overlay
        const overlay = document.getElementById('widget-overlay');
        const overlayTitle = document.getElementById('widget-overlay-title');
        const overlayCanvas = document.getElementById('widget-overlay-canvas');
        const overlayClose = document.getElementById('widget-overlay-close');
        let overlayChartInstance = null;

        document.querySelectorAll('.chart-expand-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const chartType = btn.dataset.chart;
                const config = JSON.parse(JSON.stringify(
                    chartType === 'status' ? statusChartConfig : timelineChartConfig
                ));
                overlayTitle.textContent = chartType === 'status'
                    ? 'Overall Status (Last 24h)'
                    : 'Status Timeline (Last 7 days)';

                if (overlayChartInstance) {
                    overlayChartInstance.destroy();
                    overlayChartInstance = null;
                }

                overlay.hidden = false;
                document.body.style.overflow = 'hidden';
                overlayChartInstance = new Chart(overlayCanvas.getContext('2d'), config);
            });
        });

        function closeOverlay() {
            overlay.hidden = true;
            document.body.style.overflow = '';
            if (overlayChartInstance) {
                overlayChartInstance.destroy();
                overlayChartInstance = null;
            }
        }

        overlayClose.addEventListener('click', closeOverlay);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeOverlay();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeOverlay();
        });
    </script>
</body>
</html>