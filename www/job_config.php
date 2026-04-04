<?php
require_once 'config.php';
requireLogin();

if ($_SESSION['username'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$pdo = getDB();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update'])) {
            $script_name = $_POST['script_name'] ?? '';
            $interval_minutes = (int)($_POST['interval_minutes'] ?? 5);
            $parameters = trim($_POST['parameters'] ?? '');
            $target_table = trim($_POST['target_table'] ?? 'health_checks');
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            if ($script_name) {
                $stmt = $pdo->prepare("UPDATE checks SET interval_minutes = ?, parameters = ?, target_table = ?, enabled = ? WHERE script_name = ?");
                $stmt->execute([$interval_minutes, $parameters, $target_table, $enabled, $script_name]);
                $success = 'Configuration updated successfully.';
            }
        } elseif (isset($_POST['add'])) {
            $script_name = trim($_POST['new_script_name'] ?? '');
            $interval_minutes = (int)($_POST['new_interval_minutes'] ?? 5);
            $parameters = trim($_POST['new_parameters'] ?? '');
            $target_table = trim($_POST['new_target_table'] ?? 'health_checks');
            $enabled = isset($_POST['new_enabled']) ? 1 : 0;

            if ($script_name) {
                $stmt = $pdo->prepare("INSERT INTO checks (script_name, interval_minutes, parameters, target_table, enabled) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$script_name, $interval_minutes, $parameters, $target_table, $enabled]);
                $success = 'Job added successfully.';
            } else {
                $error = 'Please provide a script name.';
            }
        } elseif (isset($_POST['delete'])) {
            $script_name = $_POST['script_name'] ?? '';
            if ($script_name) {
                $stmt = $pdo->prepare("DELETE FROM checks WHERE script_name = ?");
                $stmt->execute([$script_name]);
                $success = 'Job deleted successfully.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Get all checks
try {
    $stmt = $pdo->query("SELECT * FROM checks ORDER BY script_name");
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
    $checks = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Configuration - Server Health</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="subpage">
    <a href="index.php" class="sticky-back-btn">Back to Dashboard</a>
    <div class="login-container" style="max-width: 800px;">
        <h1>Job Configuration</h1>

        <p>Configure the execution interval (in minutes) and command line parameters for each check script.</p>

        <h2>Add New Job</h2>
        <form method="post" class="add-job-form">
            <label>Script Name: <input type="text" name="new_script_name" placeholder="e.g., check_new.py" required></label>
            <label>Interval (minutes): <input type="number" name="new_interval_minutes" value="5" min="1" required></label>
            <label>Parameters: <input type="text" name="new_parameters" placeholder="e.g., 80 90"></label>
            <label>Target Table: <input type="text" name="new_target_table" value="health_checks"></label>
            <label><input type="checkbox" name="new_enabled" checked> Enabled</label>
            <button type="submit" name="add">Add Job</button>
        </form>

        <h2>Existing Jobs</h2>
        <?php foreach ($checks as $check): ?>
            <div class="check-config">
                <h3><?php echo htmlspecialchars($check['script_name']); ?></h3>
                <form method="post" class="job-row-form">
                    <input type="hidden" name="script_name" value="<?php echo htmlspecialchars($check['script_name']); ?>">
                    <div class="job-fields">
                        <label>Interval: <input type="number" name="interval_minutes" value="<?php echo $check['interval_minutes']; ?>" min="1" required></label>
                        <label>Params: <input type="text" name="parameters" value="<?php echo htmlspecialchars($check['parameters']); ?>" placeholder="e.g., 80 90"></label>
                        <label>Table: <input type="text" name="target_table" value="<?php echo htmlspecialchars($check['target_table']); ?>"></label>
                        <label><input type="checkbox" name="enabled" <?php echo $check['enabled'] ? 'checked' : ''; ?>> Enabled</label>
                    </div>
                    <div class="job-actions">
                        <button type="submit" name="update" value="1" class="icon-btn icon-update" title="Update job" aria-label="Update job">&#9998;</button>
                        <button type="submit" name="delete" value="1" class="icon-btn icon-delete" title="Delete job" aria-label="Delete job" onclick="return confirm('Are you sure you want to delete this job?')">&#128465;</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>

        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const errorP = document.querySelector('.error');
        const successP = document.querySelector('.success');
        const container = document.querySelector('.login-container');

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const submitter = e.submitter;
                if (submitter && submitter.name) {
                    formData.append(submitter.name, submitter.value || '1');
                }
                
                fetch('job_config.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(html => {
                    if (!html.includes('Job Configuration')) {
                        window.location.href = 'login.php';
                        return;
                    }
                    // Update messages and content
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = html;
                    const newError = tempDiv.querySelector('.error');
                    const newSuccess = tempDiv.querySelector('.success');
                    const newContainer = tempDiv.querySelector('.login-container');
                    
                    if (newSuccess) {
                        // Reload to show updated list
                        location.reload();
                    } else if (newError) {
                        errorP.textContent = newError.textContent;
                        errorP.style.display = 'block';
                        successP.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    errorP.textContent = 'An error occurred. Please try again.';
                    errorP.style.display = 'block';
                    successP.style.display = 'none';
                });
            });
        });
    });
    </script>
</body>
</html>