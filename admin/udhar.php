<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(); // accessible by Admin and Operator

$pdo = db();
$user = current_user();
$page_title = 'Udhar Khata (Customer Credit Management)';

// Handle Payment Recovery
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');

    if ($action === 'receive_payment') {
        $udharId = post_int('udhar_id');
        $amount = post_float('amount');
        $mode = post_string('payment_mode', 'Cash');
        $date = post_string('date', date('Y-m-d'));
        $notes = post_string('notes');

        if ($udharId && $amount > 0) {
            $pdo->beginTransaction();
            try {
                $uStmt = $pdo->prepare("SELECT * FROM udhar_ledger WHERE id = ? FOR UPDATE");
                $uStmt->execute([$udharId]);
                $udhar = $uStmt->fetch();

                if (!$udhar) {
                    throw new RuntimeException('Udhar record not found.');
                }

                $newPaid = (float) $udhar['paid_amount'] + $amount;
                $newBalance = max(0.0, (float) $udhar['total_amount'] - $newPaid);
                $newStatus = $newBalance <= 0 ? 'Cleared' : 'Pending';

                // Update ledger
                $upStmt = $pdo->prepare("UPDATE udhar_ledger SET paid_amount = ?, balance_amount = ?, status = ? WHERE id = ?");
                $upStmt->execute([$newPaid, $newBalance, $newStatus, $udharId]);

                // Insert into udhar_payments
                $receiptNo = 'UPR-' . date('ymd') . '-' . random_int(1000, 9999);
                $pStmt = $pdo->prepare("INSERT INTO udhar_payments (udhar_id, sale_id, amount_paid, payment_mode, receipt_number, date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $pStmt->execute([$udharId, $udhar['sale_id'], $amount, $mode, $receiptNo, $date, $notes, $user['id']]);
                $paymentId = (int) $pdo->lastInsertId();

                $pdo->commit();
                flash('success', "Udhar recovery payment of Rs " . money($amount) . " recorded successfully! Receipt # $receiptNo");
                redirect("/POS_software/print_udhar_receipt.php?id=$paymentId&autoprint=1");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', $e->getMessage());
            }
        } else {
            flash('error', 'Please enter a valid amount.');
        }
    }

    redirect('/POS_software/admin/udhar.php');
}

// Udhar Summary
$totalUdharGiven = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM udhar_ledger")->fetchColumn();
$totalUdharRecovered = (float) $pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM udhar_ledger")->fetchColumn();
$totalOutstanding = (float) $pdo->query("SELECT COALESCE(SUM(balance_amount), 0) FROM udhar_ledger WHERE status = 'Pending'")->fetchColumn();

$todayGiven = (float) $pdo->query("SELECT COALESCE(SUM(ul.total_amount), 0) FROM udhar_ledger ul JOIN sales s ON s.id = ul.sale_id WHERE DATE(s.created_at) = CURDATE()")->fetchColumn();
$todayRecovered = (float) $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM udhar_payments WHERE date = CURDATE()")->fetchColumn();

// Fetch Udhar ledger items
$filter = $_GET['status'] ?? '';
$where = '';
if ($filter === 'pending') {
    $where = "WHERE ul.status = 'Pending'";
} elseif ($filter === 'cleared') {
    $where = "WHERE ul.status = 'Cleared'";
}

