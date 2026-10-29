<?php
require_once __DIR__ . '/functions.php';
require_login();

$page_title = $page_title ?? 'Dashboard';
$user = current_user();
$flash = take_flash();
$pdo = db();
$settings = get_store_settings($pdo);

// Low stock alert count
$lowStockAlertCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_alert_limit")->fetchColumn();
// Expiry alert count (expired or expiring within 30 days)
$expiryAlertCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE expiry_date IS NOT NULL AND expiry_date <= CURDATE() + INTERVAL 30 DAY")->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> · <?= e($settings['store_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/style.css') ?>">
    <script src="<?= url('/assets/js/chart.min.js') ?>"></script>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-wrapper">
        <header class="topbar no-print">
            <div class="topbar-left">
                <h1 class="page-heading"><?= e($page_title) ?></h1>
            </div>
            <div class="topbar-right">
                <?php if ($lowStockAlertCount > 0): ?>
                    <a href="<?= url('/admin/products.php?filter=low') ?>" class="stock-alert-pill" title="Products running low on stock">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        <span><?= $lowStockAlertCount ?> Low Stock</span>
                    </a>
                <?php endif; ?>

                <?php if ($expiryAlertCount > 0): ?>
                    <a href="<?= url('/admin/products.php?filter=expired') ?>" class="stock-alert-pill" style="background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3;" title="Products expired or expiring within 30 days">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <span><?= $expiryAlertCount ?> Expiry Alert</span>
                    </a>
                <?php endif; ?>

                <div class="time-pill">
                    <?= date('D, d M Y') ?>
                </div>

                <div class="avatar" title="<?= e($user['full_name'] ?? $user['username']) ?>">
                    <?= e(strtoupper(substr($user['username'], 0, 1))) ?>
                </div>
            </div>
        </header>

        <div class="content-area">
            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> no-print">
                    <span><?= e($flash['message']) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-size:16px;">&times;</button>
                </div>
            <?php endif; ?>
