<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Operator');

$pdo = db();
$user = current_user();
$page_title = 'Customer Udhar Collection (Wasooli)';

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
                flash('success', "Payment of Rs " . money($amount) . " received from '{$udhar['buyer_name']}'! Receipt # $receiptNo");
                redirect("/POS_software/print_udhar_receipt.php?id=$paymentId&autoprint=1");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', $e->getMessage());
            }
        } else {
            flash('error', 'Please enter a valid amount.');
        }
    }

    redirect('/POS_software/operator/udhar.php');
}

// Summary Metrics
$totalOutstanding = (float) $pdo->query("SELECT COALESCE(SUM(balance_amount), 0) FROM udhar_ledger WHERE status = 'Pending'")->fetchColumn();
$todayRecovered = (float) $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM udhar_payments WHERE date = CURDATE()")->fetchColumn();

// Filter
$statusFilter = $_GET['status'] ?? 'pending';
$where = $statusFilter === 'pending' ? "WHERE ul.status = 'Pending'" : ($statusFilter === 'cleared' ? "WHERE ul.status = 'Cleared'" : "");

$udhars = $pdo->query("SELECT ul.*, s.invoice_number, s.created_at as sale_date 
    FROM udhar_ledger ul 
    JOIN sales s ON s.id = ul.sale_id 
    $where 
    ORDER BY ul.status ASC, ul.balance_amount DESC")->fetchAll();

$recentRecoveries = $pdo->query("SELECT up.*, ul.buyer_name, ul.buyer_phone, s.invoice_number 
    FROM udhar_payments up 
    JOIN udhar_ledger ul ON ul.id = up.udhar_id 
    JOIN sales s ON s.id = up.sale_id 
    ORDER BY up.id DESC 
    LIMIT 10")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="stats-row" style="margin-bottom: 20px;">
    <div class="stat-box warning">
        <div class="stat-box-header"><span class="stat-title">Pending Udhar to Collect</span></div>
        <div class="stat-value text-warning">Rs <?= money($totalOutstanding) ?></div>
        <div class="stat-desc">Remaining customer balances</div>
    </div>
    <div class="stat-box success">
        <div class="stat-box-header"><span class="stat-title">Collected Today (Wasooli)</span></div>
        <div class="stat-value text-success">Rs <?= money($todayRecovered) ?></div>
        <div class="stat-desc">Cash/Bank recovered today</div>
    </div>
</div>

<div class="filter-bar">
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" data-table-search="[data-udhar-row]" placeholder="Search customer by name, phone, or invoice...">
        </div>

        <div style="display: flex; gap: 6px;">
            <a href="/POS_software/operator/udhar.php?status=pending" class="btn btn-sm <?= $statusFilter === 'pending' ? 'btn-warning' : 'btn-light' ?>">Pending Accounts</a>
            <a href="/POS_software/operator/udhar.php?status=cleared" class="btn btn-sm <?= $statusFilter === 'cleared' ? 'btn-success' : 'btn-light' ?>">Cleared Accounts</a>
            <a href="/POS_software/operator/udhar.php?status=all" class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-light' ?>">All Accounts</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Customer Credit (Udhar) Accounts</h3>
        <span class="text-muted" style="font-size: 12px;">Select an account to collect payment and print voucher</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Customer / Buyer</th>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th class="text-right">Total Bill</th>
                        <th class="text-right">Paid So Far</th>
                        <th class="text-right">Balance Due</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($udhars)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted" style="padding: 35px;">
                                No customer Udhar records found matching criteria.
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
                                        <span class="text-muted" style="font-size: 12px;">Fully Cleared</span>
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

<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3>Recent Wasooli Recoveries</h3>
        <span class="badge badge-gray">Latest 10</span>
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
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentRecoveries)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted" style="padding: 25px;">
                                No recent wasooli payments recorded.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentRecoveries as $rec): ?>
                            <tr>
                                <td><code><?= e($rec['receipt_number']) ?></code></td>
                                <td><strong><?= e($rec['buyer_name']) ?></strong></td>
                                <td><?= e($rec['invoice_number']) ?></td>
                                <td><?= date('d M Y', strtotime($rec['date'])) ?></td>
                                <td><span class="badge badge-<?= $rec['payment_mode'] === 'Cash' ? 'success' : 'info' ?>"><?= e($rec['payment_mode']) ?></span></td>
                                <td class="text-right font-bold text-success">Rs <?= money($rec['amount_paid']) ?></td>
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

<!-- ==================== RECEIVE MODAL ==================== -->
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

                <p style="margin-bottom: 10px;">Customer: <strong id="receive_buyer_name"></strong></p>
                <div class="alert alert-warning" style="padding: 10px 14px; margin-bottom: 16px;">
                    Outstanding Balance: <strong id="receive_balance_display">Rs 0.00</strong>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Amount Received (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="receive_amount" class="form-control" placeholder="Enter amount" required>
                    </div>

                    <div class="form-group">
                        <label>Payment Mode</label>
                        <select name="payment_mode" class="form-control" required>
                            <option value="Cash">Cash (Adds to Till Drawer)</option>
                            <option value="Bank">Bank Account / Online</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Date</label>
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
                <button type="submit" class="btn btn-success">Receive & Print Voucher</button>
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
