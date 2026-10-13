<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Operator');

$pdo = db();
$user = current_user();
$page_title = 'Pay Dealer / Supplier';

// Handle payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $dealerId = post_int('dealer_id');
    $amount = post_float('paid_amount');
    $mode = post_string('payment_mode', 'Cash');
    $date = post_string('date', date('Y-m-d'));
    $notes = post_string('notes');

    if ($dealerId && $amount > 0) {
        $pdo->beginTransaction();
        try {
            $dStmt = $pdo->prepare("SELECT name, pending_payable_amount FROM dealers WHERE id = ? FOR UPDATE");
            $dStmt->execute([$dealerId]);
            $dealer = $dStmt->fetch();

            if (!$dealer) {
                throw new RuntimeException('Dealer not found.');
            }

            $receiptNo = 'DPR-' . date('ymd') . '-' . random_int(1000, 9999);
            $pStmt = $pdo->prepare("INSERT INTO dealer_payments (dealer_id, paid_amount, payment_mode, receipt_number, date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $pStmt->execute([$dealerId, $amount, $mode, $receiptNo, $date, $notes, $user['id']]);
            $paymentId = (int) $pdo->lastInsertId();

            $newBalance = max(0.0, (float) $dealer['pending_payable_amount'] - $amount);
            $upStmt = $pdo->prepare("UPDATE dealers SET pending_payable_amount = ? WHERE id = ?");
            $upStmt->execute([$newBalance, $dealerId]);

            $pdo->commit();
            flash('success', "Payment of Rs " . money($amount) . " to {$dealer['name']} recorded successfully! Receipt # $receiptNo");
            redirect("/POS_software/print_dealer_receipt.php?id=$paymentId&autoprint=1");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $e->getMessage());
        }
    } else {
        flash('error', 'Please enter a valid payment amount.');
    }
    redirect('/POS_software/operator/dealers.php');
}

$dealers = $pdo->query("SELECT * FROM dealers ORDER BY name ASC")->fetchAll();
$recentPayments = $pdo->query("SELECT dp.*, d.name as dealer_name, d.company_name 
    FROM dealer_payments dp 
    JOIN dealers d ON d.id = dp.dealer_id 
    ORDER BY dp.id DESC 
    LIMIT 10")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3>Disburse Dealer Payment (Partial / Full)</h3>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

                    <div class="form-group">
                        <label>Select Supplier / Dealer <span class="text-danger">*</span></label>
                        <select name="dealer_id" id="dealerSelect" class="form-control" required onchange="updatePayable(this)">
                            <option value="">-- Choose Dealer --</option>
                            <?php foreach ($dealers as $d): ?>
                                <option value="<?= $d['id'] ?>" data-payable="<?= $d['pending_payable_amount'] ?>">
                                    <?= e($d['name']) ?> (Payable: Rs <?= money($d['pending_payable_amount']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="payableDisplayBox" class="alert alert-warning" style="display:none; padding: 10px 14px;">
                        Remaining Payable to this Dealer: <strong id="payableAmt">Rs 0.00</strong>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Amount to Pay (Rs) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="paid_amount" class="form-control" placeholder="Enter amount" required>
                        </div>

                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode" class="form-control" required>
                                <option value="Cash">Cash (Deducts from Till Drawer)</option>
                                <option value="Bank">Bank Account / Transfer</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Remarks / Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success btn-block">
                        Disburse Payment & Print Receipt Slip
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3>Recent Dealer Payments</h3>
                <span class="badge badge-gray">Latest 10</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Receipt #</th>
                                <th>Dealer</th>
                                <th>Mode</th>
                                <th class="text-right">Paid</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentPayments)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted" style="padding: 30px;">
                                        No recent dealer payments.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentPayments as $rp): ?>
                                    <tr>
                                        <td><code><?= e($rp['receipt_number']) ?></code></td>
                                        <td>
                                            <strong><?= e($rp['dealer_name']) ?></strong>
                                            <br><small class="text-muted"><?= date('d M Y', strtotime($rp['date'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= $rp['payment_mode'] === 'Cash' ? 'success' : 'info' ?>">
                                                <?= e($rp['payment_mode']) ?>
                                            </span>
                                        </td>
                                        <td class="text-right font-bold text-success">
                                            Rs <?= money($rp['paid_amount']) ?>
                                        </td>
                                        <td class="text-right">
                                            <a href="/POS_software/print_dealer_receipt.php?id=<?= $rp['id'] ?>" target="_blank" class="btn btn-sm btn-light">
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
function updatePayable(sel) {
    const opt = sel.options[sel.selectedIndex];
    const box = document.getElementById('payableDisplayBox');
    const amt = document.getElementById('payableAmt');
    if (opt && opt.dataset.payable) {
        box.style.display = 'block';
        amt.textContent = 'Rs ' + Number(opt.dataset.payable).toLocaleString('en-US', {minimumFractionDigits: 2});
    } else {
        box.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
