<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(); // accessible by both Operator and Admin

$pdo = db();
$user = current_user();
$page_title = 'Sales & Drawer Cash Analytics';

// Date range selection (defaults to Today for operator hisab)
$preset = $_GET['preset'] ?? 'today';
$today = date('Y-m-d');
$scope = $_GET['scope'] ?? 'all'; // 'all' (entire register/drawer) or 'my' (logged-in operator)

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
} elseif ($preset === 'this_month') {
    $startDate = date('Y-m-01');
    $endDate = $today;
} else {
    // Custom date range
    $startDate = $_GET['start_date'] ?? $today;
    $endDate = $_GET['end_date'] ?? $today;
    $preset = 'custom';
}

// Validate dates
if ($startDate > $endDate) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

// Build scope parameters if user filtered by 'my' shift
$salesScopeSql = ($scope === 'my') ? " AND operator_id = ?" : "";
$salesScopeParams = ($scope === 'my') ? [$startDate, $endDate, $user['id']] : [$startDate, $endDate];

$udharScopeSql = ($scope === 'my') ? " AND created_by = ?" : "";
$udharScopeParams = ($scope === 'my') ? [$startDate, $endDate, $user['id']] : [$startDate, $endDate];

$dealerScopeSql = ($scope === 'my') ? " AND created_by = ?" : "";
$dealerScopeParams = ($scope === 'my') ? [$startDate, $endDate, $user['id']] : [$startDate, $endDate];

$expScopeSql = ($scope === 'my') ? " AND recorded_by = ?" : "";
$expScopeParams = ($scope === 'my') ? [$startDate, $endDate, $user['id']] : [$startDate, $endDate];

$retScopeSql = ($scope === 'my') ? " AND created_by = ?" : "";
$retScopeParams = ($scope === 'my') ? [$startDate, $endDate, $user['id']] : [$startDate, $endDate];

// 1. Sales & Revenue Metrics for selected range (NO cost, NO profit!)
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
    WHERE DATE(created_at) BETWEEN ? AND ? $salesScopeSql");
$salesStmt->execute($salesScopeParams);
$salesData = $salesStmt->fetch();

// 2. Customer Udhar Collected (Wasooli) in this range
$udharStmt = $pdo->prepare("SELECT 
    COALESCE(SUM(amount_paid), 0) as total_recovered,
    COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount_paid ELSE 0 END), 0) as cash_recovered,
    COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN amount_paid ELSE 0 END), 0) as bank_recovered
    FROM udhar_payments 
    WHERE date BETWEEN ? AND ? $udharScopeSql");
$udharStmt->execute($udharScopeParams);
$udharData = $udharStmt->fetch();

// 3. Dealer Payments Made in this range
$dealerStmt = $pdo->prepare("SELECT 
    COALESCE(SUM(paid_amount), 0) as total_dealer_paid,
    COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN paid_amount ELSE 0 END), 0) as cash_dealer_paid,
    COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN paid_amount ELSE 0 END), 0) as bank_dealer_paid
    FROM dealer_payments 
    WHERE date BETWEEN ? AND ? $dealerScopeSql");
$dealerStmt->execute($dealerScopeParams);
$dealerData = $dealerStmt->fetch();

// 4. Operating Expenses in this range
$expStmt = $pdo->prepare("SELECT 
    COALESCE(SUM(amount), 0) as total_expenses,
    COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount ELSE 0 END), 0) as cash_expenses,
    COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN amount ELSE 0 END), 0) as bank_expenses
    FROM expenses 
    WHERE date BETWEEN ? AND ? $expScopeSql");
$expStmt->execute($expScopeParams);
$expData = $expStmt->fetch();

