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

error_log( "User Management accessed by: " . $_SESSION['username'] );

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log( "Received POST request: " . print_r($_POST, true) );
    try {
        if (isset($_POST['add_user'])) {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($username && $password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
                $stmt->execute([$username, $hash]);
                $success = 'User added successfully.';
            } else {
                $error = 'Please provide username and password.';
            }
        } elseif (isset($_POST['delete_user'])) {
            $user_id = $_POST['user_id'] ?? '';
            if ($user_id && $user_id != $_SESSION['user_id']) {  // Don't delete self
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $success = 'User deleted successfully.';
            } else {
                $error = 'Cannot delete this user.';
            }
        } elseif (isset($_POST['toggle_admin'])) {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $make_admin = isset($_POST['is_admin']) ? 1 : 0;
            if ($user_id && $user_id != $_SESSION['user_id']) {  // Don't change own admin status
                $stmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
                $stmt->execute([$make_admin, $user_id]);
                $success = 'Admin status updated.';
            } else {
                $error = 'Cannot change your own admin status.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Get all users
try {
    $stmt = $pdo->query("SELECT id, username, is_admin FROM users ORDER BY username");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
    $users = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Server Health</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="subpage">
    <a href="index.php" class="sticky-back-btn">Back to Dashboard</a>
    <div class="login-container">
        <h1>User Management</h1>

        <h2>Add New User</h2>
        <form method="post">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" name="add_user" value="1">Add User</button>
        </form>

        <h2>Existing Users</h2>
        <ul>
            <?php foreach ($users as $user): ?>
                <li>
                    <?php echo htmlspecialchars($user['username']); ?>
                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <label style="margin: 0 8px;">
                                <input type="checkbox" name="is_admin" <?php echo $user['is_admin'] ? 'checked' : ''; ?>
                                    onchange="this.form.submit()">
                                Admin
                            </label>
                            <input type="hidden" name="toggle_admin" value="1">
                        </form>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" name="delete_user" onclick="return confirm('Are you sure you want to delete this user?')">Delete</button>
                        </form>
                    <?php else: ?>
                        <span style="margin: 0 8px; color: var(--text-muted, #888);">Admin (you)</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

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
        const userList = document.querySelector('ul');

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const submitter = e.submitter;
                if (submitter && submitter.name) {
                    formData.append(submitter.name, submitter.value || '1');
                }
                
                fetch('user_management.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(html => {
                    if (!html.includes('User Management')) {
                        window.location.href = 'login.php';
                        return;
                    }
                    // Update messages
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = html;
                    const newError = tempDiv.querySelector('.error');
                    const newSuccess = tempDiv.querySelector('.success');
                    const newList = tempDiv.querySelector('ul');
                    
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