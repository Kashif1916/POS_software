<?php
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$user = current_user();
$pdo = db();

// Fetch live counts for badges
$lowStockBadge = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_alert_limit")->fetchColumn();
$pendingUdharBadge = (int) $pdo->query("SELECT COUNT(*) FROM udhar_ledger WHERE status = 'Pending'")->fetchColumn();

function navActive(string $path, string $currentUri): string {
    return str_contains($currentUri, $path) ? 'active' : '';
}
?>
<aside class="sidebar no-print">
    <div class="sidebar-header">
        <a class="brand-link" href="<?= $user['role'] === 'Admin' ? '/POS_software/admin/dashboard.php' : '/POS_software/operator/index.php' ?>">
            <div class="brand-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
            </div>
            <div>
                <div class="brand-title">Super POS</div>
                <div class="brand-subtitle"><?= e($user['role']) ?> Portal</div>
            </div>
        </a>
    </div>

    <nav class="sidebar-nav">
        <?php if ($user['role'] === 'Admin'): ?>
            <div class="nav-section-title">Overview</div>
            <a class="nav-item <?= navActive('/admin/dashboard.php', $currentUri) ?>" href="/POS_software/admin/dashboard.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                <span>Dashboard</span>
            </a>

            <a class="nav-item <?= navActive('/operator/index.php', $currentUri) ?>" href="/POS_software/operator/index.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                <span>POS Terminal</span>
            </a>

            <div class="nav-section-title">Inventory & Supply</div>
            <a class="nav-item <?= navActive('/admin/products.php', $currentUri) ?>" href="/POS_software/admin/products.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                <span>Products & Stock</span>
                <?php if ($lowStockBadge > 0): ?>
                    <span class="nav-badge badge-danger"><?= $lowStockBadge ?> low</span>
                <?php endif; ?>
            </a>

            <a class="nav-item <?= navActive('/admin/dealers.php', $currentUri) ?>" href="/POS_software/admin/dealers.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <span>Dealers & Payables</span>
            </a>

            <div class="nav-section-title">Finance & Billing</div>
            <a class="nav-item <?= navActive('/admin/invoices.php', $currentUri) ?>" href="/POS_software/admin/invoices.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                <span>Sales Invoices</span>
            </a>

            <a class="nav-item <?= navActive('/admin/udhar.php', $currentUri) ?>" href="/POS_software/admin/udhar.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                <span>Udhar Khata</span>
                <?php if ($pendingUdharBadge > 0): ?>
                    <span class="nav-badge badge-warning"><?= $pendingUdharBadge ?></span>
                <?php endif; ?>
            </a>

            <a class="nav-item <?= navActive('/admin/expenses.php', $currentUri) ?>" href="/POS_software/admin/expenses.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>Daily Expenses</span>
            </a>

            <a class="nav-item <?= navActive('/operator/returns.php', $currentUri) ?>" href="/POS_software/operator/returns.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                <span>Returns & Refunds</span>
            </a>

            <div class="nav-section-title">Reports & System</div>
            <a class="nav-item <?= navActive('/admin/analytics.php', $currentUri) ?>" href="/POS_software/admin/analytics.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                <span>Analytics (Custom Days)</span>
            </a>

            <a class="nav-item <?= navActive('/admin/reports.php', $currentUri) ?>" href="/POS_software/admin/reports.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                <span>Sales Reports</span>
            </a>

            <a class="nav-item <?= navActive('/admin/users.php', $currentUri) ?>" href="/POS_software/admin/users.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                <span>Users Management</span>
            </a>

            <a class="nav-item <?= navActive('/profile.php', $currentUri) ?>" href="/POS_software/profile.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                <span>My Profile</span>
            </a>
        <?php else: ?>
            <div class="nav-section-title">POS Cashier</div>
            <a class="nav-item <?= navActive('/operator/index.php', $currentUri) ?>" href="/POS_software/operator/index.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                <span>POS Billing</span>
            </a>

            <a class="nav-item <?= navActive('/admin/invoices.php', $currentUri) ?>" href="/POS_software/admin/invoices.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                <span>Recent Invoices</span>
            </a>

            <a class="nav-item <?= navActive('/operator/udhar.php', $currentUri) ?>" href="/POS_software/operator/udhar.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                <span>Udhar Wasooli</span>
                <?php if ($pendingUdharBadge > 0): ?>
                    <span class="nav-badge badge-warning"><?= $pendingUdharBadge ?></span>
                <?php endif; ?>
            </a>

            <a class="nav-item <?= navActive('/operator/returns.php', $currentUri) ?>" href="/POS_software/operator/returns.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                <span>Returns & Refunds</span>
            </a>

            <a class="nav-item <?= navActive('/operator/dealers.php', $currentUri) ?>" href="/POS_software/operator/dealers.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                <span>Pay Dealer</span>
            </a>

            <a class="nav-item <?= navActive('/operator/expenses.php', $currentUri) ?>" href="/POS_software/operator/expenses.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>Record Expense</span>
            </a>

            <div class="nav-section-title">My Account</div>
            <a class="nav-item <?= navActive('/profile.php', $currentUri) ?>" href="/POS_software/profile.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                <span>Change Password</span>
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-user">
        <div class="user-meta">
            <div class="avatar"><?= e(strtoupper(substr($user['username'], 0, 1))) ?></div>
            <div>
                <div class="user-name"><?= e($user['full_name'] ?? $user['username']) ?></div>
                <div class="user-role"><?= e($user['role']) ?></div>
            </div>
        </div>
        <a href="/POS_software/logout.php" class="btn-signout" title="Sign out" onclick="return confirm('Are you sure you want to log out?');">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </a>
    </div>
</aside>
