<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(); // accessible by Operator and Admin

$pdo = db();
$user = current_user();
$page_title = 'Sales Returns & Refunds';

$invoiceSearch = post_string('invoice_number') ?: ($_GET['invoice'] ?? '');
$sale = null;
$saleItems = [];
$message = '';

if ($invoiceSearch) {
    $sStmt = $pdo->prepare("SELECT s.*, u.username as operator_name 
        FROM sales s 
        LEFT JOIN users u ON u.id = s.operator_id 
        WHERE s.invoice_number = ? LIMIT 1");
    $sStmt->execute([$invoiceSearch]);
    $sale = $sStmt->fetch();

    if ($sale) {
        $siStmt = $pdo->prepare("SELECT si.*, p.name as product_name, p.stock_quantity as current_stock,
            COALESCE((SELECT SUM(r.quantity) FROM returns r WHERE r.sale_id = si.sale_id AND r.product_id = si.product_id), 0) as returned_qty 
            FROM sale_items si 
            JOIN products p ON p.id = si.product_id 
            WHERE si.sale_id = ?");
        $siStmt->execute([$sale['id']]);
        $saleItems = $siStmt->fetchAll();
    } else {
        $message = "Invoice '$invoiceSearch' not found.";
    }
}

// Process Return Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_return') {
    verify_csrf();
    $saleId = post_int('sale_id');
    $productId = post_int('product_id');
    $returnQty = post_int('quantity');
    $reason = post_string('reason');

    if ($saleId && $productId && $returnQty > 0) {
        $pdo->beginTransaction();
        try {
            // Check item validity
            $itemStmt = $pdo->prepare("SELECT si.*, p.name as product_name,
                COALESCE((SELECT SUM(r.quantity) FROM returns r WHERE r.sale_id = si.sale_id AND r.product_id = si.product_id), 0) as already_returned 
                FROM sale_items si 
                JOIN products p ON p.id = si.product_id 
                WHERE si.sale_id = ? AND si.product_id = ? FOR UPDATE");
            $itemStmt->execute([$saleId, $productId]);
            $item = $itemStmt->fetch();

            if (!$item) {
                throw new RuntimeException('Sale item not found.');
            }

            $maxReturnable = (int) $item['quantity'] - (int) $item['already_returned'];
            if ($returnQty > $maxReturnable) {
                throw new RuntimeException("Cannot return $returnQty units. Maximum returnable quantity is $maxReturnable.");
            }

            $refundAmount = $returnQty * (float) $item['unit_price'];

            // Insert into returns table
            $rStmt = $pdo->prepare("INSERT INTO returns (sale_id, product_id, quantity, refund_amount, date, reason, created_by) VALUES (?, ?, ?, ?, CURDATE(), ?, ?)");
            $rStmt->execute([$saleId, $productId, $returnQty, $refundAmount, $reason, $user['id']]);

            // Check if MySQL trigger exists for return_after_insert
            $hasTrigger = (bool) $pdo->query("SHOW TRIGGERS WHERE `Trigger` = 'return_after_insert'")->fetch();
            if (!$hasTrigger) {
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")->execute([$returnQty, $productId]);
            }

            // Update sales status to Returned if all items returned
            $pdo->prepare("UPDATE sales SET status = 'Returned' WHERE id = ?")->execute([$saleId]);

            $pdo->commit();
            flash('success', "Return of {$returnQty}x '{$item['product_name']}' processed successfully! Refund amount: Rs " . money($refundAmount) . ". Stock restored to inventory.");
            redirect("/POS_software/operator/returns.php?invoice=" . urlencode($invoiceSearch));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $e->getMessage());
        }
    }
}

// Fetch recent returns
$recentReturns = $pdo->query("SELECT r.*, s.invoice_number, s.buyer_name, p.name as product_name, u.username as processed_by 
    FROM returns r 
    JOIN sales s ON s.id = r.sale_id 
    JOIN products p ON p.id = r.product_id 
    LEFT JOIN users u ON u.id = r.created_by 
    ORDER BY r.id DESC 
    LIMIT 15")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Lookup Card -->
<div class="card">
    <div class="card-header">
        <h3>Lookup Customer Invoice for Return / Refund</h3>
    </div>
    <div class="card-body">
        <form method="get" class="filter-bar" style="margin: 0; max-width: 600px;">
            <div class="search-box" style="flex: 1;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" name="invoice" value="<?= e($invoiceSearch) ?>" placeholder="Enter Invoice # (e.g. INV-261013-1234)" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary">Find Invoice</button>
        </form>

        <?php if ($message): ?>
            <div class="alert alert-error" style="margin-top: 16px; margin-bottom: 0;">
                <?= e($message) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Invoice Details & Items to Return -->
<?php if ($sale): ?>
    <div class="card" style="border-top: 4px solid var(--primary);">
        <div class="card-header">
            <div>
                <h3>Invoice Details: <code><?= e($sale['invoice_number']) ?></code></h3>
                <span class="text-muted" style="font-size: 12px;">
                    Buyer: <strong><?= e($sale['buyer_name'] ?: 'Walk-in') ?></strong> <?= $sale['buyer_phone'] ? '(' . e($sale['buyer_phone']) . ')' : '' ?> · 
                    Date: <?= date('d M Y, h:i A', strtotime($sale['created_at'])) ?> · 
                    Mode: <span class="badge badge-gray"><?= e($sale['payment_mode']) ?></span>
                </span>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 11px; color: var(--text-muted);">Invoice Total</div>
                <div style="font-size: 18px; font-weight: 800; color: var(--primary);">Rs <?= money($sale['net_payable']) ?></div>
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-center">Sold Qty</th>
                            <th class="text-center">Already Returned</th>
                            <th class="text-center">Available to Return</th>
                            <th class="text-right">Return Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($saleItems as $si): 
                            $returnable = (int) $si['quantity'] - (int) $si['returned_qty'];
                        ?>
                            <tr>
                                <td><strong><?= e($si['product_name']) ?></strong></td>
                                <td class="text-right">Rs <?= money($si['unit_price']) ?></td>
                                <td class="text-center"><?= $si['quantity'] ?></td>
                                <td class="text-center text-muted"><?= $si['returned_qty'] ?></td>
                                <td class="text-center">
                                    <span class="badge badge-<?= $returnable > 0 ? 'success' : 'gray' ?>">
                                        <?= $returnable ?> units
                                    </span>
                                </td>
                                <td class="text-right">
                                    <?php if ($returnable > 0): ?>
                                        <button type="button" class="btn btn-sm btn-danger" 
                                            onclick="openReturnModal(<?= $sale['id'] ?>, <?= $si['product_id'] ?>, '<?= e(addslashes($si['product_name'])) ?>', <?= $returnable ?>, <?= $si['unit_price'] ?>)">
                                            ↩ Return Item
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size: 12px;">Fully Returned</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Recent Processed Returns -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3>Recent Return Transactions Log</h3>
        <span class="badge badge-gray">Latest 15 Returns</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice Ref</th>
                        <th>Customer</th>
                        <th>Product Returned</th>
                        <th class="text-center">Qty Restored</th>
                        <th class="text-right">Refund Amount</th>
                        <th>Reason</th>
                        <th>Processed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentReturns)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted" style="padding: 30px;">
                                No return transactions recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentReturns as $ret): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($ret['date'])) ?></td>
                                <td><code><?= e($ret['invoice_number']) ?></code></td>
                                <td><strong><?= e($ret['buyer_name'] ?: 'Walk-in') ?></strong></td>
                                <td><?= e($ret['product_name']) ?></td>
                                <td class="text-center font-bold text-success">+<?= $ret['quantity'] ?></td>
                                <td class="text-right font-bold text-danger">Rs <?= money($ret['refund_amount']) ?></td>
                                <td><small class="text-muted"><?= e($ret['reason'] ?: 'Customer return') ?></small></td>
                                <td><?= e($ret['processed_by'] ?? 'System') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== PROCESS RETURN MODAL ==================== -->
