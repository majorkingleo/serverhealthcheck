<?php
require_once 'config.php';
requireLogin();

if (empty($_SESSION['is_admin'])) {
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
            $title = trim($_POST['title'] ?? '');
            $interval_minutes = (int)($_POST['interval_minutes'] ?? 5);
            $parameters = trim($_POST['parameters'] ?? '');
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $use_sudo = isset($_POST['sudo']) ? 1 : 0;

            if ($script_name) {
                if ($title === '') {
                    $title = $script_name;
                }
                $stmt = $pdo->prepare("UPDATE checks SET title = ?, interval_minutes = ?, parameters = ?, enabled = ?, sudo = ? WHERE script_name = ?");
                $stmt->execute([$title, $interval_minutes, $parameters, $enabled, $use_sudo, $script_name]);
                $success = 'Configuration updated successfully.';
            }
        } elseif (isset($_POST['delete'])) {
            $script_name = $_POST['script_name'] ?? '';
            if ($script_name) {
                $stmt = $pdo->prepare("DELETE FROM checks WHERE script_name = ?");
                $stmt->execute([$script_name]);
                $success = 'Job deleted successfully.';
            }
        } elseif (isset($_POST['schedule_now'])) {
            $script_name = $_POST['script_name'] ?? '';
            if ($script_name) {
                $stmt = $pdo->prepare("UPDATE checks SET next_run = NOW() WHERE script_name = ?");
                $stmt->execute([$script_name]);
                $success = 'Job scheduled to run now.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}


if (isset($_GET['added']) && $_GET['added'] === '1') {
    $success = 'Job added successfully.';
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
    <style>
        .field-modified {
            background-color: #fff59d;
        }

        .field-locked {
            background-color: #e5e5e5;
            color: #555;
        }

        .field-locked-label {
            background-color: #e5e5e5;
            border-radius: 4px;
            padding: 0.2rem 0.35rem;
        }

        .field-locked[type="checkbox"] {
            accent-color: #9e9e9e;
        }

        .sticky-top-right-btn {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1000;
            display: inline-block;
            padding: 0.6rem 1rem;
            background: #007bff;
            color: white;
            text-decoration: none;
            border: 1px solid #007bff;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .sticky-top-right-btn:hover {
            background: #0056b3;
            border-color: #0056b3;
        }

        .dark-theme .sticky-top-right-btn {
            background: #0056b3;
            border-color: #0056b3;
            color: white;
        }

        .dark-theme .sticky-top-right-btn:hover {
            background: #003d82;
            border-color: #003d82;
        }
    </style>
</head>
<body class="subpage">
    <a href="index.php" class="sticky-back-btn">Back to Dashboard</a>
    <a href="job_add.php" class="sticky-top-right-btn" title="Open Add Job Page" aria-label="Open Add Job Page">Add Job</a>
    <div class="login-container job-config-container">
        <h1>Job Configuration</h1>

        <p>Configure the execution interval (in minutes) and command line parameters for each check script.</p>

        <h2>Existing Jobs</h2>
        <?php foreach ($checks as $check): ?>
            <div class="check-config">
                <h3><?php echo htmlspecialchars($check['script_name']); ?></h3>
                <form method="post" class="job-row-form">
                    <input type="hidden" name="script_name" value="<?php echo htmlspecialchars($check['script_name']); ?>">
                    <div class="job-fields">
                        <label>Title: <input type="text" class="row-editable field-locked" name="title" value="<?php echo htmlspecialchars($check['title'] ?? $check['script_name']); ?>" readonly></label>
                        <label>Interval: <input type="number" class="row-editable field-locked" name="interval_minutes" value="<?php echo $check['interval_minutes']; ?>" min="1" required readonly></label>
                        <label>Params: <input type="text" class="row-editable field-locked" name="parameters" value="<?php echo htmlspecialchars($check['parameters']); ?>" placeholder="e.g., 80 90" readonly></label>
                        <label>Last: <input type="text" class="field-locked" value="<?php echo htmlspecialchars($check['last_run'] ?? ''); ?>" readonly></label>
                        <label>Next: <input type="text" class="field-locked" value="<?php echo htmlspecialchars($check['next_run'] ?? ''); ?>" readonly></label>
                        <label class="field-locked-label"><input type="checkbox" class="row-editable field-locked" name="enabled" <?php echo $check['enabled'] ? 'checked' : ''; ?> disabled> Enabled</label>
                        <label class="field-locked-label"><input type="checkbox" class="row-editable field-locked" name="sudo" <?php echo !empty($check['sudo']) ? 'checked' : ''; ?> disabled> Sudo</label>
                    </div>
                    <div class="job-actions">
                        <button type="button" class="icon-btn icon-edit row-edit-toggle" title="Edit row" aria-label="Edit row">&#9998;</button>
                        <button type="submit" name="update" value="1" class="icon-btn icon-apply" title="Apply changes" aria-label="Apply changes" disabled>&#10003;</button>
                        <button type="submit" name="schedule_now" value="1" class="icon-btn icon-schedule" title="Schedule now" aria-label="Schedule now">&#9200;</button>
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

        function getFieldValue(field) {
            return field.type === 'checkbox' ? String(field.checked) : field.value;
        }

        function updateModifiedState(field) {
            const currentValue = getFieldValue(field);
            const originalValue = field.dataset.originalValue;
            field.classList.toggle('field-modified', currentValue !== originalValue);
        }

        document.querySelectorAll('.job-row-form .row-editable').forEach(field => {
            field.dataset.originalValue = getFieldValue(field);
            updateModifiedState(field);

            field.addEventListener('input', function() {
                updateModifiedState(field);
            });

            field.addEventListener('change', function() {
                updateModifiedState(field);
            });
        });

        document.querySelectorAll('.row-edit-toggle').forEach(button => {
            button.addEventListener('click', function() {
                const form = button.closest('.job-row-form');
                const editableFields = form.querySelectorAll('.row-editable');
                const applyButton = form.querySelector('button[name="update"]');

                editableFields.forEach(field => {
                    if (field.type === 'checkbox') {
                        field.disabled = false;
                        const label = field.closest('label');
                        if (label) {
                            label.classList.remove('field-locked-label');
                        }
                    } else {
                        field.readOnly = false;
                    }
                    field.classList.remove('field-locked');
                    updateModifiedState(field);
                });

                if (applyButton) {
                    applyButton.disabled = false;
                }
                button.disabled = true;
            });
        });

        document.querySelectorAll('.job-row-form').forEach(form => {
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
                    if (errorP) {
                        errorP.textContent = 'An error occurred. Please try again.';
                        errorP.style.display = 'block';
                    }
                    if (successP) {
                        successP.style.display = 'none';
                    }
                });
            });
        });
    });
    </script>
</body>
</html>