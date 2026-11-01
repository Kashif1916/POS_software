<?php
require_once __DIR__ . '/includes/functions.php';

$pdo = db();
$settings = get_store_settings($pdo);
$lock = get_active_software_lock();

// If no lock active, redirect straight away to login or dashboard
if (!$lock) {
    $user = current_user();
    if ($user) {
        redirect(in_array($user['role'], ['Admin', 'Master'], true) ? '/admin/dashboard.php' : '/operator/index.php');
    }
    redirect('/login.php');
}

$error = '';
$activeTab = 'unlock'; // 'unlock' or 'master'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action', 'unlock');

    if ($action === 'unlock') {
        $enteredPass = post_string('unlock_password');
        if ($enteredPass !== '' && hash_equals((string)$lock['unlock_password'], $enteredPass)) {
            // Unmark the lock permanently in Database once correct password is supplied
            $stmt = $pdo->prepare("UPDATE software_locks SET is_unlocked = 1, is_active = 0 WHERE id = ?");
            $stmt->execute([$lock['id']]);

            flash('success', 'Software unlocked successfully!');
            
            $user = current_user();
            if ($user) {
                redirect(in_array($user['role'], ['Admin', 'Master'], true) ? '/admin/dashboard.php' : '/operator/index.php');
            }
            redirect('/login.php');
        } else {
            $error = 'Incorrect unlock password. Please contact administrator.';
            $activeTab = 'unlock';
        }
    } elseif ($action === 'master_login') {
        $username = post_string('master_username');
        $password = post_string('master_password');
        $activeTab = 'master';

        if ($username && $password) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'Master' LIMIT 1");
            $stmt->execute([$username]);
            $masterUser = $stmt->fetch();

            if ($masterUser && password_verify($password, $masterUser['password_hash'])) {
                if ($masterUser['status'] === 'Frozen') {
                    $error = 'Master account is frozen.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'id' => (int) $masterUser['id'],
                        'username' => $masterUser['username'],
                        'full_name' => $masterUser['full_name'] ?? $masterUser['username'],
                        'role' => 'Master',
                    ];
                    flash('success', 'Welcome Master! You have full system access.');
                    redirect('/admin/software_lock.php');
                }
            } else {
                $error = 'Invalid Master username or password.';
            }
        } else {
            $error = 'Please enter Master username and password.';
        }
    }
}

