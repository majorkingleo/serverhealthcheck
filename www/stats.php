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
        <div class="chart-container" style="max-width: none; margin: 2rem;">
            <div class="chart-container-header">
                <h2><?php echo htmlspecialchars($title); ?> &mdash; Status Timeline (Last 30 days)</h2>
            </div>
            <canvas id="statsChart"></canvas>
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
                    { label: 'OK',      data: okData,      borderColor: '#28a745', backgroundColor: 'rgba(40,167,69,0.08)',  fill: true },
                    { label: 'WARN',    data: warnData,    borderColor: '#ffc107', backgroundColor: 'rgba(255,193,7,0.08)',  fill: true },
                    { label: 'ERROR',   data: errorData,   borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,0.08)',  fill: true },
                    { label: 'UNKNOWN', data: unknownData, borderColor: '#6c757d', backgroundColor: 'rgba(108,117,125,0.08)', fill: true }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });

        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-theme');
        }
    </script>
</body>
</html>
