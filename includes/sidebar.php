<?php
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$user = current_user();
$pdo = db();
$settings = get_store_settings($pdo);
$isMasterUser = is_master();
$isAdminUser = is_admin();

// Fetch live counts for badges
$lowStockBadge = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_alert_limit")->fetchColumn();
$pendingUdharBadge = (int) $pdo->query("SELECT COUNT(*) FROM udhar_ledger WHERE status = 'Pending'")->fetchColumn();

function navActive(string $path, string $currentUri): string {
    return str_contains($currentUri, $path) ? 'active' : '';
}
?>
<aside class="sidebar no-print" style="display: flex !important; flex-direction: column !important; height: 125vh !important; position: sticky; top: 0; overflow: hidden; background: var(--bg-sidebar); border-right: 1px solid var(--border-color); width: 260px; flex-shrink: 0; z-index: 100;">
    
    <!-- Top Header / Logo -->
    <div class="sidebar-header" style="flex-shrink: 0;">
        <a class="brand-link" href="<?= url(in_array($user['role'], ['Admin', 'Master'], true) ? '/admin/dashboard.php' : '/operator/index.php') ?>">
            <div class="brand-icon" style="<?= $isMasterUser ? 'background: linear-gradient(135deg, #7e22ce, #a855f7); color: #fff;' : '' ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
            </div>
            <div>
                <div class="brand-title" style="font-size: 15px; font-weight: 800;"><?= e($settings['store_name']) ?></div>
                <div class="brand-subtitle"><?= e($user['role']) ?> Portal</div>
            </div>
        </a>
    </div>

    <!-- Scrollable Navigation Items -->
    <nav class="sidebar-nav" style="flex: 1 1 auto !important; overflow-y: auto !important; min-height: 0 !important; padding: 16px 12px;">
        <?php if ($isAdminUser): ?>
            
            <?php if ($isMasterUser): ?>
                <div class="nav-section-title" style="color: #7e22ce; font-weight: 800;">👑 Master Super Admin</div>
                <a class="nav-item <?= navActive('/admin/software_lock.php', $currentUri) ?>" href="<?= url('/admin/software_lock.php') ?>" style="background: rgba(126, 34, 206, 0.06); border-left: 3px solid #7e22ce;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color: #7e22ce;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <span style="font-weight: 700; color: #7e22ce;">Software Lock Control</span>
                </a>
                <a class="nav-item <?= navActive('/admin/settings.php', $currentUri) ?>" href="<?= url('/admin/settings.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                <span>Store Settings</span>
            </a>
            <?php endif; ?>

            <div class="nav-section-title">Overview</div>
            <a class="nav-item <?= navActive('/admin/dashboard.php', $currentUri) ?>" href="<?= url('/admin/dashboard.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                <span>Dashboard</span>
            </a>

            <a class="nav-item <?= navActive('/operator/index.php', $currentUri) ?>" href="<?= url('/operator/index.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                <span>POS Terminal</span>
            </a>

            <div class="nav-section-title">Inventory & Supply</div>
            <a class="nav-item <?= navActive('/admin/products.php', $currentUri) ?>" href="<?= url('/admin/products.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                <span>Products & Stock</span>
                <?php if ($lowStockBadge > 0): ?>
                    <span class="nav-badge badge-danger"><?= $lowStockBadge ?> low</span>
                <?php endif; ?>
            </a>

            <a class="nav-item <?= navActive('/admin/dealers.php', $currentUri) ?>" href="<?= url('/admin/dealers.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <span>Dealers & Payables</span>
            </a>

            <div class="nav-section-title">Finance & Billing</div>
            <a class="nav-item <?= navActive('/admin/invoices.php', $currentUri) ?>" href="<?= url('/admin/invoices.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                <span>Sales Invoices</span>
            </a>

            <a class="nav-item <?= navActive('/admin/udhar.php', $currentUri) ?>" href="<?= url('/admin/udhar.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                <span>Udhar Khata</span>
                <?php if ($pendingUdharBadge > 0): ?>
                    <span class="nav-badge badge-warning"><?= $pendingUdharBadge ?></span>
                <?php endif; ?>
            </a>

            <a class="nav-item <?= navActive('/admin/expenses.php', $currentUri) ?>" href="<?= url('/admin/expenses.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>Daily Expenses</span>
            </a>

            <a class="nav-item <?= navActive('/operator/returns.php', $currentUri) ?>" href="<?= url('/operator/returns.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                <span>Returns & Refunds</span>
            </a>

            <div class="nav-section-title">Reports & System</div>
            <a class="nav-item <?= navActive('/operator/analytics.php', $currentUri) ?>" href="<?= url('/operator/analytics.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="M7 15h0M2 9.5h20"></path></svg>
                <span>Sales & Drawer Hisab</span>
            </a>

            <a class="nav-item <?= navActive('/admin/analytics.php', $currentUri) ?>" href="<?= url('/admin/analytics.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                <span>Analytics (Custom Days)</span>
            </a>

            <a class="nav-item <?= navActive('/admin/reports.php', $currentUri) ?>" href="<?= url('/admin/reports.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                <span>Sales Reports</span>
            </a>

            <a class="nav-item <?= navActive('/admin/users.php', $currentUri) ?>" href="<?= url('/admin/users.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                <span>Users Management</span>
            </a>

            

            <a class="nav-item <?= navActive('/profile.php', $currentUri) ?>" href="<?= url('/profile.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <span>My Profile</span>
            </a>
        <?php else: ?>
            <div class="nav-section-title">POS Cashier</div>
            <a class="nav-item <?= navActive('/operator/index.php', $currentUri) ?>" href="<?= url('/operator/index.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                <span>POS Billing</span>
            </a>

            <a class="nav-item <?= navActive('/admin/invoices.php', $currentUri) ?>" href="<?= url('/admin/invoices.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                <span>Recent Invoices</span>
            </a>

            <a class="nav-item <?= navActive('/operator/udhar.php', $currentUri) ?>" href="<?= url('/operator/udhar.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                <span>Udhar Wasooli</span>
                <?php if ($pendingUdharBadge > 0): ?>
                    <span class="nav-badge badge-warning"><?= $pendingUdharBadge ?></span>
                <?php endif; ?>
            </a>

            <a class="nav-item <?= navActive('/operator/returns.php', $currentUri) ?>" href="<?= url('/operator/returns.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                <span>Returns & Refunds</span>
            </a>

            <a class="nav-item <?= navActive('/operator/dealers.php', $currentUri) ?>" href="<?= url('/operator/dealers.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                <span>Pay Dealer</span>
            </a>

            <a class="nav-item <?= navActive('/operator/expenses.php', $currentUri) ?>" href="<?= url('/operator/expenses.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>Record Expense</span>
            </a>

            <div class="nav-section-title">Reports & Hisab</div>
            <a class="nav-item <?= navActive('/operator/analytics.php', $currentUri) ?>" href="<?= url('/operator/analytics.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                <span>Sales & Drawer Hisab</span>
            </a>

            <div class="nav-section-title">My Account</div>
            <a class="nav-item <?= navActive('/profile.php', $currentUri) ?>" href="<?= url('/profile.php') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <span>Change Password</span>
            </a>
        <?php endif; ?>
    </nav>

    <!-- Permanently Fixed User Card at the Absolute Bottom -->
    <div class="sidebar-user" style="flex-shrink: 0 !important; margin-top: auto !important; padding: 16px 18px; border-top: 1px solid var(--border-color); background: #fafbfc; display: flex; align-items: center; justify-content: space-between;">
        <div class="user-meta" style="display: flex; align-items: center; gap: 10px;">
            <div class="avatar" style="<?= $isMasterUser ? 'background: #7e22ce; color: #fff;' : '' ?>">
                <?= e(strtoupper(substr($user['username'], 0, 1))) ?>
            </div>
            <div>
                <div class="user-name"><?= e($user['full_name'] ?? $user['username']) ?></div>
                <div class="user-role" style="<?= $isMasterUser ? 'color: #7e22ce; font-weight: 700;' : '' ?>">
                    <?= $isMasterUser ? '👑 Master' : e($user['role']) ?>
                </div>
            </div>
        </div>
        <a href="<?= url('/logout.php') ?>" class="btn-signout" title="Sign out" onclick="return confirm('Are you sure you want to log out?');">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </a>
    </div>
</aside>