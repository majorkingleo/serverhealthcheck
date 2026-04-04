<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();

// Get recent health checks
$stmt = $pdo->query("SELECT check_name, status, COUNT(*) as count FROM health_checks WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY check_name, status ORDER BY check_name, status");
$checks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status counts for pie chart
$status_counts = ['OK' => 0, 'WARN' => 0, 'ERROR' => 0, 'UNKNOWN' => 0];
foreach ($checks as $check) {
    $status_counts[$check['status']] += $check['count'];
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
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <main>
        <div class="chart-container">
            <h2>Overall Status (Last 24h)</h2>
            <canvas id="statusChart"></canvas>
        </div>

        <div class="chart-container">
            <h2>Status Timeline (Last 7 days)</h2>
            <canvas id="timelineChart"></canvas>
        </div>

        <div class="checks-list">
            <h2>Recent Checks</h2>
            <table>
                <thead>
                    <tr>
                        <th>Check Name</th>
                        <th>Status</th>
                        <th>Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $check): ?>
                        <tr class="status-<?php echo strtolower($check['status']); ?>">
                            <td><?php echo htmlspecialchars($check['check_name']); ?></td>
                            <td><?php echo htmlspecialchars($check['status']); ?></td>
                            <td><?php echo $check['count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        // Status Pie Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: ['OK', 'WARN', 'ERROR', 'UNKNOWN'],
                datasets: [{
                    data: [<?php echo implode(',', $status_counts); ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d']
                }]
            }
        });

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

        new Chart(timelineCtx, {
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
        });

        // Theme toggle
        document.getElementById('theme-toggle').addEventListener('click', () => {
            document.body.classList.toggle('dark-theme');
            localStorage.setItem('theme', document.body.classList.contains('dark-theme') ? 'dark' : 'light');
        });

        // Load saved theme
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-theme');
        }
    </script>
</body>
</html>