$lockMessage = trim((string)($lock['lock_message'] ?? ''));
if ($lockMessage === '') {
    $lockMessage = 'Software access is locked. Please enter the authorized unlock password to resume operations.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Software Locked · <?= e($settings['store_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/style.css') ?>">
    <style>
        .lock-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 24px;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .lock-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4);
            width: 100%;
            max-width: 480px;
            padding: 36px 32px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .lock-top-bar {
            height: 6px;
            background: linear-gradient(90deg, #ef4444, #f59e0b, #ef4444);
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
        }
        .lock-icon-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #fee2e2;
            color: #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px rgba(220, 38, 38, 0.2);
            animation: pulse-ring 2.5s infinite;
        }
        @keyframes pulse-ring {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 16px rgba(220, 38, 38, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
        }
        .store-pill {
            display: inline-block;
            background: #f1f5f9;
            color: #475569;
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }
        .lock-title {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 8px 0;
        }
        .lock-date-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .custom-message-box {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 14px 16px;
            text-align: left;
            margin-bottom: 24px;
            font-size: 13.5px;
            line-height: 1.5;
            color: #78350f;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        }
        .custom-message-box strong {
            display: block;
            margin-bottom: 4px;
            color: #92400e;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .nav-tabs-lock {
            display: flex;
            background: #f1f5f9;
            border-radius: 10px;
            padding: 4px;
            margin-bottom: 20px;
        }
        .nav-tabs-lock button {
            flex: 1;
            padding: 8px 12px;
            border: none;
            background: transparent;
            font-weight: 700;
            font-size: 12.5px;
            border-radius: 8px;
            cursor: pointer;
            color: #64748b;
            transition: all 0.2s;
        }
        .nav-tabs-lock button.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        .lock-input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1.5px solid #cbd5e1;
            font-size: 14px;
            transition: all 0.2s;
            box-sizing: border-box;
            margin-bottom: 14px;
        }
        .lock-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .btn-unlock {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            background: #dc2626;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-unlock:hover {
            background: #b91c1c;
        }
        .btn-master-login {
            background: #4f46e5;
        }
        .btn-master-login:hover {
            background: #4338ca;
        }
        .support-info {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid #f1f5f9;
            font-size: 12.5px;
            color: #64748b;
        }
    </style>
</head>
<body class="lock-wrapper">
    <div class="lock-card">
        <div class="lock-top-bar"></div>

        <div class="lock-icon-circle">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>

        <div class="store-pill"><?= e($settings['store_name']) ?></div>
        <h1 class="lock-title">Software Access Locked</h1>
        
        <div class="lock-date-badge">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <span>Triggered on <?= date('d M Y', strtotime($lock['lock_date'])) ?></span>
        </div>

        <div class="custom-message-box">
            <strong>Notice / پیغام:</strong>
            <?= nl2br(e($lockMessage)) ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 16px; text-align: left; font-size: 13px;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <div class="nav-tabs-lock">
            <button type="button" id="tabBtnUnlock" class="<?= $activeTab === 'unlock' ? 'active' : '' ?>" onclick="switchLockTab('unlock')">
                🔑 Unlock Password
            </button>
            <button type="button" id="tabBtnMaster" class="<?= $activeTab === 'master' ? 'active' : '' ?>" onclick="switchLockTab('master')">
                🛡️ Master Login
            </button>
        </div>

        <form method="post" id="formUnlock" style="display: <?= $activeTab === 'unlock' ? 'block' : 'none' ?>;">
            <input type="hidden" name="action" value="unlock">
            <div style="text-align: left; margin-bottom: 6px;">
                <label style="font-size: 12.5px; font-weight: 700; color: #475569;">Unlock Password:</label>
            </div>
            <input type="password" name="unlock_password" class="lock-input" placeholder="Enter password to unlock software..." required autofocus>
            <button type="submit" class="btn-unlock">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Unlock Software Access
            </button>
        </form>

        <form method="post" id="formMaster" style="display: <?= $activeTab === 'master' ? 'block' : 'none' ?>;">
            <input type="hidden" name="action" value="master_login">
            <div style="text-align: left; margin-bottom: 6px;">
                <label style="font-size: 12.5px; font-weight: 700; color: #475569;">Master Username:</label>
            </div>
            <input type="text" name="master_username" class="lock-input" placeholder="Master username" required>
            
            <div style="text-align: left; margin-bottom: 6px;">
                <label style="font-size: 12.5px; font-weight: 700; color: #475569;">Master Password:</label>
            </div>
            <input type="password" name="master_password" class="lock-input" placeholder="Master password" required>

            <button type="submit" class="btn-unlock btn-master-login">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                Sign In as Master
            </button>
        </form>

        <div class="support-info">
            For assistance, contact: 03180711280 </strong><br>
            KJ Software House Khushab
        </div>
    </div>

    <script>
        function switchLockTab(tab) {
            const formUnlock = document.getElementById('formUnlock');
            const formMaster = document.getElementById('formMaster');
            const tabBtnUnlock = document.getElementById('tabBtnUnlock');
            const tabBtnMaster = document.getElementById('tabBtnMaster');

            if (tab === 'unlock') {
                formUnlock.style.display = 'block';
                formMaster.style.display = 'none';
                tabBtnUnlock.classList.add('active');
                tabBtnMaster.classList.remove('active');
            } else {
                formUnlock.style.display = 'none';
                formMaster.style.display = 'block';
                tabBtnUnlock.classList.remove('active');
                tabBtnMaster.classList.add('active');
            }
        }
    </script>
</body>
</html>