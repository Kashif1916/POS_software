<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$user = current_user();
$page_title = 'My Profile & Password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');

    if ($action === 'change_password') {
        $currentPassword = post_string('current_password');
        $newPassword = post_string('new_password');
        $confirmPassword = post_string('confirm_password');

        if (strlen($newPassword) < 6) {
            flash('error', 'New password must be at least 6 characters long.');
        } elseif ($newPassword !== $confirmPassword) {
            flash('error', 'New password and confirmation do not match.');
        } else {
            // Check current password
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $hash = $stmt->fetchColumn();

            if (password_verify($currentPassword, $hash)) {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE users SET password_hash = ?, plain_password = ? WHERE id = ?");
                $update->execute([$newHash, $newPassword, $user['id']]);
                flash('success', 'Your password has been updated successfully!');
            } else {
                flash('error', 'Current password is incorrect.');
            }
        }
    } elseif ($action === 'update_profile') {
        $fullName = post_string('full_name');
        if ($fullName) {
            $update = $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?");
            $update->execute([$fullName, $user['id']]);
            $_SESSION['user']['full_name'] = $fullName;
            flash('success', 'Profile name updated successfully.');
        }
    }

    redirect('/profile.php');
}

// Fetch user fresh info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$userInfo = $stmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<div class="page-container">
    <div class="row">
        <div class="col-6">
            <div class="card">
                <div class="card-header">
                    <h3>User Profile Information</h3>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form-group mb-3">
                            <label>Username</label>
                            <input type="text" class="form-control" value="<?= e($userInfo['username']) ?>" disabled>
                            <small class="text-muted">Username cannot be changed.</small>
                        </div>

                        <div class="form-group mb-3">
                            <label>Role</label>
                            <?php if ($userInfo['role'] === 'Master'): ?>
                                <span class="badge" style="background: #f3e8ff; color: #7e22ce; font-weight: 700;">👑 Master</span>
                            <?php elseif ($userInfo['role'] === 'Admin'): ?>
                                <span class="badge badge-primary">Admin</span>
                            <?php else: ?>
                                <span class="badge badge-info">Operator</span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group mb-3">
                            <label>Full Name / Display Name</label>
                            <input type="text" name="full_name" class="form-control" value="<?= e($userInfo['full_name'] ?? $userInfo['username']) ?>" required>
                        </div>

                        <button type="submit" class="btn btn-primary">Save Profile Name</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-6">
            <div class="card">
                <div class="card-header">
                    <h3>Change Password</h3>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="change_password">

                        <div class="form-group mb-3">
                            <label>Current Password</label>
                            <input type="password" name="current_password" class="form-control" required placeholder="Enter current password">
                        </div>

                        <div class="form-group mb-3">
                            <label>New Password (min 6 characters)</label>
                            <input type="password" name="new_password" class="form-control" minlength="6" required placeholder="Enter new password">
                        </div>

                        <div class="form-group mb-3">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" required placeholder="Re-enter new password">
                        </div>

                        <button type="submit" class="btn btn-success">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
