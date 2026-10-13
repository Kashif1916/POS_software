<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(); // accessible by Admin and Operator

$pdo = db();
$user = current_user();
$page_title = 'Sales Invoices & Receipts';

// Filters
$modeFilter = $_GET['mode'] ?? '';
$dateFilter = $_GET['date'] ?? '';

$conditions = [];
$params = [];

if ($modeFilter) {
    $conditions[] = 's.payment_mode = ?';
    $params[] = $modeFilter;
}

if ($dateFilter === 'today') {
    $conditions[] = 'DATE(s.created_at) = CURDATE()';
} elseif ($dateFilter === 'month') {
    $conditions[] = 'MONTH(s.created_at) = MONTH(CURDATE()) AND YEAR(s.created_at) = YEAR(CURDATE())';
}

$whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$invoices = $pdo->prepare("SELECT s.*, u.username as operator_name,
    (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as items_count 
    FROM sales s 
    LEFT JOIN users u ON u.id = s.operator_id 
    $whereClause 
    ORDER BY s.id DESC 
    LIMIT 100");
$invoices->execute($params);
$rows = $invoices->fetchAll();

// Overall Metrics
$metrics = $pdo->query("SELECT 
    COUNT(*) as total_count,
    COALESCE(SUM(net_payable), 0) as total_volume,
    COUNT(CASE WHEN payment_mode = 'Cash' THEN 1 END) as cash_count,
    COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN net_payable ELSE 0 END), 0) as cash_vol,
    COUNT(CASE WHEN payment_mode = 'Bank' THEN 1 END) as bank_count,
    COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN net_payable ELSE 0 END), 0) as bank_vol,
    COUNT(CASE WHEN payment_mode = 'Udhar' THEN 1 END) as udhar_count,
    COALESCE(SUM(CASE WHEN payment_mode = 'Udhar' THEN net_payable ELSE 0 END), 0) as udhar_vol,
    COUNT(CASE WHEN status = 'Returned' THEN 1 END) as return_count
    FROM sales")->fetch();

include __DIR__ . '/../includes/header.php';
?>

<!-- Metrics Row -->
<div class="stats-row" style="margin-bottom: 20px;">
    <div class="stat-box primary">
        <div class="stat-box-header"><span class="stat-title">Total Invoices</span></div>
        <div class="stat-value"><?= number_format($metrics['total_count']) ?></div>
        <div class="stat-desc">Vol: Rs <?= money($metrics['total_volume']) ?></div>
    </div>
    <div class="stat-box success">
        <div class="stat-box-header"><span class="stat-title">Cash Sales</span></div>
        <div class="stat-value text-success"><?= $metrics['cash_count'] ?></div>
        <div class="stat-desc">Rs <?= money($metrics['cash_vol']) ?></div>
    </div>
    <div class="stat-box info">
        <div class="stat-box-header"><span class="stat-title">Bank Invoices</span></div>
        <div class="stat-value text-info"><?= $metrics['bank_count'] ?></div>
        <div class="stat-desc">Rs <?= money($metrics['bank_vol']) ?></div>
    </div>
    <div class="stat-box warning">
        <div class="stat-box-header"><span class="stat-title">Udhar (Credit)</span></div>
        <div class="stat-value text-warning"><?= $metrics['udhar_count'] ?></div>
        <div class="stat-desc">Rs <?= money($metrics['udhar_vol']) ?></div>
    </div>
</div>

<!-- Toolbar -->
<div class="filter-bar">
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" data-table-search="[data-invoice-row]" placeholder="Search by Invoice #, Buyer, Phone...">
        </div>

        <div style="display: flex; gap: 6px;">
            <a href="/POS_software/admin/invoices.php" class="btn btn-sm <?= empty($modeFilter) && empty($dateFilter) ? 'btn-primary' : 'btn-light' ?>">All</a>
            <a href="/POS_software/admin/invoices.php?date=today" class="btn btn-sm <?= $dateFilter === 'today' ? 'btn-primary' : 'btn-light' ?>">Today's Invoices</a>
            <a href="/POS_software/admin/invoices.php?mode=Cash" class="btn btn-sm <?= $modeFilter === 'Cash' ? 'btn-success' : 'btn-light' ?>">Cash</a>
            <a href="/POS_software/admin/invoices.php?mode=Bank" class="btn btn-sm <?= $modeFilter === 'Bank' ? 'btn-info' : 'btn-light' ?>">Bank</a>
            <a href="/POS_software/admin/invoices.php?mode=Udhar" class="btn btn-sm <?= $modeFilter === 'Udhar' ? 'btn-warning' : 'btn-light' ?>">Udhar</a>
        </div>
    </div>

    <div>
        <a href="/POS_software/operator/index.php" class="btn btn-primary">
            + New Sale Receipt
        </a>
    </div>
</div>

<!-- Invoices Table -->
<div class="card">
    <div class="card-header">
        <h3>Sales Invoices History (Showing latest <?= count($rows) ?>)</h3>
        <span class="text-muted" style="font-size: 12px;">Click Print to generate thermal or A4 customer receipt</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Buyer Customer</th>
                        <th>Items</th>
                        <th class="text-right">Subtotal</th>
                        <th class="text-right">Discount</th>
                        <th class="text-right">Net Payable</th>
                        <th class="text-center">Mode</th>
                        <th class="text-center">Status</th>
                        <th>Cashier</th>
                        <th>Date & Time</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted" style="padding: 35px;">
                                No sales invoices found matching your filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $inv): ?>
                            <tr data-invoice-row>
                                <td>
                                    <strong style="color: var(--primary);"><?= e($inv['invoice_number']) ?></strong>
                                </td>
                                <td>
                                    <strong><?= e($inv['buyer_name'] ?: 'Walk-in Customer') ?></strong>
                                    <?php if ($inv['buyer_phone']): ?>
                                        <br><small class="text-muted"><?= e($inv['buyer_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-gray"><?= $inv['items_count'] ?> items</span></td>
                                <td class="text-right">Rs <?= money($inv['total_amount']) ?></td>
                                <td class="text-right text-muted">
                                    <?= $inv['bonus_discount'] > 0 ? 'Rs ' . money($inv['bonus_discount']) : '—' ?>
                                </td>
                                <td class="text-right font-bold text-success" style="font-size: 14px;">
                                    Rs <?= money($inv['net_payable']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-<?= $inv['payment_mode'] === 'Cash' ? 'success' : ($inv['payment_mode'] === 'Bank' ? 'info' : 'warning') ?>">
                                        <?= e($inv['payment_mode']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-<?= $inv['status'] === 'Completed' ? 'success' : 'danger' ?>">
                                        <?= e($inv['status']) ?>
                                    </span>
                                </td>
                                <td><?= e($inv['operator_name'] ?? 'System') ?></td>
                                <td><?= date('d M Y, h:i A', strtotime($inv['created_at'])) ?></td>
                                <td class="text-right">
                                    <a href="/POS_software/print_invoice.php?invoice=<?= urlencode($inv['invoice_number']) ?>" target="_blank" class="btn btn-sm btn-light">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                        Print
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
