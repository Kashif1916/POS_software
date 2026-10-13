<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Admin');

$pdo = db();
$currentUser = current_user();
$page_title = 'Users & Team Management';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');

    if ($action === 'add') {
        $username = post_string('username');
        $fullName = post_string('full_name');
        $password = post_string('password');
        $role = post_string('role', 'Operator');
        $status = post_string('status', 'Active');

        if ($username && strlen($password) >= 6) {
            // Check username uniqueness
            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $chk->execute([$username]);
            if ($chk->fetchColumn() > 0) {
                flash('error', "Username '$username' already exists. Choose a different username.");
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hash, $fullName ?: $username, $role, $status]);
                flash('success', "New user '$username' ($role) created successfully!");
            }
        } else {
            flash('error', 'Username and password (at least 6 characters) are required.');
        }
    } elseif ($action === 'edit') {
        $id = post_int('id');
        $fullName = post_string('full_name');
        $role = post_string('role', 'Operator');

        if ($id && $fullName) {
            // Prevent changing own role away from Admin if it's the only admin
            if ($id === $currentUser['id'] && $role !== 'Admin') {
                flash('error', 'You cannot demote yourself from Admin.');
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, role = ? WHERE id = ?");
                $stmt->execute([$fullName, $role, $id]);
                flash('success', 'User profile updated successfully.');
            }
        }
    } elseif ($action === 'reset_password') {
        $id = post_int('id');
        $newPass = post_string('new_password');

        if ($id && strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);
            flash('success', 'Password reset successfully for this user.');
        } else {
            flash('error', 'New password must be at least 6 characters.');
        }
    } elseif ($action === 'toggle_freeze') {
        $id = post_int('id');
        if ($id === $currentUser['id']) {
            flash('error', 'You cannot freeze your own account.');
        } else {
            $stmt = $pdo->prepare("SELECT status, username FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $u = $stmt->fetch();

            if ($u) {
                $newStatus = $u['status'] === 'Active' ? 'Frozen' : 'Active';
                $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
                flash('success', "User '{$u['username']}' is now {$newStatus}.");
            }
        }
    } elseif ($action === 'delete') {
        $id = post_int('id');
        if ($id === $currentUser['id']) {
            flash('error', 'You cannot delete your own account.');
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            flash('success', 'User deleted successfully.');
        }
    }

    redirect('/POS_software/admin/users.php');
}

// Fetch all users
$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();

$totalUsers = count($users);
$totalAdmins = 0;
$totalOperators = 0;
$totalFrozen = 0;