<div id="returnModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 460px;">
        <div class="modal-header">
            <h3>Process Product Return</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="process_return">
                <input type="hidden" name="sale_id" id="return_sale_id">
                <input type="hidden" name="product_id" id="return_product_id">

                <p style="margin-bottom: 6px;">Product: <strong id="return_product_name"></strong></p>
                <p class="text-muted" style="margin-bottom: 14px; font-size: 12.5px;">
                    Unit Price: <strong id="return_unit_price">Rs 0.00</strong>
                </p>

                <div class="form-group">
                    <label>Quantity to Return <span class="text-danger">*</span></label>
                    <input type="number" name="quantity" id="return_qty" class="form-control" min="1" value="1" required oninput="calcRefundTotal()">
                    <small class="text-muted">Maximum returnable: <span id="return_max_qty">1</span> units</small>
                </div>

                <div class="alert alert-warning" style="padding: 10px 14px; margin-bottom: 16px;">
                    Estimated Refund to Customer: <strong id="return_total_refund" class="text-danger">Rs 0.00</strong>
                </div>

                <div class="form-group">
                    <label>Reason for Return (Optional)</label>
                    <textarea name="reason" class="form-control" placeholder="e.g. Defective item, customer changed mind, wrong product" style="height: 70px;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-danger">Confirm Return & Restore Stock</button>
            </div>
        </form>
    </div>
</div>

<script>
let unitPriceVal = 0;

function openReturnModal(saleId, prodId, name, maxQty, unitPrice) {
    document.getElementById('return_sale_id').value = saleId;
    document.getElementById('return_product_id').value = prodId;
    document.getElementById('return_product_name').textContent = name;
    document.getElementById('return_max_qty').textContent = maxQty;
    document.getElementById('return_unit_price').textContent = 'Rs ' + Number(unitPrice).toFixed(2);
    
    unitPriceVal = Number(unitPrice);
    const qtyInput = document.getElementById('return_qty');
    qtyInput.max = maxQty;
    qtyInput.value = 1;

    calcRefundTotal();
    document.getElementById('returnModal').classList.add('active');
}

function calcRefundTotal() {
    const qty = parseInt(document.getElementById('return_qty').value, 10) || 0;
    const total = qty * unitPriceVal;
    document.getElementById('return_total_refund').textContent = 'Rs ' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
