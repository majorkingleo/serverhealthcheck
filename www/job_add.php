<?php
require_once 'config.php';
requireLogin();

if ($_SESSION['username'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$pdo = getDB();
$error = '';

// Gather available scripts not yet configured
$scripts_dir = __DIR__ . '/../scripts/';
$all_scripts = array_values(array_filter(
    array_map('basename', glob($scripts_dir . 'check_*.py') ?: []),
    fn($f) => is_file($scripts_dir . $f)
));
sort($all_scripts);

$configured = $pdo->query("SELECT script_name FROM checks")->fetchAll(PDO::FETCH_COLUMN);
$available_scripts = array_values(array_diff($all_scripts, $configured));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $script_name = trim($_POST['script_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $interval_minutes = (int)($_POST['interval_minutes'] ?? 5);
        $parameters = trim($_POST['parameters'] ?? '');
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $use_sudo = isset($_POST['sudo']) ? 1 : 0;

        if ($script_name === '') {
            $error = 'Please provide a script name.';
        } else {
            if ($title === '') {
                $title = $script_name;
            }

            $stmt = $pdo->prepare("INSERT INTO checks (script_name, title, interval_minutes, parameters, enabled, sudo) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$script_name, $title, $interval_minutes, $parameters, $enabled, $use_sudo]);

            header('Location: job_config.php?added=1');
            exit;
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Job - Server Health</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="subpage">
    <a href="job_config.php" class="sticky-back-btn">Back to Job Configuration</a>

    <div class="login-container job-config-container">
        <h1>Add New Job</h1>
        <p>Create a new check job and configure its default behavior.</p>

        <form method="post" class="add-job-form">
            <div class="job-fields">
                <label>Script:
                    <?php if ($available_scripts): ?>
                        <select name="script_name" required>
                            <option value="">-- select script --</option>
                            <?php foreach ($available_scripts as $s): ?>
                                <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="hint">All available scripts are already configured.</span>
                        <input type="hidden" name="script_name" value="">
                    <?php endif; ?>
                </label>
                <label>Title: <input type="text" name="title" placeholder="Display title"></label>
                <label>Interval: <input type="number" name="interval_minutes" value="5" min="1" required></label>
                <label>Params: <input type="text" name="parameters" placeholder="e.g., 80 90"></label>
                <label><input type="checkbox" name="enabled" checked> Enabled</label>
                <label><input type="checkbox" name="sudo"> Sudo</label>
            </div>
            <div class="job-actions">
                <button type="submit" class="icon-btn icon-apply" title="Add job" aria-label="Add job">&#10003;</button>
            </div>
        </form>

        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
