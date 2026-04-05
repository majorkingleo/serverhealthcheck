<?php
require_once 'config.php';
requireLogin();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = getDB();
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($current_password && $new_password && $confirm_password) {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($current_password, $user['password_hash'])) {
                if ($new_password === $confirm_password) {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $update_stmt->execute([$new_hash, $_SESSION['user_id']]);
                    $success = 'Password changed successfully.';
                } else {
                    $error = 'New passwords do not match.';
                }
            } else {
                $error = 'Current password is incorrect.';
            }
        } else {
            $error = 'Please fill in all fields.';
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
    <title>Change Password - Server Health</title>
    <link rel="icon" href="favicon.php" type="image/svg+xml">
    <link rel="stylesheet" href="style.css">
</head>
<body class="subpage">
    <a href="index.php" class="sticky-back-btn">Back to Dashboard</a>
    <div class="login-container">
        <h1>Change Password</h1>
        <form method="post">
            <input type="password" name="current_password" placeholder="Current Password" required>
            <input type="password" name="new_password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
            <button type="submit">Change Password</button>
            <?php if ($error): ?>
                <p class="error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <?php if ($success): ?>
                <p class="success"><?php echo htmlspecialchars($success); ?></p>
            <?php endif; ?>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        const errorP = document.querySelector('.error');
        const successP = document.querySelector('.success');

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('change_password.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                if (!html.includes('Change Password')) {
                    window.location.href = 'login.php';
                    return;
                }
                // Check for success/error in response
                if (html.includes('Password changed successfully')) {
                    successP.textContent = 'Password changed successfully.';
                    successP.style.display = 'block';
                    errorP.style.display = 'none';
                    form.reset();
                } else if (html.includes('class="error"')) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = html;
                    const newError = tempDiv.querySelector('.error');
                    if (newError) {
                        errorP.textContent = newError.textContent;
                        errorP.style.display = 'block';
                        successP.style.display = 'none';
                    }
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
    </script>
</body>
</html>