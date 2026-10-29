<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Admin');

$pdo = db();
$page_title = 'Sales, Expense & Profit Reports';

$view = $_GET['view'] ?? 'daily';
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedMonth = $_GET['month'] ?? date('Y-m');
$selectedYear = (int) ($_GET['year'] ?? date('Y'));

// -------------------------------------------------------------
// 1. DAILY REPORT CALCULATION
// -------------------------------------------------------------
if ($view === 'daily') {
    // Sales summary for selected date
    $sStmt = $pdo->prepare("SELECT 
        COUNT(CASE WHEN status = 'Completed' THEN 1 END) as invoices,
        COALESCE(SUM(CASE WHEN status = 'Completed' THEN net_payable ELSE 0 END), 0) as total_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Cash' THEN net_payable ELSE 0 END), 0) as cash_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Bank' THEN net_payable ELSE 0 END), 0) as bank_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Udhar' THEN net_payable ELSE 0 END), 0) as udhar_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' THEN bonus_discount ELSE 0 END), 0) as total_discounts
        FROM sales WHERE DATE(created_at) = ?");
    $sStmt->execute([$selectedDate]);
    $daySales = $sStmt->fetch();

    // Cost of goods sold for selected date
    $cStmt = $pdo->prepare("SELECT 
        COALESCE(SUM(si.cost_price * si.quantity), 0) as cogs
        FROM sale_items si 
        JOIN sales s ON s.id = si.sale_id 
        WHERE s.status = 'Completed' AND DATE(s.created_at) = ?");
    $cStmt->execute([$selectedDate]);
    $dayCogs = (float) $cStmt->fetchColumn();

    // Expenses for selected date
    $eStmt = $pdo->prepare("SELECT 
        COALESCE(SUM(amount), 0) as total_expense,
        COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount ELSE 0 END), 0) as cash_expense
        FROM expenses WHERE date = ?");
    $eStmt->execute([$selectedDate]);
    $dayExpense = $eStmt->fetch();

    // Returns for selected date
    $rStmt = $pdo->prepare("SELECT 
        COUNT(*) as returns_count,
        COALESCE(SUM(refund_amount), 0) as total_refund
        FROM returns WHERE date = ?");
    $rStmt->execute([$selectedDate]);
    $dayReturn = $rStmt->fetch();

    // Udhar collections for selected date
    $uStmt = $pdo->prepare("SELECT 
        COALESCE(SUM(amount_paid), 0) as total_recovered,
        COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount_paid ELSE 0 END), 0) as cash_recovered
        FROM udhar_payments WHERE date = ?");
    $uStmt->execute([$selectedDate]);
    $dayUdharRec = $uStmt->fetch();

    // Dealer payments for selected date
    $dStmt = $pdo->prepare("SELECT 
        COALESCE(SUM(paid_amount), 0) as total_dealer_paid,
        COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN paid_amount ELSE 0 END), 0) as cash_dealer_paid
        FROM dealer_payments WHERE date = ?");
    $dStmt->execute([$selectedDate]);
    $dayDealer = $dStmt->fetch();

    // Gross and Net Profit
    $grossProfit = (float) $daySales['total_sales'] - $dayCogs;
    $netProfit = $grossProfit - (float) $dayExpense['total_expense'] - (float) $dayReturn['total_refund'];

    // Drawer Cash reconciliation
    $cashIn = (float) $daySales['cash_sales'] + (float) $dayUdharRec['cash_recovered'];
    $cashOut = (float) $dayExpense['cash_expense'] + (float) $dayDealer['cash_dealer_paid'] + (float) $dayReturn['total_refund'];
    $drawerNet = max(0.0, $cashIn - $cashOut);

    // Invoices list for that date
    $invStmt = $pdo->prepare("SELECT s.*, u.username as operator_name FROM sales s LEFT JOIN users u ON u.id = s.operator_id WHERE DATE(s.created_at) = ? ORDER BY s.id DESC");
    $invStmt->execute([$selectedDate]);
    $dayInvoices = $invStmt->fetchAll();

    // Expenses list for that date
    $expStmt = $pdo->prepare("SELECT e.*, u.username as recorded_by_user FROM expenses e LEFT JOIN users u ON u.id = e.recorded_by WHERE e.date = ? ORDER BY e.id DESC");
    $expStmt->execute([$selectedDate]);
    $dayExpensesList = $expStmt->fetchAll();
}