$udhars = $pdo->query("SELECT ul.*, s.invoice_number, s.created_at as sale_date 
    FROM udhar_ledger ul 
    JOIN sales s ON s.id = ul.sale_id 
    $where 
    ORDER BY ul.status ASC, ul.id DESC")->fetchAll();

// Fetch recent payment receipts
$recentRecoveries = $pdo->query("SELECT up.*, ul.buyer_name, ul.buyer_phone, s.invoice_number, u.username as recorded_by_user 
    FROM udhar_payments up 
    JOIN udhar_ledger ul ON ul.id = up.udhar_id 
    JOIN sales s ON s.id = up.sale_id 
    LEFT JOIN users u ON u.id = up.created_by 
    ORDER BY up.id DESC 
    LIMIT 15")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Udhar Summary Cards -->
<div class="stats-row" style="margin-bottom: 20px;">
    <div class="stat-box warning">
        <div class="stat-box-header"><span class="stat-title">Outstanding Udhar Balance</span></div>
        <div class="stat-value text-warning">Rs <?= money($totalOutstanding) ?></div>
        <div class="stat-desc">Total unpaid credit due from customers</div>
    </div>
    <div class="stat-box success">
        <div class="stat-box-header"><span class="stat-title">Total Recovered / Wasool</span></div>
        <div class="stat-value text-success">Rs <?= money($totalUdharRecovered) ?></div>
        <div class="stat-desc">Credit payments received so far</div>
    </div>
    <div class="stat-box primary">
        <div class="stat-box-header"><span class="stat-title">Today's Udhar Sales</span></div>
        <div class="stat-value">Rs <?= money($todayGiven) ?></div>
        <div class="stat-desc">New credit invoices issued today</div>
    </div>
    <div class="stat-box emerald">
        <div class="stat-box-header"><span class="stat-title">Today's Recoveries</span></div>
        <div class="stat-value" style="color: #059669;">Rs <?= money($todayRecovered) ?></div>
        <div class="stat-desc">Cash/Bank collected from debtors today</div>
    </div>
</div>

<!-- Controls -->
<div class="filter-bar">
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" data-table-search="[data-udhar-row]" placeholder="Search customer name, phone, invoice...">
        </div>

        <div style="display: flex; gap: 6px;">
            <a href="/POS_software/admin/udhar.php" class="btn btn-sm <?= empty($filter) ? 'btn-primary' : 'btn-light' ?>">All Accounts</a>
            <a href="/POS_software/admin/udhar.php?status=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-warning' : 'btn-light' ?>">Pending Only</a>
            <a href="/POS_software/admin/udhar.php?status=cleared" class="btn btn-sm <?= $filter === 'cleared' ? 'btn-success' : 'btn-light' ?>">Cleared Only</a>
        </div>
    </div>
</div>

<!-- Udhar Accounts Table -->
<div class="card">
    <div class="card-header">
        <h3>Customer Credit Accounts (<?= count($udhars) ?>)</h3>
        <span class="text-muted" style="font-size: 12px;">Track individual debtor balances and record partial wasoolis</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Customer / Buyer</th>
                        <th>Invoice #</th>
                        <th>Sale Date</th>
                        <th class="text-right">Total Bill</th>
                        <th class="text-right">Paid Amount</th>
                        <th class="text-right">Remaining Balance</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($udhars)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted" style="padding: 35px;">
                                No customer Udhar records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($udhars as $u): ?>
                            <tr data-udhar-row>
                                <td>
                                    <strong><?= e($u['buyer_name'] ?: 'Walk-in Customer') ?></strong>
                                    <?php if ($u['buyer_phone']): ?>
                                        <br><small class="text-muted"><?= e($u['buyer_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/POS_software/print_invoice.php?invoice=<?= urlencode($u['invoice_number']) ?>" target="_blank" style="color: var(--primary); font-weight: 700;">
                                        <?= e($u['invoice_number']) ?>
                                    </a>
                                </td>
                                <td><?= date('d M Y', strtotime($u['sale_date'])) ?></td>
                                <td class="text-right">Rs <?= money($u['total_amount']) ?></td>
                                <td class="text-right text-success">Rs <?= money($u['paid_amount']) ?></td>
                                <td class="text-right font-bold text-warning" style="font-size: 14px;">
                                    Rs <?= money($u['balance_amount']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-<?= $u['status'] === 'Cleared' ? 'success' : 'warning' ?>">
                                        <?= e($u['status']) ?>
                                    </span>
                                </td>
                                <td class="text-right">
                                    <?php if ($u['status'] === 'Pending'): ?>
                                        <button type="button" class="btn btn-sm btn-success" 
                                            onclick="openReceiveUdharModal(<?= $u['id'] ?>, '<?= e(addslashes($u['buyer_name'])) ?>', <?= $u['balance_amount'] ?>)">
                                            Receive Payment
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size: 12px;">Fully Paid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Recent Wasooli Payments History -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3>Udhar Collection History (Wasooli Log)</h3>
        <span class="badge badge-gray">Recent Collections</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Customer</th>
                        <th>Invoice Ref</th>
                        <th>Date</th>
                        <th>Mode</th>
                        <th class="text-right">Amount Received</th>
                        <th>Received By</th>
                        <th>Notes</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentRecoveries)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted" style="padding: 25px;">
                                No Udhar recoveries recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentRecoveries as $rec): ?>
                            <tr>
                                <td><code><?= e($rec['receipt_number']) ?></code></td>
                                <td>
                                    <strong><?= e($rec['buyer_name']) ?></strong>
                                    <?php if ($rec['buyer_phone']): ?>
                                        <br><small class="text-muted"><?= e($rec['buyer_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($rec['invoice_number']) ?></td>
                                <td><?= date('d M Y', strtotime($rec['date'])) ?></td>
                                <td>
                                    <span class="badge badge-<?= $rec['payment_mode'] === 'Cash' ? 'success' : 'info' ?>">
                                        <?= e($rec['payment_mode']) ?>
                                    </span>
                                </td>
                                <td class="text-right font-bold text-success">Rs <?= money($rec['amount_paid']) ?></td>
                                <td><?= e($rec['recorded_by_user'] ?? 'System') ?></td>
                                <td><small class="text-muted"><?= e($rec['notes'] ?: '—') ?></small></td>
                                <td class="text-right">
                                    <a href="/POS_software/print_udhar_receipt.php?id=<?= $rec['id'] ?>" target="_blank" class="btn btn-sm btn-light">
                                        Print Slip
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

<!-- ==================== RECEIVE PAYMENT MODAL ==================== -->
<div id="receiveUdharModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Receive Udhar Payment (Wasooli)</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="receive_payment">
                <input type="hidden" name="udhar_id" id="receive_udhar_id">

                <p style="margin-bottom: 12px;">Customer: <strong id="receive_buyer_name"></strong></p>
                <div class="alert alert-warning" style="padding: 10px 14px; margin-bottom: 16px;">
                    Outstanding Balance: <strong id="receive_balance_display">Rs 0.00</strong>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Amount Received (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="receive_amount" class="form-control" placeholder="Enter amount received" required>
                    </div>

                    <div class="form-group">
                        <label>Payment Mode</label>
                        <select name="payment_mode" class="form-control" required>
                            <option value="Cash">Cash (Adds to Drawer Cash)</option>
                            <option value="Bank">Bank Transfer / Online</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Collection Date</label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Notes / Remarks</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-success">Record Collection & Print Voucher</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReceiveUdharModal(id, buyerName, balance) {
    document.getElementById('receive_udhar_id').value = id;
    document.getElementById('receive_buyer_name').textContent = buyerName || 'Walk-in Customer';
    document.getElementById('receive_balance_display').textContent = 'Rs ' + Number(balance).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('receive_amount').value = balance;
    document.getElementById('receiveUdharModal').classList.add('active');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
