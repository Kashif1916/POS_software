<?php
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    redirect(current_user()['role'] === 'Admin' ? '/POS_software/admin/dashboard.php' : '/POS_software/operator/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = post_string('username');
    $password = post_string('password');

    if ($username && $password) {
        $stmt = db()->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['status'] === 'Frozen') {
                $error = 'Your account has been frozen by an administrator. Please contact your manager.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'] ?? $user['username'],
                    'role' => $user['role'],
                ];
                redirect($user['role'] === 'Admin' ? '/POS_software/admin/dashboard.php' : '/POS_software/operator/index.php');
            }
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        $error = 'Please enter both username and password.';
    }
}

$flash = take_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In · QuickPOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/POS_software/assets/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-brand">
                <div class="brand-badge-lg">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                </div>
                <h2>Super POS Mart</h2>
                <p>Point of Sale & Inventory Management</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="auth-form">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter username (e.g. admin)" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Sign In to POS</button>
            </form>

            <div class="demo-logins">
                <p class="demo-title">Default Login Credentials:</p>
                <div class="demo-badges">
                    <div class="demo-box">
                        <strong>Admin:</strong> <code>admin</code> / <code>admin123</code>
                    </div>
                    <div class="demo-box">
                        <strong>Operator:</strong> <code>operator</code> / <code>operator123</code>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
