<?php
require_once 'config.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

error_log('post request: ' . print_r($_POST, true));

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = getDB();
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($username && $password) {
            $stmt = $pdo->prepare("SELECT id, password_hash, is_admin FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $username;
                $_SESSION['is_admin'] = (bool)$user['is_admin'];
                header('Location: index.php');
                exit;
            } else {
                $error = 'Invalid username or password';
            }
        } else {
            $error = 'Please enter username and password';
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
    <title>Login - Server Health</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="login-container">
        <h1>Server Health Monitor</h1>
        <form method="post">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
            <?php if ($error): ?>
                <p class="error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        const errorP = document.querySelector('.error');

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('login.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text();
                }
            })
            .then(html => {
                if (html) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = html;
                    const newError = tempDiv.querySelector('.error');
                    if (newError) {
                        let ep = errorP || form.querySelector('.error');
                        if (!ep) {
                            ep = document.createElement('p');
                            ep.className = 'error';
                            form.appendChild(ep);
                        }
                        ep.textContent = newError.textContent;
                        ep.style.display = 'block';
                    }
                }
            })
            .catch(err => {
                console.error('Error:', err);
                let ep = errorP || form.querySelector('.error');
                if (!ep) {
                    ep = document.createElement('p');
                    ep.className = 'error';
                    form.appendChild(ep);
                }
                ep.textContent = 'An error occurred. Please try again.';
                ep.style.display = 'block';
            });
        });
    });
    </script>
</body>
</html>