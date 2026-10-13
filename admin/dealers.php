<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(); // accessible by Admin and Operator (for paying dealer)

$pdo = db();
$user = current_user();
$isAdmin = is_admin();
$page_title = 'Dealers & Supplier Payables';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');

    if ($action === 'add' && $isAdmin) {
        $name = post_string('name');
        $phone = post_string('phone');
        $company = post_string('company_name');
        $productsSupplied = post_string('products_supplied');
        $payable = post_float('pending_payable_amount');

        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO dealers (name, phone, company_name, products_supplied, pending_payable_amount) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $company, $productsSupplied, $payable]);
            flash('success', "Dealer '$name' added successfully!");
        } else {
            flash('error', 'Dealer name is required.');
        }
    } elseif ($action === 'edit' && $isAdmin) {
        $id = post_int('id');
        $name = post_string('name');
        $phone = post_string('phone');
        $company = post_string('company_name');
        $productsSupplied = post_string('products_supplied');
        $payable = post_float('pending_payable_amount');

        if ($id && $name) {
            $stmt = $pdo->prepare("UPDATE dealers SET name = ?, phone = ?, company_name = ?, products_supplied = ?, pending_payable_amount = ? WHERE id = ?");
            $stmt->execute([$name, $phone, $company, $productsSupplied, $payable, $id]);
            flash('success', "Dealer '$name' updated successfully!");
        }
    } elseif ($action === 'pay') {
        // Payment to dealer (accessible to both Admin & Operator!)
        $dealerId = post_int('dealer_id');
        $amount = post_float('paid_amount');
        $mode = post_string('payment_mode', 'Cash');
        $date = post_string('date', date('Y-m-d'));
        $notes = post_string('notes');

        if ($dealerId && $amount > 0) {
            $pdo->beginTransaction();
            try {
                // Fetch dealer current payable
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

                // Deduct from dealer pending payable
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
    } elseif ($action === 'delete' && $isAdmin) {
        $id = post_int('id');
        if ($id) {
            $pdo->prepare("DELETE FROM dealers WHERE id = ?")->execute([$id]);
            flash('success', 'Dealer deleted successfully.');
        }
    }

    redirect('/POS_software/admin/dealers.php');
}

// Fetch dealers with total paid and active products count
$dealers = $pdo->query("SELECT d.*, 
    COALESCE((SELECT SUM(paid_amount) FROM dealer_payments WHERE dealer_id = d.id), 0) as total_paid,
    COALESCE((SELECT COUNT(*) FROM products WHERE dealer_id = d.id), 0) as products_count
    FROM dealers d 
    ORDER BY d.id DESC")->fetchAll();

$totalPayableAll = 0;
$totalPaidAll = 0;
foreach ($dealers as $d) {
    $totalPayableAll += (float) $d['pending_payable_amount'];
    $totalPaidAll += (float) $d['total_paid'];
}

// Fetch recent dealer payments
$payments = $pdo->query("SELECT dp.*, d.name as dealer_name, d.company_name, u.username as created_by_user 
    FROM dealer_payments dp 
    JOIN dealers d ON d.id = dp.dealer_id 
    LEFT JOIN users u ON u.id = dp.created_by 
    ORDER BY dp.id DESC 
    LIMIT 20")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Dealers Summary Cards -->
<div class="stats-row" style="margin-bottom: 20px;">
    <div class="stat-box primary">
        <div class="stat-box-header"><span class="stat-title">Total Dealers</span></div>
        <div class="stat-value"><?= count($dealers) ?></div>
        <div class="stat-desc">Active registered suppliers</div>
    </div>
    <div class="stat-box warning">
        <div class="stat-box-header"><span class="stat-title">Total Pending Payable</span></div>
        <div class="stat-value text-warning">Rs <?= money($totalPayableAll) ?></div>
        <div class="stat-desc">Amount we owe to dealers</div>
    </div>
    <div class="stat-box success">
        <div class="stat-box-header"><span class="stat-title">Total Payments Made</span></div>
        <div class="stat-value text-success">Rs <?= money($totalPaidAll) ?></div>
        <div class="stat-desc">Lifetime payments made</div>
    </div>
</div>

<!-- Toolbar -->
<div class="filter-bar">
    <div class="search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" data-table-search="[data-dealer-row]" placeholder="Search dealers by name, company, phone...">
    </div>

    <div style="display: flex; gap: 8px;">
        <button type="button" class="btn btn-success" data-modal-open="payDealerModal">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Pay Dealer (Partial / Full)
        </button>
        <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-primary" data-modal-open="addDealerModal">
                + Add New Dealer
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Dealers Table -->
<div class="card">
    <div class="card-header">
        <h3>Registered Dealers & Suppliers (<?= count($dealers) ?>)</h3>
        <span class="text-muted" style="font-size: 12px;">Track supplier ledger and partial disbursements</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Dealer Name</th>
                        <th>Company</th>
                        <th>Contact Phone</th>
                        <th>Supplied Products</th>
                        <th class="text-right">Total Paid</th>
                        <th class="text-right">Remaining Payable</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dealers)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted" style="padding: 35px;">
                                No dealers registered yet. Click <strong>+ Add New Dealer</strong> to create your first supplier profile.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dealers as $d): ?>
                            <tr data-dealer-row>
                                <td><strong><?= e($d['name']) ?></strong></td>
                                <td><?= e($d['company_name'] ?: '—') ?></td>
                                <td><?= e($d['phone'] ?: '—') ?></td>
                                <td>
                                    <?php if ($d['products_supplied']): ?>
                                        <small><?= e($d['products_supplied']) ?></small>
                                    <?php else: ?>
                                        <span class="badge badge-gray"><?= $d['products_count'] ?> products listed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right text-success font-bold">Rs <?= money($d['total_paid']) ?></td>
                                <td class="text-right font-bold text-warning" style="font-size: 14px;">
                                    Rs <?= money($d['pending_payable_amount']) ?>
                                </td>
                                <td class="text-right">
                                    <div style="display: inline-flex; gap: 6px;">
                                        <!-- Quick Pay button -->
                                        <button type="button" class="btn btn-sm btn-success" 
                                            onclick="openPayDealerModal(<?= $d['id'] ?>, '<?= e(addslashes($d['name'])) ?>', <?= $d['pending_payable_amount'] ?>)">
                                            Pay
                                        </button>

                                        <?php if ($isAdmin): ?>
                                            <!-- Edit button -->
                                            <button type="button" class="btn btn-sm btn-light"
                                                onclick="openEditDealerModal(<?= htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8') ?>)">
                                                Edit
                                            </button>

                                            <!-- Delete Form -->
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete dealer \'<?= e(addslashes($d['name'])) ?>\'?');">
                                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">✕</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Payment History Ledger -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3>Dealer Payment History (Ledger & Receipts)</h3>
        <span class="badge badge-gray">Latest 20 Payments</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Dealer</th>
                        <th>Date</th>
                        <th>Mode</th>
                        <th class="text-right">Amount Paid</th>
                        <th>Paid By</th>
                        <th>Notes</th>
                        <th class="text-right">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted" style="padding: 30px;">
                                No payments made to dealers yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><code><?= e($pay['receipt_number']) ?></code></td>
                                <td>
                                    <strong><?= e($pay['dealer_name']) ?></strong>
                                    <?php if ($pay['company_name']): ?>
                                        <br><small class="text-muted"><?= e($pay['company_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d M Y', strtotime($pay['date'])) ?></td>
                                <td>
                                    <span class="badge badge-<?= $pay['payment_mode'] === 'Cash' ? 'success' : 'info' ?>">
                                        <?= e($pay['payment_mode']) ?>
                                    </span>
                                </td>
                                <td class="text-right font-bold text-success">Rs <?= money($pay['paid_amount']) ?></td>
                                <td><?= e($pay['created_by_user'] ?? 'System') ?></td>
                                <td><small class="text-muted"><?= e($pay['notes'] ?: '—') ?></small></td>
                                <td class="text-right">
                                    <a href="/POS_software/print_dealer_receipt.php?id=<?= $pay['id'] ?>" target="_blank" class="btn btn-sm btn-light">
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

<!-- ==================== PAY DEALER MODAL ==================== -->
<div id="payDealerModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Make Payment to Dealer</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="pay">

                <div class="form-group">
                    <label>Select Dealer <span class="text-danger">*</span></label>
                    <select name="dealer_id" id="pay_dealer_id" class="form-control" required onchange="updatePayableHint(this)">
                        <option value="">-- Choose Dealer --</option>
                        <?php foreach ($dealers as $d): ?>
                            <option value="<?= $d['id'] ?>" data-payable="<?= $d['pending_payable_amount'] ?>">
                                <?= e($d['name']) ?> (Remaining: Rs <?= money($d['pending_payable_amount']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="payableHintBox" class="alert alert-warning" style="display:none; padding: 10px 14px; margin-bottom: 14px;">
                    Current Pending Payable: <strong id="payableHintAmount">Rs 0.00</strong>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Amount to Pay (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="paid_amount" id="pay_amount" class="form-control" placeholder="Enter payment amount" required>
                    </div>

                    <div class="form-group">
                        <label>Payment Mode</label>
                        <select name="payment_mode" class="form-control" required>
                            <option value="Cash">Cash (Deducts from Till Drawer)</option>
                            <option value="Bank">Bank Transfer / Cheque</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Payment Date</label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Slip / Cheque / Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes / reference">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-success">Disburse Payment & Print Receipt</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== ADD DEALER MODAL ==================== -->
<?php if ($isAdmin): ?>
<div id="addDealerModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Dealer / Supplier</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add">

                <div class="form-group">
                    <label>Dealer / Person Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Haji Muhammad Aslam" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Company / Agency Name</label>
                        <input type="text" name="company_name" class="form-control" placeholder="e.g. Aslam Foods & Distributors">
                    </div>

                    <div class="form-group">
                        <label>Contact Phone Number</label>
                        <input type="text" name="phone" class="form-control" placeholder="e.g. 0300-9876543">
                    </div>
                </div>

                <div class="form-group">
                    <label>Supplied Products (Optional)</label>
                    <input type="text" name="products_supplied" class="form-control" placeholder="e.g. Rice, Sugar, Cooking Oil, Beverages">
                </div>

                <div class="form-group">
                    <label>Opening Pending Payable Balance (Rs)</label>
                    <input type="number" step="0.01" name="pending_payable_amount" class="form-control" value="0.00">
                    <small class="text-muted">Kitne paise hamne pehle se dene hain</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Dealer</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== EDIT DEALER MODAL ==================== -->
<div id="editDealerModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Dealer Profile</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_dealer_id">

                <div class="form-group">
                    <label>Dealer Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="edit_dealer_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Company Name</label>
                        <input type="text" name="company_name" id="edit_dealer_company" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Contact Phone</label>
                        <input type="text" name="phone" id="edit_dealer_phone" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label>Supplied Products</label>
                    <input type="text" name="products_supplied" id="edit_dealer_products" class="form-control">
                </div>

                <div class="form-group">
                    <label>Pending Payable Balance (Rs)</label>
                    <input type="number" step="0.01" name="pending_payable_amount" id="edit_dealer_payable" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-success">Update Dealer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openPayDealerModal(dealerId, name, payable) {
    const sel = document.getElementById('pay_dealer_id');
    sel.value = dealerId;
    updatePayableHint(sel);
    document.getElementById('pay_amount').value = payable > 0 ? payable : '';
    document.getElementById('payDealerModal').classList.add('active');
}

function updatePayableHint(select) {
    const opt = select.options[select.selectedIndex];
    const hintBox = document.getElementById('payableHintBox');
    const hintAmount = document.getElementById('payableHintAmount');
    if (opt && opt.dataset.payable) {
        hintBox.style.display = 'block';
        hintAmount.textContent = 'Rs ' + Number(opt.dataset.payable).toLocaleString('en-US', {minimumFractionDigits: 2});
    } else {
        hintBox.style.display = 'none';
    }
}

function openEditDealerModal(dealer) {
    document.getElementById('edit_dealer_id').value = dealer.id;
    document.getElementById('edit_dealer_name').value = dealer.name;
    document.getElementById('edit_dealer_company').value = dealer.company_name || '';
    document.getElementById('edit_dealer_phone').value = dealer.phone || '';
    document.getElementById('edit_dealer_products').value = dealer.products_supplied || '';
    document.getElementById('edit_dealer_payable').value = dealer.pending_payable_amount;
    document.getElementById('editDealerModal').classList.add('active');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