foreach ($users as $u) {
    if ($u['role'] === 'Admin') $totalAdmins++;
    if ($u['role'] === 'Operator') $totalOperators++;
    if ($u['status'] === 'Frozen') $totalFrozen++;
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Metrics Row -->
<div class="stats-row" style="margin-bottom: 20px;">
    <div class="stat-box primary">
        <div class="stat-box-header"><span class="stat-title">Total Users</span></div>
        <div class="stat-value"><?= $totalUsers ?></div>
        <div class="stat-desc">All system logins</div>
    </div>
    <div class="stat-box info">
        <div class="stat-box-header"><span class="stat-title">Cashier Operators</span></div>
        <div class="stat-value text-info"><?= $totalOperators ?></div>
        <div class="stat-desc">Staff POS accounts</div>
    </div>
    <div class="stat-box purple">
        <div class="stat-box-header"><span class="stat-title">Administrators</span></div>
        <div class="stat-value"><?= $totalAdmins ?></div>
        <div class="stat-desc">Full system access</div>
    </div>
    <div class="stat-box danger">
        <div class="stat-box-header"><span class="stat-title">Frozen Accounts</span></div>
        <div class="stat-value text-danger"><?= $totalFrozen ?></div>
        <div class="stat-desc">Blocked from sign in</div>
    </div>
</div>

<!-- Toolbar -->
<div class="filter-bar">
    <div class="search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" data-table-search="[data-user-row]" placeholder="Search users by name, username, role...">
    </div>

    <div>
        <button type="button" class="btn btn-primary" data-modal-open="addUserModal">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            + Create New User
        </button>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <h3>System User Accounts (<?= count($users) ?>)</h3>
        <span class="text-muted" style="font-size: 12px;">Manage passwords, roles, and freeze/unfreeze accounts</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Username</th>
                        <th class="text-center">Role</th>
                        <th class="text-center">Status</th>
                        <th>Created Date</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): 
                        $isSelf = $u['id'] === $currentUser['id'];
                    ?>
                        <tr data-user-row>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="avatar" style="width: 32px; height: 32px; font-size: 13px;">
                                        <?= e(strtoupper(substr($u['username'], 0, 1))) ?>
                                    </div>
                                    <div>
                                        <strong><?= e($u['full_name'] ?? $u['username']) ?></strong>
                                        <?php if ($isSelf): ?>
                                            <span class="badge badge-gray" style="font-size: 10px; margin-left: 4px;">You</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><code><?= e($u['username']) ?></code></td>
                            <td class="text-center">
                                <span class="badge badge-<?= $u['role'] === 'Admin' ? 'primary' : 'info' ?>">
                                    <?= e($u['role']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-<?= $u['status'] === 'Active' ? 'success' : 'danger' ?>">
                                    <?= e($u['status']) ?>
                                </span>
                            </td>
                            <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                            <td class="text-right">
                                <div style="display: inline-flex; gap: 6px;">
                                    <!-- Edit User -->
                                    <button type="button" class="btn btn-sm btn-light" 
                                        onclick="openEditUserModal(<?= $u['id'] ?>, '<?= e(addslashes($u['full_name'] ?? $u['username'])) ?>', '<?= e($u['role']) ?>')">
                                        Edit
                                    </button>

                                    <!-- Reset Password -->
                                    <button type="button" class="btn btn-sm btn-light" 
                                        onclick="openResetPasswordModal(<?= $u['id'] ?>, '<?= e(addslashes($u['username'])) ?>')">
                                        Reset Pass
                                    </button>

                                    <?php if (!$isSelf): ?>
                                        <!-- Freeze / Unfreeze Toggle -->
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="toggle_freeze">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $u['status'] === 'Active' ? 'btn-warning' : 'btn-success' ?>" 
                                                title="<?= $u['status'] === 'Active' ? 'Freeze Account (Block login)' : 'Unfreeze Account (Allow login)' ?>">
                                                <?= $u['status'] === 'Active' ? 'Freeze' : 'Unfreeze' ?>
                                            </button>
                                        </form>

                                        <!-- Delete User -->
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Permanently delete user \'<?= e(addslashes($u['username'])) ?>\'?');">
                                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">✕</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== ADD USER MODAL ==================== -->
<div id="addUserModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create New User Account</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add">

                <div class="form-group">
                    <label>Username (used to login) <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" placeholder="e.g. cashier1" required>
                </div>

                <div class="form-group">
                    <label>Full Display Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" placeholder="e.g. Muhammad Kashif" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Account Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-control" required>
                            <option value="Operator">Operator (POS Cashier, Sales, Returns)</option>
                            <option value="Admin">Admin (Full Control, Reports, Products, Users)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Initial Status</label>
                        <select name="status" class="form-control" required>
                            <option value="Active">Active (Can Login)</option>
                            <option value="Frozen">Frozen (Blocked)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Password (min 6 characters) <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" placeholder="Enter secure password" minlength="6" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Create User Account</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== EDIT USER MODAL ==================== -->
<div id="editUserModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <h3>Edit User Profile</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_user_id">

                <div class="form-group">
                    <label>Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="edit_role" class="form-control" required>
                        <option value="Operator">Operator</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-success">Update User</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== RESET PASSWORD MODAL ==================== -->
<div id="resetPasswordModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <h3>Reset Password for User</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" id="reset_user_id">

                <p style="margin-bottom: 14px;">User: <strong id="reset_username_display"></strong></p>

                <div class="form-group">
                    <label>New Password (min 6 characters) <span class="text-danger">*</span></label>
                    <input type="password" name="new_password" class="form-control" minlength="6" placeholder="Enter new password" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-danger">Set New Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditUserModal(id, fullName, role) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_full_name').value = fullName;
    document.getElementById('edit_role').value = role;
    document.getElementById('editUserModal').classList.add('active');
}

function openResetPasswordModal(id, username) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_username_display').textContent = username;
    document.getElementById('resetPasswordModal').classList.add('active');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
