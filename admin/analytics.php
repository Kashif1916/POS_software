<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Admin');

$pdo = db();
$page_title = 'Financial & Business Analytics';

// Date range selection (defaults to last 7 days)
$preset = $_GET['preset'] ?? '';
$today = date('Y-m-d');

if ($preset === 'today') {
    $startDate = $today;
    $endDate = $today;
} elseif ($preset === 'yesterday') {
    $startDate = date('Y-m-d', strtotime('-1 day'));
    $endDate = date('Y-m-d', strtotime('-1 day'));
} elseif ($preset === '3days') {
    $startDate = date('Y-m-d', strtotime('-2 days'));
    $endDate = $today;
} elseif ($preset === '7days') {
    $startDate = date('Y-m-d', strtotime('-6 days'));
    $endDate = $today;
} elseif ($preset === '30days') {
    $startDate = date('Y-m-d', strtotime('-29 days'));
    $endDate = $today;
} elseif ($preset === 'this_month') {
    $startDate = date('Y-m-01');
    $endDate = $today;
} elseif ($preset === 'last_month') {
    $startDate = date('Y-m-01', strtotime('first day of last month'));
    $endDate = date('Y-m-t', strtotime('last day of last month'));
} else {
    // Custom date range or default to last 7 days
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-6 days'));
    $endDate = $_GET['end_date'] ?? $today;
}

// Validate dates
if ($startDate > $endDate) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

// 1. Sales & Revenue Metrics for selected range
$salesStmt = $pdo->prepare("SELECT 
    COUNT(CASE WHEN status = 'Completed' THEN 1 END) as total_invoices,
    COALESCE(SUM(CASE WHEN status = 'Completed' THEN net_payable ELSE 0 END), 0) as total_sales,
    COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Cash' THEN net_payable ELSE 0 END), 0) as cash_sales,
    COUNT(CASE WHEN status = 'Completed' AND payment_mode = 'Cash' THEN 1 END) as cash_count,
    COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Bank' THEN net_payable ELSE 0 END), 0) as bank_sales,
    COUNT(CASE WHEN status = 'Completed' AND payment_mode = 'Bank' THEN 1 END) as bank_count,
    COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Udhar' THEN net_payable ELSE 0 END), 0) as udhar_sales,
    COUNT(CASE WHEN status = 'Completed' AND payment_mode = 'Udhar' THEN 1 END) as udhar_count,
    COALESCE(SUM(CASE WHEN status = 'Completed' THEN bonus_discount ELSE 0 END), 0) as total_discounts
    FROM sales 
    WHERE DATE(created_at) BETWEEN ? AND ?");
$salesStmt->execute([$startDate, $endDate]);
$salesData = $salesStmt->fetch();

// 2. Cost of Goods Sold (COGS) for selected range
$cogsStmt = $pdo->prepare("SELECT 
    COALESCE(SUM(si.cost_price * si.quantity), 0) as total_cogs
    FROM sale_items si 
    JOIN sales s ON s.id = si.sale_id 
    WHERE s.status = 'Completed' AND DATE(s.created_at) BETWEEN ? AND ?");
$cogsStmt->execute([$startDate, $endDate]);
$totalCogs = (float) $cogsStmt->fetchColumn();

// 3. Customer Udhar Collected (Wasooli) in this range
$udharStmt = $pdo->prepare("SELECT 
    COALESCE(SUM(amount_paid), 0) as total_recovered,
    COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount_paid ELSE 0 END), 0) as cash_recovered,
    COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN amount_paid ELSE 0 END), 0) as bank_recovered
    FROM udhar_payments 
    WHERE date BETWEEN ? AND ?");
$udharStmt->execute([$startDate, $endDate]);
$udharData = $udharStmt->fetch();

// 4. Dealer Payments Made in this range
$dealerStmt = $pdo->prepare("SELECT 
    COALESCE(SUM(paid_amount), 0) as total_dealer_paid,
    COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN paid_amount ELSE 0 END), 0) as cash_dealer_paid,
    COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN paid_amount ELSE 0 END), 0) as bank_dealer_paid
    FROM dealer_payments 
    WHERE date BETWEEN ? AND ?");
$dealerStmt->execute([$startDate, $endDate]);
$dealerData = $dealerStmt->fetch();

