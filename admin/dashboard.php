<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Admin');

$pdo = db();
$page_title = 'Admin Dashboard';
$stats = today_stats($pdo);

// 7-day sales trend data
$trendQuery = $pdo->query("SELECT 
    DATE(created_at) as sale_date,
    COALESCE(SUM(net_payable), 0) as total_amount
    FROM sales 
    WHERE status = 'Completed' AND created_at >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(created_at)
    ORDER BY sale_date ASC")->fetchAll();

$chartLabels = [];
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('d M', strtotime($d));
    $found = 0;
    foreach ($trendQuery as $row) {
        if ($row['sale_date'] === $d) {
            $found = (float) $row['total_amount'];
            break;
        }
    }
    $chartData[] = $found;
}

// Payment method breakdown today
$payBreakdown = [
    'Cash' => $stats['cash_sales'],
    'Bank' => $stats['bank_sales'],
    'Udhar' => $stats['udhar_sales']
];

// Low stock items list
$lowStockProducts = $pdo->query("SELECT p.*, c.name as category_name, d.name as dealer_name 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    LEFT JOIN dealers d ON d.id = p.dealer_id 
    WHERE p.stock_quantity <= p.min_alert_limit 
    ORDER BY p.stock_quantity ASC 
    LIMIT 6")->fetchAll();

// Recent sales
$recentSales = $pdo->query("SELECT s.*, u.username as operator_name 
    FROM sales s 
    LEFT JOIN users u ON u.id = s.operator_id 
    ORDER BY s.id DESC 
    LIMIT 8")->fetchAll();

// Expiry alerts (expired or expiring in next 30 days)
$expiryAlertItems = $pdo->query("SELECT p.*, c.name as category_name, DATEDIFF(p.expiry_date, CURDATE()) as days_left 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    WHERE p.expiry_date IS NOT NULL AND p.expiry_date <= CURDATE() + INTERVAL 30 DAY 
    ORDER BY p.expiry_date ASC 
    LIMIT 5")->fetchAll();
$expiryAlertTotal = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE expiry_date IS NOT NULL AND expiry_date <= CURDATE() + INTERVAL 30 DAY")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<!-- Low Stock Alert Banner -->
<?php if ($stats['low_stock_count'] > 0): ?>
    <div class="card" style="border-left: 4px solid var(--danger); background: #fffdfd; margin-bottom: 14px;">
        <div class="card-body" style="padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span class="badge badge-danger" style="font-size: 13px; padding: 6px 12px;">⚠️ Stock Alert</span>
                <strong><?= $stats['low_stock_count'] ?> product(s) are low in stock (below threshold limit).</strong>
            </div>
            <a href="<?= url('/admin/products.php?filter=low') ?>" class="btn btn-sm btn-danger">View Low Stock Items</a>
        </div>
    </div>
<?php endif; ?>

<!-- Expiry Alert Banner -->
<?php if ($expiryAlertTotal > 0): ?>
    <div class="card" style="border-left: 4px solid #e11d48; background: #fff5f5; margin-bottom: 20px;">
        <div class="card-body" style="padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span class="badge" style="background: #e11d48; color: #fff; font-size: 13px; padding: 6px 12px;">📅 Expiry Alert</span>
                <strong><?= $expiryAlertTotal ?> product(s) are already expired or expiring within 30 days!</strong>
            </div>
            <a href="<?= url('/admin/products.php?filter=expired') ?>" class="btn btn-sm" style="background: #e11d48; color: #fff;">View Expiring Products</a>
        </div>
    </div>
<?php endif; ?>

<!-- Quick Shortcuts Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="font-size: 18px; font-weight: 800; color: var(--text-primary);">Today's Overview & Shortcuts</h2>
        <p class="text-muted" style="font-size: 12.5px;">Live real-time summary for <?= date('d F Y') ?></p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="<?= url('/operator/index.php') ?>" class="btn btn-primary btn-sm">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
            + POS Sale
        </a>
        <a href="<?= url('/admin/products.php') ?>" class="btn btn-light btn-sm">+ Product</a>
        <a href="<?= url('/admin/dealers.php') ?>" class="btn btn-light btn-sm">Pay Dealer</a>
        <a href="<?= url('/admin/expenses.php') ?>" class="btn btn-light btn-sm">+ Expense</a>
    </div>
</div>

<!-- Shortcuts & Metrics Cards Grid -->
<div class="stats-row">
    <!-- 1. Total Sales -->
    <div class="stat-box primary">
        <div class="stat-box-header">
            <span class="stat-title">Today's Total Sale</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
        </div>
        <div class="stat-value">Rs <?= money($stats['total_sales']) ?></div>
        <div class="stat-desc">
            <span>Cash: <strong>Rs <?= money($stats['cash_sales']) ?></strong></span> · 
            <span>Bank: <strong>Rs <?= money($stats['bank_sales']) ?></strong></span>
        </div>
    </div>

    <!-- 2. Cash In Drawer -->
    <div class="stat-box success">
        <div class="stat-box-header">
            <span class="stat-title">Cash in Drawer</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="M7 15h0M2 9.5h20"></path></svg>
            </div>
        </div>
        <div class="stat-value text-success">Rs <?= money($stats['drawer_cash']) ?></div>
        <div class="stat-desc">Physical cash available in drawer</div>
    </div>

    <!-- 3. Net Profit Today -->
    <div class="stat-box emerald">
        <div class="stat-box-header">
            <span class="stat-title">Net Profit Today</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
            </div>
        </div>
        <div class="stat-value" style="color: #16a34a;">Rs <?= money($stats['net_profit']) ?></div>
        <div class="stat-desc">Sales revenue minus COGS & expenses</div>
    </div>

    <!-- 4. Today's Udhar Given -->
    <div class="stat-box warning">
        <div class="stat-box-header">
            <span class="stat-title">Today's Udhar (Credit)</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            </div>
        </div>
        <div class="stat-value text-warning">Rs <?= money($stats['udhar_sales']) ?></div>
        <div class="stat-desc">Recovered today: Rs <?= money($stats['udhar_recovered']) ?></div>
    </div>

    <!-- 5. Dealer Payments Today -->
    <div class="stat-box purple">
        <div class="stat-box-header">
            <span class="stat-title">Dealer Payments</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            </div>
        </div>
        <div class="stat-value">Rs <?= money($stats['dealer_paid']) ?></div>
        <div class="stat-desc">Paid to suppliers today</div>
    </div>

    <!-- 6. Today's Expenses -->
    <div class="stat-box danger">
        <div class="stat-box-header">
            <span class="stat-title">Today's Expenses</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>
        </div>
        <div class="stat-value text-danger">Rs <?= money($stats['total_expense']) ?></div>
        <div class="stat-desc">Petty cash & bills recorded</div>
    </div>

    <!-- 7. Today's Returns -->
    <div class="stat-box info">
        <div class="stat-box-header">
            <span class="stat-title">Returns / Refunds</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
            </div>
        </div>
        <div class="stat-value"><?= $stats['returns_count'] ?> <span style="font-size: 14px; font-weight: 600;">Items</span></div>
        <div class="stat-desc">Refunded: Rs <?= money($stats['total_refund']) ?></div>
    </div>

    <!-- 8. Discounts / Bonus -->
    <div class="stat-box primary">
        <div class="stat-box-header">
            <span class="stat-title">Bonus / Discount</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
            </div>
        </div>
        <div class="stat-value">Rs <?= money($stats['total_discounts']) ?></div>
        <div class="stat-desc">Discounts given by cashier</div>
    </div>
</div>

<!-- Charts Section (Fixed Grid Layout to prevent chart overflow) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; width: 100%; box-sizing: border-box;">
    <div class="card" style="margin-bottom: 0; min-width: 0;">
        <div class="card-header">
            <h3>Sales Revenue Trend (Last 7 Days)</h3>
            <span class="badge badge-primary">Completed Sales</span>
        </div>
        <div class="card-body">
            <div style="position: relative; height: 260px; width: 100%; min-width: 0;">
                <canvas id="salesTrendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0; min-width: 0;">
        <div class="card-header">
            <h3>Today's Payment Modes</h3>
            <span class="badge badge-gray"><?= $stats['invoice_count'] ?> Invoices</span>
        </div>
        <div class="card-body">
            <div style="position: relative; height: 260px; width: 100%; min-width: 0;">
                <canvas id="paymentModeChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Low Stock & Recent Sales Rows -->
<div class="row" style="margin-top: 24px;">
    <?php if ($lowStockProducts): ?>
    <div class="col-4">
        <div class="card">
            <div class="card-header">
                <h3>Low Stock Alert</h3>
                <a href="<?= url('/admin/products.php?filter=low') ?>" class="btn btn-sm btn-light">Manage All</a>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStockProducts as $lp): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($lp['name']) ?></strong><br>
                                        <small class="text-muted"><?= e($lp['category_name'] ?? 'General') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-danger"><?= $lp['stock_quantity'] ?> left</span>
                                    </td>
                                    <td>
                                        <a href="<?= url('/admin/products.php?edit_id=' . $lp['id']) ?>" class="btn btn-sm btn-light">Restock</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $lowStockProducts ? 'col-8' : 'col-12' ?>">
        <div class="card">
            <div class="card-header">
                <h3>Recent Sales Invoices</h3>
                <a href="<?= url('/admin/invoices.php') ?>" class="btn btn-sm btn-light">View All Invoices</a>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Buyer</th>
                                <th>Net Payable</th>
                                <th>Mode</th>
                                <th>Time</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentSales)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted" style="padding: 30px;">
                                        No sales recorded yet today. Click <strong>+ POS Sale</strong> to create an invoice.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentSales as $sale): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= url('/print_invoice.php?invoice=' . urlencode($sale['invoice_number'])) ?>" target="_blank" style="color: var(--primary); font-weight: 700;">
                                                <?= e($sale['invoice_number']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <strong><?= e($sale['buyer_name'] ?: 'Walk-in Customer') ?></strong>
                                            <?php if ($sale['buyer_phone']): ?>
                                                <br><small class="text-muted"><?= e($sale['buyer_phone']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong>Rs <?= money($sale['net_payable']) ?></strong></td>
                                        <td>
                                            <span class="badge badge-<?= $sale['payment_mode'] === 'Cash' ? 'success' : ($sale['payment_mode'] === 'Bank' ? 'info' : 'warning') ?>">
                                                <?= e($sale['payment_mode']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('d M, h:i A', strtotime($sale['created_at'])) ?></td>
                                        <td class="text-right">
                                            <a href="<?= url('/print_invoice.php?invoice=' . urlencode($sale['invoice_number'])) ?>" target="_blank" class="btn btn-sm btn-light" title="Print Receipt">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
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
    </div>
</div>

<script>
if (window.Chart) {
    Chart.defaults.devicePixelRatio = window.devicePixelRatio || 2;
}

// Sales Trend Chart
const ctxTrend = document.getElementById('salesTrendChart').getContext('2d');
new Chart(ctxTrend, {
    type: 'line',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'Sales Revenue (Rs)',
            data: <?= json_encode($chartData) ?>,
            borderColor: '#22c55e',
            backgroundColor: 'rgba(34, 197, 94, 0.08)',
            borderWidth: 2.5,
            fill: true,
            tension: 0.35,
            pointBackgroundColor: '#22c55e',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: {
                    color: '#64748b',
                    font: { weight: '600' },
                    callback: (value) => 'Rs ' + value.toLocaleString()
                }
            },
            x: {
                grid: { display: false },
                ticks: {
                    color: '#64748b',
                    font: { weight: '600' }
                }
            }
        }
    }
});

// Payment Mode Donut Chart
const ctxPay = document.getElementById('paymentModeChart').getContext('2d');
const payData = <?= json_encode(array_values($payBreakdown)) ?>;
const hasSales = payData.some(v => v > 0);

new Chart(ctxPay, {
    type: 'doughnut',
    data: {
        labels: ['Cash', 'Bank', 'Udhar (Credit)'],
        datasets: [{
            data: hasSales ? payData : [1, 0, 0],
            backgroundColor: hasSales ? ['#22c55e', '#0284c7', '#f59e0b'] : ['#e2e8f0', '#e2e8f0', '#e2e8f0'],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth: 12, padding: 16, font: { weight: '700', size: 12 } }
            }
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>