// -------------------------------------------------------------
// 2. MONTHLY REPORT CALCULATION
// -------------------------------------------------------------
elseif ($view === 'monthly') {
    $monthParts = explode('-', $selectedMonth);
    $mYear = (int) $monthParts[0];
    $mMonth = (int) ($monthParts[1] ?? 1);

    // Days query
    $mStmt = $pdo->prepare("SELECT 
        DATE(s.created_at) as day_date,
        COUNT(*) as invoices,
        SUM(s.net_payable) as sales,
        SUM(CASE WHEN s.payment_mode = 'Cash' THEN s.net_payable ELSE 0 END) as cash_sales,
        SUM(CASE WHEN s.payment_mode = 'Bank' THEN s.net_payable ELSE 0 END) as bank_sales,
        SUM(CASE WHEN s.payment_mode = 'Udhar' THEN s.net_payable ELSE 0 END) as udhar_sales,
        COALESCE((SELECT SUM(si.cost_price * si.quantity) FROM sale_items si JOIN sales s2 ON s2.id = si.sale_id WHERE s2.status = 'Completed' AND DATE(s2.created_at) = DATE(s.created_at)), 0) as cost,
        COALESCE((SELECT SUM(amount) FROM expenses WHERE date = DATE(s.created_at)), 0) as expenses,
        COALESCE((SELECT SUM(refund_amount) FROM returns WHERE date = DATE(s.created_at)), 0) as refunds
        FROM sales s 
        WHERE s.status = 'Completed' AND MONTH(s.created_at) = ? AND YEAR(s.created_at) = ?
        GROUP BY DATE(s.created_at)
        ORDER BY day_date DESC");
    $mStmt->execute([$mMonth, $mYear]);
    $monthDays = $mStmt->fetchAll();

    // Total monthly metrics
    $mTotalSales = 0; $mTotalCost = 0; $mTotalExp = 0; $mTotalRef = 0; $mTotalInvoices = 0;
    foreach ($monthDays as $md) {
        $mTotalSales += (float) $md['sales'];
        $mTotalCost += (float) $md['cost'];
        $mTotalExp += (float) $md['expenses'];
        $mTotalRef += (float) $md['refunds'];
        $mTotalInvoices += (int) $md['invoices'];
    }
    $mGrossProfit = $mTotalSales - $mTotalCost;
    $mNetProfit = $mGrossProfit - $mTotalExp - $mTotalRef;
}

// -------------------------------------------------------------
// 3. YEARLY REPORT CALCULATION
// -------------------------------------------------------------
elseif ($view === 'yearly') {
    $yearMonths = [];
    $yTotalSales = 0; $yTotalCost = 0; $yTotalExp = 0; $yTotalInvoices = 0;

    for ($m = 1; $m <= 12; $m++) {
        $mLabel = date('F', mktime(0, 0, 0, $m, 1, $selectedYear));

        $sRow = $pdo->prepare("SELECT 
            COUNT(*) as invoices,
            COALESCE(SUM(net_payable), 0) as sales,
            COALESCE((SELECT SUM(si.cost_price * si.quantity) FROM sale_items si JOIN sales s2 ON s2.id = si.sale_id WHERE s2.status = 'Completed' AND MONTH(s2.created_at) = ? AND YEAR(s2.created_at) = ?), 0) as cost,
            COALESCE((SELECT SUM(amount) FROM expenses WHERE MONTH(date) = ? AND YEAR(date) = ?), 0) as expenses
            FROM sales 
            WHERE status = 'Completed' AND MONTH(created_at) = ? AND YEAR(created_at) = ?");
        $sRow->execute([$m, $selectedYear, $m, $selectedYear, $m, $selectedYear]);
        $data = $sRow->fetch();

        $gross = (float) $data['sales'] - (float) $data['cost'];
        $net = $gross - (float) $data['expenses'];

        $yearMonths[] = [
            'month_num' => $m,
            'month_name' => $mLabel,
            'invoices' => (int) $data['invoices'],
            'sales' => (float) $data['sales'],
            'cost' => (float) $data['cost'],
            'gross' => $gross,
            'expenses' => (float) $data['expenses'],
            'net' => $net
        ];

        $yTotalSales += (float) $data['sales'];
        $yTotalCost += (float) $data['cost'];
        $yTotalExp += (float) $data['expenses'];
        $yTotalInvoices += (int) $data['invoices'];
    }

    $yGrossProfit = $yTotalSales - $yTotalCost;
    $yNetProfit = $yGrossProfit - $yTotalExp;
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Report Navigation Tabs & Toolbar -->
<div class="filter-bar no-print">
    <div style="display: flex; gap: 8px;">
        <a href="<?= url('/admin/reports.php?view=daily') ?>" class="btn <?= $view === 'daily' ? 'btn-primary' : 'btn-light' ?>">
            📅 Daily Report
        </a>
        <a href="<?= url('/admin/reports.php?view=monthly') ?>" class="btn <?= $view === 'monthly' ? 'btn-primary' : 'btn-light' ?>">
            🗓️ Monthly Report
        </a>
        <a href="<?= url('/admin/reports.php?view=yearly') ?>" class="btn <?= $view === 'yearly' ? 'btn-primary' : 'btn-light' ?>">
            📊 Yearly Report
        </a>
    </div>

    <!-- Date Selectors -->
    <div style="display: flex; gap: 10px; align-items: center;">
        <?php if ($view === 'daily'): ?>
            <form method="get" style="display: flex; gap: 8px; align-items: center;">
                <input type="hidden" name="view" value="daily">
                <label style="margin: 0; font-size: 13px;">Date:</label>
                <input type="date" name="date" class="form-control" value="<?= e($selectedDate) ?>" onchange="this.form.submit()" style="padding: 6px 12px; width: 170px;">
            </form>
        <?php elseif ($view === 'monthly'): ?>
            <form method="get" style="display: flex; gap: 8px; align-items: center;">
                <input type="hidden" name="view" value="monthly">
                <label style="margin: 0; font-size: 13px;">Month:</label>
                <input type="month" name="month" class="form-control" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()" style="padding: 6px 12px; width: 170px;">
            </form>
        <?php elseif ($view === 'yearly'): ?>
            <form method="get" style="display: flex; gap: 8px; align-items: center;">
                <input type="hidden" name="view" value="yearly">
                <label style="margin: 0; font-size: 13px;">Year:</label>
                <select name="year" class="form-control" onchange="this.form.submit()" style="padding: 6px 12px; width: 120px;">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        <?php endif; ?>

        <button onclick="window.print()" class="btn btn-light" title="Print this report">
            🖨️ Print Report
        </button>
    </div>
</div>

<!-- =============================================================
     1. DAILY REPORT VIEW
     ============================================================= -->
<?php if ($view === 'daily'): ?>
    <div class="card" style="border-top: 4px solid var(--primary); margin-bottom: 24px;">
        <div class="card-header">
            <div>
                <h3>Daily Business Statement for <?= date('l, d F Y', strtotime($selectedDate)) ?></h3>
                <span class="text-muted" style="font-size: 12px;">Full reconciliation of sales, costs, expenses, and net profit</span>
            </div>
            <span class="badge badge-primary"><?= $daySales['invoices'] ?> Invoices</span>
        </div>
        <div class="card-body">
            <!-- Daily KPI Grid -->
            <div class="stats-row" style="margin-bottom: 24px;">
                <div class="stat-box primary">
                    <div class="stat-box-header"><span class="stat-title">Total Sales Revenue</span></div>
                    <div class="stat-value">Rs <?= money($daySales['total_sales']) ?></div>
                    <div class="stat-desc">Cash: <strong>Rs <?= money($daySales['cash_sales']) ?></strong> · Bank: <strong>Rs <?= money($daySales['bank_sales']) ?></strong></div>
                </div>

                <div class="stat-box warning">
                    <div class="stat-box-header"><span class="stat-title">Purchase Cost (COGS)</span></div>
                    <div class="stat-value text-warning">Rs <?= money($dayCogs) ?></div>
                    <div class="stat-desc">Supplier purchase cost of sold items</div>
                </div>

                <div class="stat-box danger">
                    <div class="stat-box-header"><span class="stat-title">Operating Expenses</span></div>
                    <div class="stat-value text-danger">Rs <?= money($dayExpense['total_expense']) ?></div>
                    <div class="stat-desc">Bills, meals, and petty expenses</div>
                </div>

                <div class="stat-box emerald">
                    <div class="stat-box-header"><span class="stat-title">Net Profit Today</span></div>
                    <div class="stat-value" style="color: #059669;">Rs <?= money($netProfit) ?></div>
                    <div class="stat-desc">Gross Profit (Rs <?= money($grossProfit) ?>) - Exp - Refunds</div>
                </div>
            </div>

            <!-- Detailed Profit & Loss Breakdown Table -->
            <div class="table-responsive" style="max-width: 600px; margin: 0 auto 30px;">
                <table class="table" style="border: 1px solid var(--border-color); border-radius: 8px;">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th>Financial Summary Component</th>
                            <th class="text-right">Amount (PKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Gross Sales (Completed Invoices)</td>
                            <td class="text-right font-bold">Rs <?= money($daySales['total_sales']) ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 25px; color: var(--text-secondary);">&minus; Cost of Goods Sold (Purchase Price)</td>
                            <td class="text-right text-muted">&minus; Rs <?= money($dayCogs) ?></td>
                        </tr>
                        <tr style="background: #fafbfc;">
                            <td><strong>Gross Profit Margin</strong></td>
                            <td class="text-right font-bold text-success">Rs <?= money($grossProfit) ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 25px; color: var(--text-secondary);">&minus; Operating Expenses</td>
                            <td class="text-right text-danger">&minus; Rs <?= money($dayExpense['total_expense']) ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 25px; color: var(--text-secondary);">&minus; Returns & Refunds</td>
                            <td class="text-right text-danger">&minus; Rs <?= money($dayReturn['total_refund']) ?></td>
                        </tr>
                        <tr style="background: #f0fdf4; border-top: 2px solid #bbf7d0;">
                            <td><strong style="color: #166534; font-size: 15px;">NET PROFIT</strong></td>
                            <td class="text-right font-bold" style="color: #166534; font-size: 16px;">Rs <?= money($netProfit) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Invoices on that day -->
            <h4 style="margin-bottom: 12px; font-weight: 800;">Invoices Recorded on <?= date('d M Y', strtotime($selectedDate)) ?></h4>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Buyer</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-right">Discount</th>
                            <th class="text-right">Net Payable</th>
                            <th class="text-center">Mode</th>
                            <th>Cashier</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dayInvoices)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted" style="padding: 25px;">No sales on this date.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dayInvoices as $inv): ?>
                                <tr>
                                    <td><code><?= e($inv['invoice_number']) ?></code></td>
                                    <td><strong><?= e($inv['buyer_name'] ?: 'Walk-in') ?></strong></td>
                                    <td class="text-right">Rs <?= money($inv['total_amount']) ?></td>
                                    <td class="text-right text-muted">Rs <?= money($inv['bonus_discount']) ?></td>
                                    <td class="text-right font-bold text-success">Rs <?= money($inv['net_payable']) ?></td>
                                    <td class="text-center"><span class="badge badge-<?= $inv['payment_mode'] === 'Cash' ? 'success' : ($inv['payment_mode'] === 'Bank' ? 'info' : 'warning') ?>"><?= e($inv['payment_mode']) ?></span></td>
                                    <td><?= e($inv['operator_name'] ?? 'System') ?></td>
                                    <td class="text-right">
                                        <a href="<?= url('/print_invoice.php?invoice=' . urlencode($inv['invoice_number'])) ?>" target="_blank" class="btn btn-sm btn-light">Print</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<!-- =============================================================
     2. MONTHLY REPORT VIEW
     ============================================================= -->
<?php elseif ($view === 'monthly'): ?>
    <div class="stats-row" style="margin-bottom: 20px;">
        <div class="stat-box primary">
            <div class="stat-box-header"><span class="stat-title">Month's Sales Revenue</span></div>
            <div class="stat-value">Rs <?= money($mTotalSales) ?></div>
            <div class="stat-desc"><?= $mTotalInvoices ?> Invoices</div>
        </div>
        <div class="stat-box warning">
            <div class="stat-box-header"><span class="stat-title">Month's Product Cost</span></div>
            <div class="stat-value text-warning">Rs <?= money($mTotalCost) ?></div>
            <div class="stat-desc">Purchase inventory cost</div>
        </div>
        <div class="stat-box danger">
            <div class="stat-box-header"><span class="stat-title">Month's Expenses</span></div>
            <div class="stat-value text-danger">Rs <?= money($mTotalExp) ?></div>
            <div class="stat-desc">Shop operating costs</div>
        </div>
        <div class="stat-box emerald">
            <div class="stat-box-header"><span class="stat-title">Month's Net Profit</span></div>
            <div class="stat-value" style="color: #059669;">Rs <?= money($mNetProfit) ?></div>
            <div class="stat-desc">Net profit after all costs</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Day-by-Day Performance for <?= date('F Y', strtotime($selectedMonth . '-01')) ?></h3>
            <span class="badge badge-gray"><?= count($monthDays) ?> Active Days</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-center">Invoices</th>
                            <th class="text-right">Sales Revenue</th>
                            <th class="text-right">Product Cost</th>
                            <th class="text-right">Gross Profit</th>
                            <th class="text-right">Expenses</th>
                            <th class="text-right">Net Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monthDays)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted" style="padding: 35px;">No sales recorded in <?= date('F Y', strtotime($selectedMonth . '-01')) ?>.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($monthDays as $md): 
                                $gross = (float) $md['sales'] - (float) $md['cost'];
                                $net = $gross - (float) $md['expenses'] - (float) $md['refunds'];
                            ?>
                                <tr>
                                    <td>
                                        <a href="<?= url('/admin/reports.php?view=daily&date=' . $md['day_date']) ?>" style="color: var(--primary); font-weight: 700;">
                                            <?= date('D, d M Y', strtotime($md['day_date'])) ?>
                                        </a>
                                    </td>
                                    <td class="text-center"><span class="badge badge-gray"><?= $md['invoices'] ?></span></td>
                                    <td class="text-right font-bold text-success">Rs <?= money($md['sales']) ?></td>
                                    <td class="text-right text-muted">Rs <?= money($md['cost']) ?></td>
                                    <td class="text-right font-bold">Rs <?= money($gross) ?></td>
                                    <td class="text-right text-danger">Rs <?= money($md['expenses']) ?></td>
                                    <td class="text-right font-bold" style="color: <?= $net >= 0 ? '#059669' : '#dc2626' ?>;">
                                        Rs <?= money($net) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<!-- =============================================================
     3. YEARLY REPORT VIEW
     ============================================================= -->
<?php elseif ($view === 'yearly'): ?>
    <div class="stats-row" style="margin-bottom: 20px;">
        <div class="stat-box primary">
            <div class="stat-box-header"><span class="stat-title">Annual Sales Revenue</span></div>
            <div class="stat-value">Rs <?= money($yTotalSales) ?></div>
            <div class="stat-desc"><?= number_format($yTotalInvoices) ?> Annual Invoices</div>
        </div>
        <div class="stat-box warning">
            <div class="stat-box-header"><span class="stat-title">Annual Cost of Goods</span></div>
            <div class="stat-value text-warning">Rs <?= money($yTotalCost) ?></div>
            <div class="stat-desc">Inventory cost</div>
        </div>
        <div class="stat-box danger">
            <div class="stat-box-header"><span class="stat-title">Annual Expenses</span></div>
            <div class="stat-value text-danger">Rs <?= money($yTotalExp) ?></div>
            <div class="stat-desc">Total store expenditures</div>
        </div>
        <div class="stat-box emerald">
            <div class="stat-box-header"><span class="stat-title">Annual Net Profit</span></div>
            <div class="stat-value" style="color: #059669;">Rs <?= money($yNetProfit) ?></div>
            <div class="stat-desc">Cumulative net profit in <?= $selectedYear ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Month-by-Month Statement for Year <?= $selectedYear ?></h3>
            <span class="badge badge-primary">12 Months Overview</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="text-center">Invoices</th>
                            <th class="text-right">Sales Revenue</th>
                            <th class="text-right">Cost of Goods</th>
                            <th class="text-right">Gross Profit</th>
                            <th class="text-right">Operating Expenses</th>
                            <th class="text-right">Net Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($yearMonths as $ym): ?>
                            <tr>
                                <td>
                                    <strong><?= $ym['month_name'] ?></strong>
                                </td>
                                <td class="text-center"><?= $ym['invoices'] ?></td>
                                <td class="text-right font-bold text-success">Rs <?= money($ym['sales']) ?></td>
                                <td class="text-right text-muted">Rs <?= money($ym['cost']) ?></td>
                                <td class="text-right font-bold">Rs <?= money($ym['gross']) ?></td>
                                <td class="text-right text-danger">Rs <?= money($ym['expenses']) ?></td>
                                <td class="text-right font-bold" style="color: <?= $ym['net'] >= 0 ? '#059669' : '#dc2626' ?>;">
                                    Rs <?= money($ym['net']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