// 5. Returns & Refunds in this range
$retStmt = $pdo->prepare("SELECT 
    COUNT(*) as return_count,
    COALESCE(SUM(refund_amount), 0) as total_refunds
    FROM returns 
    WHERE date BETWEEN ? AND ? $retScopeSql");
$retStmt->execute($retScopeParams);
$retData = $retStmt->fetch();

// PHYSICAL CASH DRAWER RECONCILIATION MATH
// Inflow: Cash sales + Udhar collected in cash
$cashInflow = (float) $salesData['cash_sales'] + (float) $udharData['cash_recovered'];
// Outflow: Cash expenses + Dealer cash payments + Return refunds
$cashOutflow = (float) $dealerData['cash_dealer_paid'] + (float) $expData['cash_expenses'] + (float) $retData['total_refunds'];
// Net cash that MUST be physically in drawer
$expectedDrawerCash = $cashInflow - $cashOutflow;

// 6. Day-by-Day performance table & trend data (NO cost, NO profit!)
$daysScopeSql = ($scope === 'my') ? " AND s.operator_id = ?" : "";
$daysScopeParams = ($scope === 'my') ? [$startDate, $endDate, $user['id']] : [$startDate, $endDate];

$daysQuery = $pdo->prepare("SELECT 
    DATE(s.created_at) as day_date,
    COUNT(*) as invoices_count,
    SUM(s.net_payable) as day_sales,
    SUM(CASE WHEN s.payment_mode = 'Cash' THEN s.net_payable ELSE 0 END) as day_cash,
    SUM(CASE WHEN s.payment_mode = 'Bank' THEN s.net_payable ELSE 0 END) as day_bank,
    SUM(CASE WHEN s.payment_mode = 'Udhar' THEN s.net_payable ELSE 0 END) as day_udhar,
    COALESCE((SELECT SUM(amount_paid) FROM udhar_payments WHERE payment_mode = 'Cash' AND date = DATE(s.created_at)), 0) as day_udhar_cash,
    COALESCE((SELECT SUM(amount) FROM expenses WHERE payment_mode = 'Cash' AND date = DATE(s.created_at)), 0) as day_expenses_cash,
    COALESCE((SELECT SUM(paid_amount) FROM dealer_payments WHERE payment_mode = 'Cash' AND date = DATE(s.created_at)), 0) as day_dealer_cash,
    COALESCE((SELECT SUM(refund_amount) FROM returns WHERE date = DATE(s.created_at)), 0) as day_refunds
    FROM sales s 
    WHERE s.status = 'Completed' AND DATE(s.created_at) BETWEEN ? AND ? $daysScopeSql
    GROUP BY DATE(s.created_at)
    ORDER BY day_date ASC");
$daysQuery->execute($daysScopeParams);
$daysList = $daysQuery->fetchAll();

$chartDays = [];
$chartSales = [];
$chartCashIn = [];

foreach ($daysList as $d) {
    $chartDays[] = date('d M', strtotime($d['day_date']));
    $chartSales[] = (float) $d['day_sales'];
    $chartCashIn[] = (float) $d['day_cash'] + (float) $d['day_udhar_cash'];
}

// 7. Top Selling Products (Filtered to revenue & quantity only - NO profit/cost!)
$topProdScopeSql = ($scope === 'my') ? " AND s.operator_id = ?" : "";
$topProdParams = ($scope === 'my') ? [$startDate, $endDate, $user['id']] : [$startDate, $endDate];

$topProductsStmt = $pdo->prepare("SELECT 
    p.name, 
    c.name as category_name,
    SUM(si.quantity) as qty_sold,
    SUM(si.total_price) as revenue
    FROM sale_items si 
    JOIN sales s ON s.id = si.sale_id 
    JOIN products p ON p.id = si.product_id 
    LEFT JOIN categories c ON c.id = p.category_id 
    WHERE s.status = 'Completed' AND DATE(s.created_at) BETWEEN ? AND ? $topProdScopeSql
    GROUP BY si.product_id 
    ORDER BY qty_sold DESC 
    LIMIT 8");
$topProductsStmt->execute($topProdParams);
$topProducts = $topProductsStmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Filter & Date Selection Header -->
<div class="card" style="margin-bottom: 22px;">
    <div class="card-body" style="padding: 18px 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h2 style="font-size: 19px; font-weight: 800; color: var(--text-primary); margin: 0;">
                        Daily Sales & Drawer Hisab
                    </h2>
                    <span class="badge <?= $scope === 'my' ? 'badge-primary' : 'badge-gray' ?>" style="font-size: 11.5px;">
                        <?= $scope === 'my' ? 'My Counter Only (' . e($user['username']) . ')' : 'All Counters (Store Drawer)' ?>
                    </span>
                </div>
                <p class="text-muted" style="font-size: 12.5px; margin-top: 3px;">
                    Date: <strong><?= date('d M Y', strtotime($startDate)) ?></strong> 
                    <?= $startDate !== $endDate ? ' &rarr; <strong>' . date('d M Y', strtotime($endDate)) . '</strong>' : '' ?>
                    &middot; Counter sales audit & expected physical cash reconciliation
                </p>
            </div>

            <!-- Preset Filters -->
            <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                <a href="/POS_software/operator/analytics.php?preset=today&scope=<?= urlencode($scope) ?>" class="btn btn-sm <?= $preset === 'today' ? 'btn-primary' : 'btn-light' ?>">Today (Aj)</a>
                <a href="/POS_software/operator/analytics.php?preset=yesterday&scope=<?= urlencode($scope) ?>" class="btn btn-sm <?= $preset === 'yesterday' ? 'btn-primary' : 'btn-light' ?>">Yesterday</a>
                <a href="/POS_software/operator/analytics.php?preset=3days&scope=<?= urlencode($scope) ?>" class="btn btn-sm <?= $preset === '3days' ? 'btn-primary' : 'btn-light' ?>">Last 3 Days (3 Din)</a>
                <a href="/POS_software/operator/analytics.php?preset=7days&scope=<?= urlencode($scope) ?>" class="btn btn-sm <?= $preset === '7days' ? 'btn-primary' : 'btn-light' ?>">Last 7 Days</a>
                <a href="/POS_software/operator/analytics.php?preset=this_month&scope=<?= urlencode($scope) ?>" class="btn btn-sm <?= $preset === 'this_month' ? 'btn-primary' : 'btn-light' ?>">This Month</a>
            </div>
        </div>

        <!-- Custom Date Range & Scope Controls -->
        <form method="get" class="no-print" style="margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border-color); display: flex; gap: 14px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <div style="display: flex; gap: 8px; align-items: center;">
                    <label style="margin: 0; font-size: 12.5px; font-weight: 600;">From:</label>
                    <input type="date" name="start_date" value="<?= e($startDate) ?>" class="form-control" style="width: 155px; padding: 6px 10px;">
                </div>

                <div style="display: flex; gap: 8px; align-items: center;">
                    <label style="margin: 0; font-size: 12.5px; font-weight: 600;">To:</label>
                    <input type="date" name="end_date" value="<?= e($endDate) ?>" class="form-control" style="width: 155px; padding: 6px 10px;">
                </div>

                <div style="display: flex; gap: 8px; align-items: center;">
                    <label style="margin: 0; font-size: 12.5px; font-weight: 600;">Scope:</label>
                    <select name="scope" class="form-control" style="width: 175px; padding: 6px 10px;">
                        <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All Counters (Overall)</option>
                        <option value="my" <?= $scope === 'my' ? 'selected' : '' ?>>My Sales (<?= e($user['username']) ?>)</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary btn-sm">Filter Hisab</button>
            </div>

            <div>
                <button type="button" onclick="window.print()" class="btn btn-light btn-sm">
                    🖨️ Print Hisab Slip
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================
     HERO HIGHLIGHT: PHYSICAL CASH DRAWER RECONCILIATION
     ============================================================== -->
<div class="card" style="margin-bottom: 22px; border-left: 5px solid #16a34a; background: linear-gradient(to right, #f0fdf4, #ffffff);">
    <div class="card-body" style="padding: 22px 26px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div>
                <div style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; background: #dcfce7; color: #166534; border-radius: 20px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="4" width="20" height="16" rx="2"></rect><line x1="6" y1="12" x2="18" y2="12"></line></svg>
                    Expected Drawer Cash &middot; Mere Paas Drawer Mein Hone Chahiye
                </div>
                <div style="font-size: 36px; font-weight: 900; color: #15803d; line-height: 1.1; margin-bottom: 6px;">
                    Rs <?= money($expectedDrawerCash) ?>
                </div>
                <p class="text-secondary" style="font-size: 13.5px; margin: 0;">
                    Shift/Period ka net cash hisab: <strong>Rs <?= money($expectedDrawerCash) ?></strong> physical cash drawer / gullay mein mojood hona lazmi hai.
                </p>
            </div>

            <!-- Quick Equation Pill -->
            <div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px 18px; box-shadow: var(--shadow-sm); min-width: 290px;">
                <div style="font-size: 12px; font-weight: 700; color: #166534; margin-bottom: 6px; text-transform: uppercase;">
                    Cash Drawer Formula:
                </div>
                <div style="font-size: 12.5px; display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span class="text-muted">(+) Cash Sales Received:</span>
                    <strong class="text-success">+ Rs <?= money($salesData['cash_sales']) ?></strong>
                </div>
                <div style="font-size: 12.5px; display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span class="text-muted">(+) Udhar Wasooli (Cash):</span>
                    <strong class="text-success">+ Rs <?= money($udharData['cash_recovered']) ?></strong>
                </div>
                <div style="font-size: 12.5px; display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span class="text-muted">(&minus;) Expenses Paid (Cash):</span>
                    <strong class="text-danger">&minus; Rs <?= money($expData['cash_expenses']) ?></strong>
                </div>
                <div style="font-size: 12.5px; display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span class="text-muted">(&minus;) Dealer Paid (Cash):</span>
                    <strong class="text-danger">&minus; Rs <?= money($dealerData['cash_dealer_paid']) ?></strong>
                </div>
                <div style="font-size: 12.5px; display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span class="text-muted">(&minus;) Returns Refunded:</span>
                    <strong class="text-danger">&minus; Rs <?= money($retData['total_refunds']) ?></strong>
                </div>
                <div style="border-top: 1px dashed #86efac; padding-top: 6px; font-size: 13.5px; display: flex; justify-content: space-between; font-weight: 800; color: #15803d;">
                    <span>Net Drawer Cash:</span>
                    <span>Rs <?= money($expectedDrawerCash) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================
     PRIMARY METRICS: SALES REVENUE & PAYMENT MODES
     ============================================================== -->
<div class="stats-row">
    <!-- Total Sales Billing -->
    <div class="stat-box primary">
        <div class="stat-box-header">
            <span class="stat-title">Total Sales Revenue</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
        </div>
        <div class="stat-value">Rs <?= money($salesData['total_sales']) ?></div>
        <div class="stat-desc"><?= $salesData['total_invoices'] ?> Invoices completed &middot; Rs <?= money($salesData['total_discounts']) ?> disc.</div>
    </div>

    <!-- Cash Sales -->
    <div class="stat-box success">
        <div class="stat-box-header">
            <span class="stat-title">Cash Sales (Counter)</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
            </div>
        </div>
        <div class="stat-value text-success">Rs <?= money($salesData['cash_sales']) ?></div>
        <div class="stat-desc"><?= $salesData['cash_count'] ?> Invoices paid via cash</div>
    </div>

    <!-- Bank / Digital Sales -->
    <div class="stat-box info">
        <div class="stat-box-header">
            <span class="stat-title">Bank / Card / Online</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            </div>
        </div>
        <div class="stat-value text-info">Rs <?= money($salesData['bank_sales']) ?></div>
        <div class="stat-desc"><?= $salesData['bank_count'] ?> Invoices via online/bank</div>
    </div>

    <!-- Udhar Given -->
    <div class="stat-box warning">
        <div class="stat-box-header">
            <span class="stat-title">Udhar (Credit Bills)</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            </div>
        </div>
        <div class="stat-value text-warning">Rs <?= money($salesData['udhar_sales']) ?></div>
        <div class="stat-desc"><?= $salesData['udhar_count'] ?> Bills given on credit</div>
    </div>
</div>

<!-- ==============================================================
     SECONDARY METRICS: DRAWER CASH INFLOW & OUTFLOW
     ============================================================== -->
<div class="stats-row" style="margin-top: 16px;">
    <!-- Udhar Wasooli (Recovery) -->
    <div class="stat-box purple">
        <div class="stat-box-header">
            <span class="stat-title">Udhar Wasooli (Collected)</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
        </div>
        <div class="stat-value" style="color: #9333ea;">Rs <?= money($udharData['total_recovered']) ?></div>
        <div class="stat-desc">Cash: <strong>Rs <?= money($udharData['cash_recovered']) ?></strong> &middot; Bank: Rs <?= money($udharData['bank_recovered']) ?></div>
    </div>

    <!-- Shop Expenses -->
    <div class="stat-box danger">
        <div class="stat-box-header">
            <span class="stat-title">Shop Expenses Paid</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>
        </div>
        <div class="stat-value text-danger">Rs <?= money($expData['total_expenses']) ?></div>
        <div class="stat-desc">Cash Out: <strong>Rs <?= money($expData['cash_expenses']) ?></strong> &middot; Bank: Rs <?= money($expData['bank_expenses']) ?></div>
    </div>

    <!-- Dealer Payments -->
    <div class="stat-box primary">
        <div class="stat-box-header">
            <span class="stat-title">Dealer Payments Made</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            </div>
        </div>
        <div class="stat-value">Rs <?= money($dealerData['total_dealer_paid']) ?></div>
        <div class="stat-desc">Cash Out: <strong>Rs <?= money($dealerData['cash_dealer_paid']) ?></strong> &middot; Bank: Rs <?= money($dealerData['bank_dealer_paid']) ?></div>
    </div>

    <!-- Returns & Refunds -->
    <div class="stat-box info">
        <div class="stat-box-header">
            <span class="stat-title">Returns Handled</span>
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
            </div>
        </div>
        <div class="stat-value"><?= $retData['return_count'] ?> Items</div>
        <div class="stat-desc">Refunded Cash: <strong>Rs <?= money($retData['total_refunds']) ?></strong></div>
    </div>
</div>

<!-- ==============================================================
     CHARTS: SALES TREND & PAYMENT DISTRIBUTION
     ============================================================== -->
<div class="dashboard-grid" style="margin-top: 24px;">
    <div class="card">
        <div class="card-header">
            <h3>Sales & Cash Trend</h3>
            <span class="badge badge-primary"><?= count($chartDays) ?> Days</span>
        </div>
        <div class="card-body">
            <div style="height: 280px; position: relative;">
                <canvas id="operatorTrendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Payment Mode Distribution</h3>
            <span class="badge badge-gray"><?= $salesData['total_invoices'] ?> Bills</span>
        </div>
        <div class="card-body">
            <div style="height: 280px; position: relative;">
                <canvas id="operatorModeChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================
     DAY-BY-DAY AUDIT & TOP SELLING ITEMS
     ============================================================== -->
<div class="row" style="margin-top: 20px;">
    <!-- Day-by-Day Breakdown (Crucial for 3 days / 7 days hisab) -->
    <div class="col-8">
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 style="margin: 0;">Day-by-Day Drawer Hisab</h3>
                    <small class="text-muted">Audit of sales, collections, cash out, and drawer balance for each day</small>
                </div>
                <span class="badge badge-primary"><?= count($daysList) ?> Days Active</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-center">Bills</th>
                                <th class="text-right">Total Sales</th>
                                <th class="text-right">Cash In</th>
                                <th class="text-right">Bank</th>
                                <th class="text-right">Udhar</th>
                                <th class="text-right">Cash Out</th>
                                <th class="text-right" style="background: #f0fdf4; color: #166534;">Drawer Cash</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daysList)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted" style="padding: 30px;">
                                        No sales or transactions found for this period.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($daysList as $d): 
                                    $dayCashIn = (float) $d['day_cash'] + (float) $d['day_udhar_cash'];
                                    $dayCashOut = (float) $d['day_expenses_cash'] + (float) $d['day_dealer_cash'] + (float) $d['day_refunds'];
                                    $dayNetDrawer = $dayCashIn - $dayCashOut;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= date('d M Y', strtotime($d['day_date'])) ?></strong><br>
                                            <small class="text-muted"><?= date('l', strtotime($d['day_date'])) ?></small>
                                        </td>
                                        <td class="text-center font-bold"><?= $d['invoices_count'] ?></td>
                                        <td class="text-right font-bold">Rs <?= money($d['day_sales']) ?></td>
                                        <td class="text-right text-success">
                                            Rs <?= money($d['day_cash']) ?>
                                            <?php if ($d['day_udhar_cash'] > 0): ?>
                                                <br><small class="text-muted">+Rs <?= money($d['day_udhar_cash']) ?> wasooli</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right text-info">Rs <?= money($d['day_bank']) ?></td>
                                        <td class="text-right text-warning">Rs <?= money($d['day_udhar']) ?></td>
                                        <td class="text-right text-danger">
                                            Rs <?= money($dayCashOut) ?>
                                        </td>
                                        <td class="text-right font-bold" style="background: #f0fdf4; color: <?= $dayNetDrawer >= 0 ? '#15803d' : '#dc2626' ?>; font-size: 13.5px;">
                                            Rs <?= money($dayNetDrawer) ?>
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

    <!-- Top Selling Items (Clean - Quantity and Sales only) -->
    <div class="col-4">
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 style="margin: 0;">Top Sold Items</h3>
                    <small class="text-muted">Most popular items by quantity</small>
                </div>
                <span class="badge badge-gray">In Selected Range</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topProducts)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted" style="padding: 30px;">
                                        No items sold in this period.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($topProducts as $tp): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($tp['name']) ?></strong><br>
                                            <small class="text-muted"><?= e($tp['category_name'] ?? 'General') ?></small>
                                        </td>
                                        <td class="text-center font-bold"><?= $tp['qty_sold'] ?></td>
                                        <td class="text-right text-success font-bold">Rs <?= money($tp['revenue']) ?></td>
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

<!-- ==============================================================
     PRINTABLE SUMMARY AUDIT SHEET (Visible on print)
     ============================================================== -->
<div class="printable-drawer-summary" style="display: none;">
    <div style="text-align: center; margin-bottom: 15px; border-bottom: 2px solid #000; padding-bottom: 10px;">
        <h2 style="margin: 0; font-size: 20px; text-transform: uppercase;">Operator Shift & Drawer Closing Hisab</h2>
        <p style="margin: 3px 0; font-size: 12px;">Period: <?= date('d/m/Y', strtotime($startDate)) ?> to <?= date('d/m/Y', strtotime($endDate)) ?> &middot; Cashier: <?= e($user['full_name'] ?: $user['username']) ?></p>
        <p style="margin: 0; font-size: 11px;">Generated on: <?= date('d/m/Y h:i A') ?></p>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 15px;">
        <tr style="border-bottom: 1px solid #ccc;">
            <td style="padding: 6px 0;">Total Invoices Completed:</td>
            <td style="text-align: right; font-weight: bold;"><?= $salesData['total_invoices'] ?></td>
        </tr>
        <tr style="border-bottom: 1px solid #ccc;">
            <td style="padding: 6px 0;">Total Sales Revenue:</td>
            <td style="text-align: right; font-weight: bold;">Rs <?= money($salesData['total_sales']) ?></td>
        </tr>
        <tr style="border-bottom: 1px solid #ccc;">
            <td style="padding: 6px 0;">Cash Sales Collected:</td>
            <td style="text-align: right; font-weight: bold;">Rs <?= money($salesData['cash_sales']) ?></td>
        </tr>
        <tr style="border-bottom: 1px solid #ccc;">
            <td style="padding: 6px 0;">Customer Udhar Wasooli (Cash):</td>
            <td style="text-align: right; font-weight: bold;">Rs <?= money($udharData['cash_recovered']) ?></td>
        </tr>
        <tr style="border-bottom: 1px solid #ccc;">
            <td style="padding: 6px 0;">Expenses Paid from Drawer:</td>
            <td style="text-align: right; font-weight: bold; color: #dc2626;">&minus; Rs <?= money($expData['cash_expenses']) ?></td>
        </tr>
        <tr style="border-bottom: 1px solid #ccc;">
            <td style="padding: 6px 0;">Dealer Payments Paid from Drawer:</td>
            <td style="text-align: right; font-weight: bold; color: #dc2626;">&minus; Rs <?= money($dealerData['cash_dealer_paid']) ?></td>
        </tr>
        <tr style="border-bottom: 1px solid #ccc;">
            <td style="padding: 6px 0;">Returns / Refunds Paid Back:</td>
            <td style="text-align: right; font-weight: bold; color: #dc2626;">&minus; Rs <?= money($retData['total_refunds']) ?></td>
        </tr>
        <tr style="border-top: 2px solid #000; border-bottom: 2px solid #000; font-size: 16px;">
            <td style="padding: 10px 0; font-weight: 900;">EXPECTED CASH IN DRAWER:</td>
            <td style="text-align: right; font-weight: 900; color: #15803d;">Rs <?= money($expectedDrawerCash) ?></td>
        </tr>
    </table>

    <div style="display: flex; justify-content: space-between; margin-top: 50px;">
        <div style="border-top: 1px solid #000; width: 180px; text-align: center; font-size: 12px; padding-top: 4px;">
            Cashier Signature
        </div>
        <div style="border-top: 1px solid #000; width: 180px; text-align: center; font-size: 12px; padding-top: 4px;">
            Manager / Owner Signature
        </div>
    </div>
</div>

<style>
@media print {
    body { background: #fff !important; }
    .no-print, .topbar, .sidebar, .card, .dashboard-grid, .stats-row, .row { display: none !important; }
    .printable-drawer-summary {
        display: block !important;
        max-width: 600px;
        margin: 20px auto;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    }
}
</style>

<script>
// Sales & Cash In Trend Chart
const ctxTrend = document.getElementById('operatorTrendChart').getContext('2d');
new Chart(ctxTrend, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartDays) ?>,
        datasets: [
            {
                label: 'Total Sales Revenue (Rs)',
                data: <?= json_encode($chartSales) ?>,
                backgroundColor: 'rgba(34, 197, 94, 0.75)',
                borderColor: '#22c55e',
                borderWidth: 1,
                borderRadius: 4
            },
            {
                label: 'Cash Inflow (Sales + Wasooli) (Rs)',
                data: <?= json_encode($chartCashIn) ?>,
                backgroundColor: 'rgba(2, 132, 199, 0.75)',
                borderColor: '#0284c7',
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
const ctxMode = document.getElementById('operatorModeChart').getContext('2d');
const cashAmt = <?= (float) $salesData['cash_sales'] ?>;
const bankAmt = <?= (float) $salesData['bank_sales'] ?>;
const udharAmt = <?= (float) $salesData['udhar_sales'] ?>;
const hasData = (cashAmt + bankAmt + udharAmt) > 0;

new Chart(ctxMode, {
    type: 'doughnut',
    data: {
        labels: ['Cash Sales', 'Bank / Online', 'Udhar (Credit)'],
        datasets: [{
            data: hasData ? [cashAmt, bankAmt, udharAmt] : [1, 0, 0],
            backgroundColor: hasData ? ['#22c55e', '#0284c7', '#f59e0b'] : ['#e2e8f0', '#e2e8f0', '#e2e8f0'],
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
