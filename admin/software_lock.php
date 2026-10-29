<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Master');

$pdo = db();
$currentUser = current_user();
$page_title = 'Master Software Lock Control';
$today = date('Y-m-d');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');

    if ($action === 'add_locks') {
        $mode = post_string('date_mode', 'single'); // 'single' or 'range'
        $password = post_string('unlock_password');
        $message = post_string('lock_message');

        if ($password === '') {
            flash('error', 'Unlock password is required.');
        } else {
            $dates = [];
            if ($mode === 'range') {
                $startDate = post_string('start_date');
                $endDate = post_string('end_date');

                if (!$startDate || !$endDate || $startDate > $endDate) {
                    flash('error', 'Please provide a valid start and end date range.');
                } else {
                    $curr = new DateTime($startDate);
                    $end = new DateTime($endDate);
                    // Cap at 180 days to avoid infinite loop
                    $count = 0;
                    while ($curr <= $end && $count < 180) {
                        $dates[] = $curr->format('Y-m-d');
                        $curr->modify('+1 day');
                        $count++;
                    }
                }
            } else {
                $singleDate = post_string('single_date');
                if (!$singleDate) {
                    flash('error', 'Please select a lock date.');
                } else {
                    $dates[] = $singleDate;
                }
            }

            if (!empty($dates)) {
                $stmt = $pdo->prepare("INSERT INTO software_locks (lock_date, unlock_password, lock_message, is_active) 
                    VALUES (?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE unlock_password = VALUES(unlock_password), lock_message = VALUES(lock_message), is_active = 1");
                
                foreach ($dates as $d) {
                    $stmt->execute([$d, $password, $message]);
                }

                $cnt = count($dates);
                flash('success', "Successfully scheduled software lock for $cnt date(s) with password '$password'!");
            }
        }
    } elseif ($action === 'toggle_lock') {
        $id = post_int('id');
        $stmt = $pdo->prepare("SELECT is_active FROM software_locks WHERE id = ?");
        $stmt->execute([$id]);
        $currStatus = $stmt->fetchColumn();

        if ($currStatus !== false) {
            $newStatus = $currStatus ? 0 : 1;
            $pdo->prepare("UPDATE software_locks SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);
            flash('success', 'Lock status updated successfully.');
        }
    } elseif ($action === 'edit_lock') {
        $id = post_int('id');
        $password = post_string('unlock_password');
        $message = post_string('lock_message');
        $isActive = post_int('is_active', 1);

        if ($id && $password !== '') {
            $stmt = $pdo->prepare("UPDATE software_locks SET unlock_password = ?, lock_message = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$password, $message, $isActive, $id]);
            flash('success', 'Scheduled lock updated successfully.');
        } else {
            flash('error', 'Unlock password cannot be empty.');
        }
    } elseif ($action === 'delete_lock') {
        $id = post_int('id');
        if ($id) {
            $pdo->prepare("DELETE FROM software_locks WHERE id = ?")->execute([$id]);
            flash('success', 'Scheduled lock removed successfully.');
        }
    } elseif ($action === 'quick_lock_today') {
        $password = post_string('quick_password', 'unlock123');
        $message = post_string('quick_message', 'Software access locked today. Please contact administrator.');
        
        $stmt = $pdo->prepare("INSERT INTO software_locks (lock_date, unlock_password, lock_message, is_active) 
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE unlock_password = VALUES(unlock_password), lock_message = VALUES(lock_message), is_active = 1");
        $stmt->execute([$today, $password, $message]);
        
        flash('success', "Emergency lock activated for today ($today) with password '$password'.");
    } elseif ($action === 'quick_unlock_today') {
        $pdo->prepare("UPDATE software_locks SET is_active = 0 WHERE lock_date = ?")->execute([$today]);
        flash('success', "Lock deactivated for today ($today). Software is now accessible to everyone.");
    }

    redirect('/admin/software_lock.php');
}

// Fetch all locks ordered by lock_date DESC
$locks = $pdo->query("SELECT * FROM software_locks ORDER BY lock_date DESC")->fetchAll();

// Check today's status
$todayLock = null;
foreach ($locks as $l) {
    if ($l['lock_date'] === $today && $l['is_active'] == 1) {
        $todayLock = $l;
        break;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Master Software Lock Control Page -->
<div class="page-container">

    <!-- Status Banner for Today -->
    <?php if ($todayLock): ?>
        <div style="background: linear-gradient(135deg, #fef2f2, #fee2e2); border: 2px solid #ef4444; border-radius: 14px; padding: 20px 24px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 52px; height: 52px; border-radius: 50%; background: #dc2626; color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                </div>
                <div>
                    <h3 style="color: #991b1b; margin: 0 0 4px 0; font-size: 18px;">Software is Currently LOCKED Today! (<?= date('d M Y') ?>)</h3>
                    <div style="font-size: 13.5px; color: #7f1d1d;">
                        Active Unlock Password: <strong style="font-family: monospace; font-size: 15px; background: #fff; padding: 2px 8px; border-radius: 6px; border: 1px solid #fca5a5;"><?= e($todayLock['unlock_password']) ?></strong>
                        <span style="margin-left: 10px; color: #555;">Message: "<?= e(substr($todayLock['lock_message'] ?: 'Default Notice', 0, 60)) ?>"</span>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="<?= url('/lock.php') ?>" target="_blank" class="btn btn-light" style="border: 1px solid #f87171; color: #b91c1c;">
                    Preview Lock Screen
                </a>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="quick_unlock_today">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Immediately disable software lock for today?');">
                        ✓ Disable Lock for Today
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 2px solid #22c55e; border-radius: 14px; padding: 20px 24px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 52px; height: 52px; border-radius: 50%; background: #16a34a; color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>
                </div>
                <div>
                    <h3 style="color: #166534; margin: 0 0 4px 0; font-size: 18px;">Software is UNLOCKED & Open Today (<?= date('d M Y') ?>)</h3>
                    <div style="font-size: 13.5px; color: #15803d;">
                        Staff and cashiers have normal unrestricted access to the POS software.
                    </div>
                </div>
            </div>
            <div>
                <button type="button" class="btn btn-danger" data-modal-open="quickLockModal">
                    🔒 Emergency Lock Today
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Two-column grid -->
    <div style="display: grid; grid-template-columns: 1fr 1.6fr; gap: 24px; align-items: start;">

        <!-- Left Form: Schedule Locks -->
        <div class="card">
            <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                <h3 style="margin: 0; font-size: 16px; color: #0f172a;">📅 Schedule Software Lockout</h3>
                <span class="text-muted" style="font-size: 12px;">Lock software on specific date or multi-day range</span>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="add_locks">

                    <!-- Mode Toggle -->
                    <div class="form-group">
                        <label>Date Selection Mode</label>
                        <div style="display: flex; gap: 10px;">
                            <label style="flex: 1; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px;" id="lblModeSingle">
                                <input type="radio" name="date_mode" value="single" checked onchange="toggleDateMode('single')">
                                Single Specific Date
                            </label>
                            <label style="flex: 1; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px;" id="lblModeRange">
                                <input type="radio" name="date_mode" value="range" onchange="toggleDateMode('range')">
                                Multiple Days (Range)
                            </label>
                        </div>
                    </div>

                    <!-- Single Date Input -->
                    <div class="form-group" id="groupSingleDate">
                        <label>Select Lock Date <span class="text-danger">*</span></label>
                        <input type="date" name="single_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        <small class="text-muted">Software will lock on this specific calendar date.</small>
                    </div>

                    <!-- Date Range Inputs -->
                    <div class="form-row" id="groupRangeDate" style="display: none;">
                        <div class="form-group">
                            <label>From (Start Date) <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label>To (End Date) <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                        </div>
                        <small class="text-muted" style="width: 100%; margin-top: -8px; margin-bottom: 12px;">Every day in this range will be locked with the password below.</small>
                    </div>

                    <!-- Unlock Password -->
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label style="margin: 0;">Unlock Password <span class="text-danger">*</span></label>
                            <button type="button" class="btn btn-sm btn-light" onclick="generateRandomPass('sched_pass')" style="font-size: 11px; padding: 2px 8px;">
                                ⚡ Generate Random
                            </button>
                        </div>
                        <div style="position: relative;">
                            <input type="text" name="unlock_password" id="sched_pass" class="form-control" placeholder="e.g. key9824 or octLock2026" required style="font-family: monospace; font-size: 14px; font-weight: 700; letter-spacing: 1px;">
                        </div>
                        <small class="text-muted">Required password to reopen software on the locked date(s).</small>
                    </div>

                    <!-- Custom Message -->
                    <div class="form-group">
                        <label>Custom Lock Message (Shown to Users)</label>
                        <textarea name="lock_message" id="sched_msg" class="form-control" rows="3" placeholder="Enter message to display on the lock screen (e.g. Monthly maintenance lock. Contact master at 0309-7107977 for password.)"></textarea>
                        
                        <!-- Quick Template Buttons -->
                        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                            <button type="button" class="btn btn-sm btn-light" style="font-size: 11px;" onclick="setTemplateMsg('Software access has expired. Please contact support at 0309-7107977 to get the unlock code.')">
                                + Fee / Subscription Due
                            </button>
                            <button type="button" class="btn btn-sm btn-light" style="font-size: 11px;" onclick="setTemplateMsg('Scheduled software maintenance is in progress. Enter the unlock password to resume billing.')">
                                + Maintenance Notice
                            </button>
                            <button type="button" class="btn btn-sm btn-light" style="font-size: 11px;" onclick="setTemplateMsg('System verification required by Master Administrator. Please provide today\'s authorized security code.')">
                                + Security Check
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" style="padding: 12px; font-weight: 700; font-size: 14px;">
                        🔒 Save & Apply Lock Schedule
                    </button>
                </form>
            </div>
        </div>

        <!-- Right Table: Scheduled Locks -->
        <div class="card">
            <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; color: #0f172a;">📋 Scheduled Software Locks (<?= count($locks) ?>)</h3>
                    <span class="text-muted" style="font-size: 12px;">Active and upcoming lock dates with plain unlock passwords</span>
                </div>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($locks)): ?>
                    <div style="padding: 40px; text-align: center; color: #94a3b8;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 12px; opacity: 0.6;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <p style="font-size: 14px; margin: 0;">No scheduled software locks found.</p>
                        <p style="font-size: 12px; margin-top: 4px;">Use the form on the left to schedule a lock for any date or month.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table" style="margin: 0;">
                            <thead>
                                <tr>
                                    <th>Lock Date</th>
                                    <th>Unlock Password</th>
                                    <th>Custom Message</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($locks as $l): 
                                    $isToday = ($l['lock_date'] === $today);
                                    $isPast = ($l['lock_date'] < $today);
                                ?>
                                    <tr style="<?= $isToday ? 'background: #fff5f5;' : '' ?>">
                                        <td>
                                            <div style="font-weight: 700; color: #0f172a;">
                                                <?= date('D, d M Y', strtotime($l['lock_date'])) ?>
                                            </div>
                                            <div>
                                                <?php if ($isToday): ?>
                                                    <span class="badge badge-danger" style="font-size: 10px;">🔴 TODAY</span>
                                                <?php elseif ($isPast): ?>
                                                    <span class="badge badge-gray" style="font-size: 10px;">Past Date</span>
                                                <?php else: ?>
                                                    <span class="badge badge-info" style="font-size: 10px;">Upcoming</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <code id="pass_<?= $l['id'] ?>" style="background: #f1f5f9; padding: 4px 8px; border-radius: 6px; font-weight: 700; color: #0f172a; font-size: 13px;">
                                                    <?= e($l['unlock_password']) ?>
                                                </code>
                                                <button type="button" class="btn btn-sm btn-light" style="padding: 2px 6px; font-size: 11px;" title="Copy password" onclick="copyText('<?= e(addslashes($l['unlock_password'])) ?>')">
                                                    📋
                                                </button>
                                            </div>
                                        </td>
                                        <td style="max-width: 220px;">
                                            <div style="font-size: 12.5px; color: #475569; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($l['lock_message'] ?: 'Default Notice') ?>">
                                                <?= e($l['lock_message'] ?: 'Default Notice') ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="toggle_lock">
                                                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                <button type="submit" class="btn btn-sm <?= $l['is_active'] ? 'btn-success' : 'btn-light' ?>" style="font-size: 11px; padding: 3px 8px;" title="Click to toggle status">
                                                    <?= $l['is_active'] ? 'Active' : 'Disabled' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-right">
                                            <div style="display: inline-flex; gap: 6px;">
                                                <!-- Edit button -->
                                                <button type="button" class="btn btn-sm btn-light" onclick="openEditLockModal(<?= $l['id'] ?>, '<?= e($l['lock_date']) ?>', '<?= e(addslashes($l['unlock_password'])) ?>', '<?= e(addslashes($l['lock_message'] ?? '')) ?>', <?= (int)$l['is_active'] ?>)">
                                                    Edit
                                                </button>

                                                <!-- Delete button -->
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Permanently remove this lock for <?= $l['lock_date'] ?>?');">
                                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="delete_lock">
                                                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete lock">✕</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- ==================== QUICK LOCK TODAY MODAL ==================== -->
<div id="quickLockModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <h3>🔒 Immediate Emergency Lock for Today</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="quick_lock_today">

                <p style="font-size: 13.5px; color: #475569; margin-top: 0;">
                    This will instantly lock the software for everyone today (<strong><?= date('d M Y') ?></strong>). Only entering this password will permit access.
                </p>

                <div class="form-group">
                    <label>Unlock Password <span class="text-danger">*</span></label>
                    <input type="text" name="quick_password" class="form-control" value="lock<?= rand(100, 999) ?>" required style="font-family: monospace; font-size: 15px; font-weight: 700;">
                </div>

                <div class="form-group">
                    <label>Custom Lock Message</label>
                    <input type="text" name="quick_message" class="form-control" value="Software access temporarily locked. Contact Master for code.">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-danger">🔒 Lock Software Right Now</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== EDIT LOCK MODAL ==================== -->
<div id="editLockModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 480px;">
        <div class="modal-header">
            <h3>Edit Scheduled Lock</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="edit_lock">
                <input type="hidden" name="id" id="edit_lock_id">

                <div class="form-group">
                    <label>Lock Date</label>
                    <input type="text" id="edit_lock_date" class="form-control" readonly style="background: #f1f5f9; font-weight: 700;">
                </div>

                <div class="form-group">
                    <label>Unlock Password <span class="text-danger">*</span></label>
                    <input type="text" name="unlock_password" id="edit_unlock_password" class="form-control" required style="font-family: monospace; font-size: 14px; font-weight: 700;">
                </div>

                <div class="form-group">
                    <label>Custom Lock Message</label>
                    <textarea name="lock_message" id="edit_lock_message" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label>Lock Status</label>
                    <select name="is_active" id="edit_is_active" class="form-control">
                        <option value="1">Active (Software is Locked on this date)</option>
                        <option value="0">Disabled (Lock Suspended)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDateMode(mode) {
    const singleGrp = document.getElementById('groupSingleDate');
    const rangeGrp = document.getElementById('groupRangeDate');
    const lblSingle = document.getElementById('lblModeSingle');
    const lblRange = document.getElementById('lblModeRange');

    if (mode === 'single') {
        singleGrp.style.display = 'block';
        rangeGrp.style.display = 'none';
        lblSingle.style.borderColor = '#2563eb';
        lblRange.style.borderColor = '#cbd5e1';
    } else {
        singleGrp.style.display = 'none';
        rangeGrp.style.display = 'flex';
        lblRange.style.borderColor = '#2563eb';
        lblSingle.style.borderColor = '#cbd5e1';
    }
}

function generateRandomPass(elementId) {
    const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    let pass = '';
    for (let i = 0; i < 6; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById(elementId).value = pass;
}

function setTemplateMsg(text) {
    document.getElementById('sched_msg').value = text;
}

function copyText(str) {
    navigator.clipboard.writeText(str).then(() => {
        alert('Unlock password "' + str + '" copied to clipboard!');
    }).catch(() => {
        prompt('Copy password:', str);
    });
}

function openEditLockModal(id, date, pass, msg, active) {
    document.getElementById('edit_lock_id').value = id;
    document.getElementById('edit_lock_date').value = date;
    document.getElementById('edit_unlock_password').value = pass;
    document.getElementById('edit_lock_message').value = msg;
    document.getElementById('edit_is_active').value = active;
    
    const modal = document.getElementById('editLockModal');
    if (modal) modal.classList.add('active');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