// 5. Operating Expenses in this range
$expStmt = $pdo->prepare("SELECT 
    COALESCE(SUM(amount), 0) as total_expenses,
    COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount ELSE 0 END), 0) as cash_expenses,
    COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN amount ELSE 0 END), 0) as bank_expenses
    FROM expenses 
    WHERE date BETWEEN ? AND ?");
$expStmt->execute([$startDate, $endDate]);
$expData = $expStmt->fetch();

// 6. Returns & Refunds in this range
$retStmt = $pdo->prepare("SELECT 
    COUNT(*) as return_count,
    COALESCE(SUM(refund_amount), 0) as total_refunds
    FROM returns 
    WHERE date BETWEEN ? AND ?");
$retStmt->execute([$startDate, $endDate]);
$retData = $retStmt->fetch();

// Profits calculation
$grossProfit = (float) $salesData['total_sales'] - $totalCogs;
$netProfit = $grossProfit - (float) $expData['total_expenses'] - (float) $retData['total_refunds'];

// Physical Cash Flow in drawer during this period
$cashInflow = (float) $salesData['cash_sales'] + (float) $udharData['cash_recovered'];
$cashOutflow = (float) $dealerData['cash_dealer_paid'] + (float) $expData['cash_expenses'] + (float) $retData['total_refunds'];
$netCashFlow = $cashInflow - $cashOutflow;

// 7. Day-by-Day performance table & chart data
$daysQuery = $pdo->prepare("SELECT 
    DATE(s.created_at) as day_date,
    COUNT(*) as invoices_count,
    SUM(s.net_payable) as day_sales,
    SUM(CASE WHEN s.payment_mode = 'Cash' THEN s.net_payable ELSE 0 END) as day_cash,
    SUM(CASE WHEN s.payment_mode = 'Bank' THEN s.net_payable ELSE 0 END) as day_bank,
    SUM(CASE WHEN s.payment_mode = 'Udhar' THEN s.net_payable ELSE 0 END) as day_udhar,
    COALESCE((SELECT SUM(si.cost_price * si.quantity) FROM sale_items si JOIN sales s2 ON s2.id = si.sale_id WHERE s2.status = 'Completed' AND DATE(s2.created_at) = DATE(s.created_at)), 0) as day_cost,
    COALESCE((SELECT SUM(amount) FROM expenses WHERE date = DATE(s.created_at)), 0) as day_expenses,
    COALESCE((SELECT SUM(refund_amount) FROM returns WHERE date = DATE(s.created_at)), 0) as day_refunds
    FROM sales s 
    WHERE s.status = 'Completed' AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY DATE(s.created_at)
    ORDER BY day_date ASC");
$daysQuery->execute([$startDate, $endDate]);
$daysList = $daysQuery->fetchAll();

$chartDays = [];
$chartSales = [];
$chartProfits = [];

foreach ($daysList as $d) {
    $chartDays[] = date('d M', strtotime($d['day_date']));
    $chartSales[] = (float) $d['day_sales'];
    $dGross = (float) $d['day_sales'] - (float) $d['day_cost'];
    $chartProfits[] = $dGross - (float) $d['day_expenses'] - (float) $d['day_refunds'];
}

// 8. Top Selling Products in this date range
$topProductsStmt = $pdo->prepare("SELECT 
    p.name, 
    c.name as category_name,
    p.unit,
    SUM(si.quantity) as qty_sold,
    SUM(si.total_price) as revenue,
    SUM(si.total_price - (si.cost_price * si.quantity)) as profit_generated
    FROM sale_items si 
    JOIN sales s ON s.id = si.sale_id 
    JOIN products p ON p.id = si.product_id 
    LEFT JOIN categories c ON c.id = p.category_id 
    WHERE s.status = 'Completed' AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY si.product_id 
    ORDER BY qty_sold DESC 
    LIMIT 8");
$topProductsStmt->execute([$startDate, $endDate]);
$topProducts = $topProductsStmt->fetchAll();

// 9. Slow Moving / Dead Stock / Low Selling Products in this period
$lowSellingStmt = $pdo->prepare("SELECT 
    p.id,
    p.name, 
    p.stock_quantity,
    p.unit,
    p.market_sale_price,
    c.name as category_name,
    COALESCE(sales_summary.qty_sold, 0) as qty_sold,
    COALESCE(sales_summary.revenue, 0) as revenue
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN (
        SELECT si.product_id, 
               SUM(si.quantity) as qty_sold, 
               SUM(si.total_price) as revenue
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE s.status = 'Completed' AND DATE(s.created_at) BETWEEN ? AND ?
        GROUP BY si.product_id
    ) sales_summary ON sales_summary.product_id = p.id
    ORDER BY qty_sold ASC, p.stock_quantity DESC
    LIMIT 8");
$lowSellingStmt->execute([$startDate, $endDate]);
$lowSellingProducts = $lowSellingStmt->fetchAll();

// Physical Drawer Cash reconciliation
$expectedDrawerCash = $cashInflow - $cashOutflow;

include __DIR__ . '/../includes/header.php';
?>

<!-- View Switcher -->
<div class="no-print" style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
    <a href="/POS_software/admin/analytics.php" class="btn btn-primary btn-sm" style="font-weight: 700;">
        📊 Financial & Profit Analytics (Selected Period)
    </a>
    <a href="/POS_software/operator/analytics.php" class="btn btn-light btn-sm" style="font-weight: 700;">
        💰 Sales & Drawer Hisab (Cashier Audit) &rarr;
    </a>
</div>

<!-- Analytics Filter Header -->
<div class="card" style="margin-bottom: 22px;">
    <div class="card-body" style="padding: 18px 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
            <div>
                <h2 style="font-size: 19px; font-weight: 800; color: var(--text-primary); margin: 0;">
                    Analytics for <?= date('d M Y', strtotime($startDate)) ?> &rarr; <?= date('d M Y', strtotime($endDate)) ?>
                </h2>
                <p class="text-muted" style="font-size: 12.5px; margin-top: 2px;">
                    Detailed business metrics, sales breakdown, cost analysis, and net cash flow
                </p>
            </div>

            <!-- Quick Preset Filter Buttons -->
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                <a href="/POS_software/admin/analytics.php?preset=today" class="btn btn-sm <?= $preset === 'today' ? 'btn-primary' : 'btn-light' ?>">Today</a>
                <a href="/POS_software/admin/analytics.php?preset=yesterday" class="btn btn-sm <?= $preset === 'yesterday' ? 'btn-primary' : 'btn-light' ?>">Yesterday</a>
                <a href="/POS_software/admin/analytics.php?preset=3days" class="btn btn-sm <?= $preset === '3days' ? 'btn-primary' : 'btn-light' ?>">Last 3 Days</a>
                <a href="/POS_software/admin/analytics.php?preset=7days" class="btn btn-sm <?= ($preset === '7days' || (!$preset && $startDate === date('Y-m-d', strtotime('-6 days')))) ? 'btn-primary' : 'btn-light' ?>">Last 7 Days</a>
                <a href="/POS_software/admin/analytics.php?preset=30days" class="btn btn-sm <?= $preset === '30days' ? 'btn-primary' : 'btn-light' ?>">Last 30 Days</a>
                <a href="/POS_software/admin/analytics.php?preset=this_month" class="btn btn-sm <?= $preset === 'this_month' ? 'btn-primary' : 'btn-light' ?>">This Month</a>
            </div>
        </div>

        <!-- Custom Date Range Form -->
        <form method="get" class="no-print" style="margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border-color); display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
            <div style="display: flex; gap: 8px; align-items: center;">
                <label style="margin: 0; font-size: 12.5px;">From Date:</label>
                <input type="date" name="start_date" value="<?= e($startDate) ?>" class="form-control" style="width: 160px; padding: 6px 10px;">
            </div>

            <div style="display: flex; gap: 8px; align-items: center;">
                <label style="margin: 0; font-size: 12.5px;">To Date:</label>
                <input type="date" name="end_date" value="<?= e($endDate) ?>" class="form-control" style="width: 160px; padding: 6px 10px;">
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Apply Date Range</button>
            <button type="button" onclick="window.print()" class="btn btn-light btn-sm">🖨️ Print Analytics</button>
        </form>
    </div>
</div>

<!-- Cash Drawer Reconciliation Banner -->
<div class="card" style="margin-bottom: 22px; border-left: 5px solid #16a34a; background: linear-gradient(to right, #f0fdf4, #ffffff);">
    <div class="card-body" style="padding: 20px 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <div style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; background: #dcfce7; color: #166534; border-radius: 20px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="4" width="20" height="16" rx="2"></rect><line x1="6" y1="12" x2="18" y2="12"></line></svg>
                    Expected Drawer Cash &middot; Gullay Mein Hone Chahiye
                </div>
                <div style="font-size: 32px; font-weight: 900; color: #15803d; line-height: 1.1; margin-bottom: 4px;">
                    Rs <?= money($expectedDrawerCash) ?>
                </div>
                <p class="text-secondary" style="font-size: 13px; margin: 0;">
                    Cash Inflow: <strong>Rs <?= money($cashInflow) ?></strong> (Sales: <?= money($salesData['cash_sales']) ?>, Udhar Recovered: <?= money($udharData['cash_recovered']) ?>) &minus; Cash Outflow: <strong>Rs <?= money($cashOutflow) ?></strong> (Expenses: <?= money($expData['cash_expenses']) ?>, Dealer: <?= money($dealerData['cash_dealer_paid']) ?>, Returns: <?= money($retData['total_refunds']) ?>)
                </p>
            </div>
            <a href="/POS_software/operator/analytics.php" class="btn btn-sm btn-success">View Detailed Drawer Audit &rarr;</a>
        </div>
    </div>
</div>

<!-- Primary Metric Shortcuts for Selected Range -->
<div class="stats-row">
    <!-- Total Sales -->
    <div class="stat-box primary">
        <div class="stat-box-header">
            <span class="stat-title">Total Sales Revenue</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
        </div>
        <div class="stat-value">Rs <?= money($salesData['total_sales']) ?></div>
        <div class="stat-desc"><?= $salesData['total_invoices'] ?> Invoices generated</div>
    </div>

    <!-- Purchase Cost of Goods -->
    <div class="stat-box warning">
        <div class="stat-box-header">
            <span class="stat-title">Cost of Goods (COGS)</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
            </div>
        </div>
        <div class="stat-value text-warning">Rs <?= money($totalCogs) ?></div>
        <div class="stat-desc">Wholesale cost of sold items</div>
    </div>

    <!-- Gross Profit -->
    <div class="stat-box info">
        <div class="stat-box-header">
            <span class="stat-title">Gross Profit Margin</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
            </div>
        </div>
        <div class="stat-value text-info">Rs <?= money($grossProfit) ?></div>
        <div class="stat-desc">Sales revenue minus product cost</div>
    </div>

    <!-- Net Profit -->
    <div class="stat-box emerald">
        <div class="stat-box-header">
            <span class="stat-title">Net Profit (Actual)</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
            </div>
        </div>
        <div class="stat-value" style="color: #059669;">Rs <?= money($netProfit) ?></div>
        <div class="stat-desc">Gross profit minus expenses & refunds</div>
    </div>
</div>

<!-- Secondary Financial Details Grid -->
<div class="stats-row" style="margin-top: 16px;">
    <!-- Cash Sales vs Bank vs Udhar -->
    <div class="stat-box success">
        <div class="stat-box-header"><span class="stat-title">Cash Sales</span></div>
        <div class="stat-value text-success">Rs <?= money($salesData['cash_sales']) ?></div>
        <div class="stat-desc"><?= $salesData['cash_count'] ?> Invoices paid in cash</div>
    </div>

    <div class="stat-box info">
        <div class="stat-box-header"><span class="stat-title">Bank / Online Sales</span></div>
        <div class="stat-value text-info">Rs <?= money($salesData['bank_sales']) ?></div>
        <div class="stat-desc"><?= $salesData['bank_count'] ?> Invoices via bank transfer</div>
    </div>

    <div class="stat-box warning">
        <div class="stat-box-header"><span class="stat-title">Udhar (Credit Given)</span></div>
        <div class="stat-value text-warning">Rs <?= money($salesData['udhar_sales']) ?></div>
        <div class="stat-desc"><?= $salesData['udhar_count'] ?> Invoices on credit</div>
    </div>

    <div class="stat-box purple">
        <div class="stat-box-header"><span class="stat-title">Udhar Recovered (Wasooli)</span></div>
        <div class="stat-value" style="color: #9333ea;">Rs <?= money($udharData['total_recovered']) ?></div>
        <div class="stat-desc">Cash: Rs <?= money($udharData['cash_recovered']) ?> · Bank: Rs <?= money($udharData['bank_recovered']) ?></div>
    </div>
</div>

<div class="stats-row" style="margin-top: 16px;">
    <!-- Expenses -->
    <div class="stat-box danger">
        <div class="stat-box-header"><span class="stat-title">Total Operating Expenses</span></div>
        <div class="stat-value text-danger">Rs <?= money($expData['total_expenses']) ?></div>
        <div class="stat-desc">Cash: Rs <?= money($expData['cash_expenses']) ?> · Bank: Rs <?= money($expData['bank_expenses']) ?></div>
    </div>

    <!-- Dealer Payments -->
    <div class="stat-box primary">
        <div class="stat-box-header"><span class="stat-title">Dealer Payments Made</span></div>
        <div class="stat-value">Rs <?= money($dealerData['total_dealer_paid']) ?></div>
        <div class="stat-desc">Cash: Rs <?= money($dealerData['cash_dealer_paid']) ?> · Bank: Rs <?= money($dealerData['bank_dealer_paid']) ?></div>
    </div>

    <!-- Returns -->
    <div class="stat-box info">
        <div class="stat-box-header"><span class="stat-title">Returns Refunded</span></div>
        <div class="stat-value"><?= $retData['return_count'] ?> Items</div>
        <div class="stat-desc">Refunded: Rs <?= money($retData['total_refunds']) ?></div>
    </div>

    <!-- Net Cash Flow -->
    <div class="stat-box <?= $netCashFlow >= 0 ? 'success' : 'danger' ?>">
        <div class="stat-box-header"><span class="stat-title">Net Cash Flow in Till</span></div>
        <div class="stat-value" style="color: <?= $netCashFlow >= 0 ? '#16a34a' : '#dc2626' ?>;">
            Rs <?= money($netCashFlow) ?>
        </div>
        <div class="stat-desc">Inflow (Rs <?= money($cashInflow) ?>) &minus; Outflow (Rs <?= money($cashOutflow) ?>)</div>
    </div>
</div>

<!-- Charts Section -->
<div class="dashboard-grid" style="margin-top: 24px;">
    <div class="card">
        <div class="card-header">
            <h3>Daily Sales & Net Profit Trend</h3>
            <span class="badge badge-primary"><?= count($chartDays) ?> Days</span>
        </div>
        <div class="card-body">
            <div style="height: 290px; position: relative;">
                <canvas id="periodTrendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Sales Mode Breakdown</h3>
            <span class="badge badge-gray"><?= $salesData['total_invoices'] ?> Total Bills</span>
        </div>
        <div class="card-body">
            <div style="height: 290px; position: relative;">
                <canvas id="periodModeChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Products Performance: Top Selling vs Low/Zero Selling Products -->
<div class="row" style="margin-top: 20px;">
    <!-- Top Selling Products -->
    <div class="col-6">
        <div class="card" style="height: 100%;">
            <div class="card-header">
                <h3>🔥 Top Selling Products</h3>
                <span class="badge badge-success">High Demand</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-center">Qty Sold</th>
                                <th class="text-right">Sales</th>
                                <th class="text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topProducts)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted" style="padding: 30px;">
                                        No sales in this date range.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($topProducts as $tp): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($tp['name']) ?></strong><br>
                                            <small class="text-muted"><?= e($tp['category_name'] ?? 'General') ?></small>
                                        </td>
                                        <td class="text-center font-bold">
                                            <?= $tp['unit'] === 'kg' ? rtrim(rtrim(number_format((float)$tp['qty_sold'], 3), '0'), '.') . ' kg' : (int)$tp['qty_sold'] . ' pcs' ?>
                                        </td>
                                        <td class="text-right text-success font-bold">Rs <?= money($tp['revenue']) ?></td>
                                        <td class="text-right font-bold" style="color: #059669;">
                                            Rs <?= money($tp['profit_generated']) ?>
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

    <!-- Low / Zero Selling Products (Dead Stock) -->
    <div class="col-6">
        <div class="card" style="height: 100%;">
            <div class="card-header">
                <h3>❄️ Low / Zero Selling Products</h3>
                <span class="badge badge-warning">Slow Moving / Dead Stock</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-center">Current Stock</th>
                                <th class="text-center">Qty Sold</th>
                                <th class="text-right">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lowSellingProducts)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted" style="padding: 30px;">
                                        All products have healthy sales!
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lowSellingProducts as $lp): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($lp['name']) ?></strong><br>
                                            <small class="text-muted"><?= e($lp['category_name'] ?? 'General') ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-gray">
                                                <?= $lp['unit'] === 'kg' ? rtrim(rtrim(number_format((float)$lp['stock_quantity'], 3), '0'), '.') . ' kg' : (int)$lp['stock_quantity'] . ' left' ?>
                                            </span>
                                        </td>
                                        <td class="text-center font-bold">
                                            <?php if ((float)$lp['qty_sold'] == 0): ?>
                                                <span class="badge badge-danger">0 Sold (Dead)</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning"><?= $lp['unit'] === 'kg' ? rtrim(rtrim(number_format((float)$lp['qty_sold'], 3), '0'), '.') . ' kg' : (int)$lp['qty_sold'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right font-bold">Rs <?= money($lp['market_sale_price']) ?></td>
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

<!-- Day-by-Day Financial Breakdown Table -->
<div class="row" style="margin-top: 24px;">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3>Day-by-Day Financial Breakdown (<?= count($daysList) ?> Days Active)</h3>
                <span class="text-muted" style="font-size: 12px;">Detailed audit of each day</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-center">Invoices</th>
                                <th class="text-right">Sales</th>
                                <th class="text-right">Cash</th>
                                <th class="text-right">Bank</th>
                                <th class="text-right">Udhar</th>
                                <th class="text-right">Expenses</th>
                                <th class="text-right">Net Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daysList)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted" style="padding: 35px;">
                                        No transactions recorded during this selected date range.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($daysList as $d): 
                                    $dGross = (float) $d['day_sales'] - (float) $d['day_cost'];
                                    $dNet = $dGross - (float) $d['day_expenses'] - (float) $d['day_refunds'];
                                ?>
                                    <tr>
                                        <td>
                                            <a href="/POS_software/admin/reports.php?view=daily&date=<?= $d['day_date'] ?>" style="color: var(--primary); font-weight: 700;">
                                                <?= date('D, d M Y', strtotime($d['day_date'])) ?>
                                            </a>
                                        </td>
                                        <td class="text-center"><span class="badge badge-gray"><?= $d['invoices_count'] ?></span></td>
                                        <td class="text-right font-bold text-success">Rs <?= money($d['day_sales']) ?></td>
                                        <td class="text-right">Rs <?= money($d['day_cash']) ?></td>
                                        <td class="text-right">Rs <?= money($d['day_bank']) ?></td>
                                        <td class="text-right text-warning">Rs <?= money($d['day_udhar']) ?></td>
                                        <td class="text-right text-danger">Rs <?= money($d['day_expenses']) ?></td>
                                        <td class="text-right font-bold" style="color: <?= $dNet >= 0 ? '#059669' : '#dc2626' ?>;">
                                            Rs <?= money($dNet) ?>
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
// Trend chart for custom date range
const ctxTrend = document.getElementById('periodTrendChart').getContext('2d');
new Chart(ctxTrend, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartDays) ?>,
        datasets: [
            {
                label: 'Sales Revenue (Rs)',
                data: <?= json_encode($chartSales) ?>,
                backgroundColor: 'rgba(37, 99, 235, 0.75)',
                borderColor: '#2563eb',
                borderWidth: 1,
                borderRadius: 4
            },
            {
                label: 'Net Profit (Rs)',
                data: <?= json_encode($chartProfits) ?>,
                backgroundColor: 'rgba(16, 185, 129, 0.75)',
                borderColor: '#10b981',
                borderWidth: 1,
                borderRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                labels: { font: { weight: 600 } }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: {
                    callback: (val) => 'Rs ' + val.toLocaleString()
                }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

// Payment Mode chart
const ctxMode = document.getElementById('periodModeChart').getContext('2d');
const cashAmt = <?= (float) $salesData['cash_sales'] ?>;
const bankAmt = <?= (float) $salesData['bank_sales'] ?>;
const udharAmt = <?= (float) $salesData['udhar_sales'] ?>;
const hasData = (cashAmt + bankAmt + udharAmt) > 0;

new Chart(ctxMode, {
    type: 'doughnut',
    data: {
        labels: ['Cash Sales', 'Bank Sales', 'Udhar (Credit)'],
        datasets: [{
            data: hasData ? [cashAmt, bankAmt, udharAmt] : [1, 0, 0],
            backgroundColor: hasData ? ['#10b981', '#0284c7', '#f59e0b'] : ['#e2e8f0', '#e2e8f0', '#e2e8f0'],
            borderColor: '#ffffff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth: 12, font: { weight: 600 } }
            }
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
