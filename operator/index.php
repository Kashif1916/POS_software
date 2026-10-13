<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(); // accessible by both Operator and Admin

$pdo = db();
$user = current_user();
$page_title = 'POS Billing Terminal';

// Handle Checkout POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    verify_csrf();
    $items = json_decode($_POST['items'] ?? '[]', true);
    $items = is_array($items) ? $items : [];
    $discount = post_float('bonus_discount');
    $mode = post_string('payment_mode', 'Cash');
    $buyerName = post_string('buyer_name') ?: 'Walk-in Customer';
    $buyerPhone = post_string('buyer_phone');
    $paidAmount = post_float('paid_amount');
    $autoprint = isset($_POST['autoprint']) ? 1 : 0;

    if (empty($items)) {
        flash('error', 'Cart is empty. Please add at least one product.');
        redirect('/POS_software/operator/index.php');
    }

    try {
        $pdo->beginTransaction();

        // Lock products to verify stock & prices
        $ids = array_map('intval', array_column($items, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pStmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) FOR UPDATE");
        $pStmt->execute($ids);
        $productsById = [];
        foreach ($pStmt as $p) {
            $productsById[(int) $p['id']] = $p;
        }

        $totalAmount = 0.0;
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);

            if (!$id || $qty < 1 || !isset($productsById[$id])) {
                throw new RuntimeException('Invalid product selected.');
            }

            if ($productsById[$id]['stock_quantity'] < $qty) {
                throw new RuntimeException("Insufficient stock for '{$productsById[$id]['name']}'. Only {$productsById[$id]['stock_quantity']} left.");
            }

            $totalAmount += ((float) $productsById[$id]['market_sale_price'] * $qty);
        }

        $netPayable = max(0.0, $totalAmount - $discount);
        $changeReturned = 0.0;

        if ($mode === 'Cash') {
            if ($paidAmount >= $netPayable) {
                $changeReturned = $paidAmount - $netPayable;
            } else {
                $paidAmount = $netPayable;
            }
        } else {
            $paidAmount = $netPayable;
        }

        $invoice = 'INV-' . date('ymd') . '-' . random_int(1000, 9999);

        // Insert sale
        $sStmt = $pdo->prepare("INSERT INTO sales (invoice_number, buyer_name, buyer_phone, total_amount, bonus_discount, net_payable, paid_amount, change_returned, payment_mode, operator_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $sStmt->execute([$invoice, $buyerName, $buyerPhone, $totalAmount, $discount, $netPayable, $paidAmount, $changeReturned, $mode, $user['id']]);
        $saleId = (int) $pdo->lastInsertId();

        // Check if MySQL trigger exists for sale_item_after_insert
        $hasTrigger = (bool) $pdo->query("SHOW TRIGGERS WHERE `Trigger` = 'sale_item_after_insert'")->fetch();

        // Insert sale items
        $siStmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, cost_price, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
        $stockDecStmt = $hasTrigger ? null : $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");

        foreach ($items as $item) {
            $prod = $productsById[(int) $item['id']];
            $qty = (int) $item['qty'];
            $costPrice = (float) $prod['manufacturer_price'];
            $unitPrice = (float) $prod['market_sale_price'];
            $lineTotal = $unitPrice * $qty;

            $siStmt->execute([$saleId, $prod['id'], $qty, $costPrice, $unitPrice, $lineTotal]);

            if ($stockDecStmt) {
                $stockDecStmt->execute([$qty, $prod['id']]);
            }
        }

        // If Udhar (credit sale), log into udhar_ledger
        if ($mode === 'Udhar') {
            $uStmt = $pdo->prepare("INSERT INTO udhar_ledger (sale_id, buyer_name, buyer_phone, total_amount, balance_amount, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
            $uStmt->execute([$saleId, $buyerName, $buyerPhone, $netPayable, $netPayable]);
        }

        $pdo->commit();

        $_SESSION['last_sale'] = [
            'invoice' => $invoice,
            'buyer' => $buyerName,
            'amount' => $netPayable,
            'mode' => $mode,
            'autoprint' => $autoprint
        ];

        redirect("/POS_software/operator/index.php?sale_done=" . urlencode($invoice));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
        redirect('/POS_software/operator/index.php');
    }
}

// Fetch all available products
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$products = $pdo->query("SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    ORDER BY p.name ASC")->fetchAll();

// Recent 10 invoices for instant reprint drawer
$recentInvoices = $pdo->query("SELECT s.*, u.username as cashier 
    FROM sales s 
    LEFT JOIN users u ON u.id = s.operator_id 
    ORDER BY s.id DESC 
    LIMIT 10")->fetchAll();

$latestInvoiceNumber = $recentInvoices[0]['invoice_number'] ?? null;
$completedSaleInfo = $_SESSION['last_sale'] ?? null;
unset($_SESSION['last_sale']);

include __DIR__ . '/../includes/header.php';
?>

<!-- SALE COMPLETED POPUP BANNER (IF SALE JUST DONE) -->
<?php if ($completedSaleInfo): ?>
    <div class="card" style="border-left: 4px solid var(--success); background: #f0fdf4; margin-bottom: 16px;">
        <div class="card-body" style="padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="badge badge-success" style="font-size: 13px;">✓ Sale Completed</span>
                <span>Invoice: <strong><?= e($completedSaleInfo['invoice']) ?></strong> · Total: <strong>Rs <?= money($completedSaleInfo['amount']) ?></strong> (<?= e($completedSaleInfo['mode']) ?>)</span>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-primary btn-sm" onclick="printReceipt('<?= e($completedSaleInfo['invoice']) ?>')">
                    🖨️ Print Customer Bill Now
                </button>
                <button type="button" class="btn btn-light btn-sm" onclick="this.closest('.card').remove()">
                    Dismiss
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="pos-container">
    <!-- LEFT PANEL: Product Catalog -->
    <div class="pos-catalog-panel">
        <div class="pos-catalog-header">
            <div style="display: flex; gap: 10px; align-items: center; justify-content: space-between;">
                <div class="search-box" style="flex: 1;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="posProductSearch" placeholder="Scan barcode or type product name..." autofocus autocomplete="off">
                </div>
                <button type="button" class="btn btn-light btn-sm" onclick="clearSearch()" title="Clear search">Clear</button>
                
                <?php if ($latestInvoiceNumber): ?>
                    <button type="button" class="btn btn-light btn-sm" onclick="printReceipt('<?= e($latestInvoiceNumber) ?>')" title="Reprint the latest invoice bill">
                        🖨️ Reprint Last Bill
                    </button>
                <?php endif; ?>

                <button type="button" class="btn btn-light btn-sm" data-modal-open="recentBillsModal" title="View recent bills & print">
                    📋 Recent Bills
                </button>
            </div>

            <!-- Categories Chips -->
            <div class="pos-categories">
                <button type="button" class="category-chip active" onclick="filterCategory('')">All Items</button>
                <?php foreach ($categories as $cat): ?>
                    <button type="button" class="category-chip" onclick="filterCategory('<?= e(addslashes($cat['name'])) ?>')">
                        <?= e($cat['name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Product Grid -->
        <div class="pos-products-grid" id="posProductsGrid">
            <?php foreach ($products as $p): 
                $isOut = $p['stock_quantity'] <= 0;
                $isLow = $p['stock_quantity'] <= $p['min_alert_limit'];
            ?>
                <div class="pos-product-card <?= $isOut ? 'out-of-stock' : '' ?>"
                     data-id="<?= $p['id'] ?>"
                     data-name="<?= e(strtolower($p['name'])) ?>"
                     data-barcode="<?= e(strtolower($p['barcode'] ?? '')) ?>"
                     data-category="<?= e(strtolower($p['category_name'] ?? '')) ?>"
                     data-price="<?= $p['market_sale_price'] ?>"
                     data-stock="<?= $p['stock_quantity'] ?>"
                     onclick="addToCart(<?= htmlspecialchars(json_encode([
                         'id' => (int) $p['id'],
                         'name' => $p['name'],
                         'price' => (float) $p['market_sale_price'],
                         'stock' => (int) $p['stock_quantity'],
                         'barcode' => $p['barcode'] ?? ''
                     ]), ENT_QUOTES, 'UTF-8') ?>)">
                    
                    <div class="product-card-title"><?= e($p['name']) ?></div>
                    <div class="product-card-category"><?= e($p['category_name'] ?? 'General') ?></div>
                    
                    <div class="product-card-footer">
                        <div class="product-card-price">Rs <?= money($p['market_sale_price']) ?></div>
                        <div>
                            <?php if ($isOut): ?>
                                <span class="badge badge-danger">Out of Stock</span>
                            <?php elseif ($isLow): ?>
                                <span class="badge badge-danger" title="Low Stock Warning">⚠️ <?= $p['stock_quantity'] ?> left</span>
                            <?php else: ?>
                                <span class="badge badge-success"><?= $p['stock_quantity'] ?> left</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- RIGHT PANEL: POS Cart & Checkout -->
    <div class="pos-cart-panel">
        <form method="post" id="checkoutForm" onsubmit="return validateCheckout()">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="checkout">
            <input type="hidden" name="items" id="itemsPayload">
            <input type="hidden" name="payment_mode" id="selectedPaymentMode" value="Cash">

            <div class="pos-cart-header">
                <div>
                    <h3 style="font-size: 16px; font-weight: 800;">Current Order</h3>
                    <span class="text-muted" id="cartItemCount" style="font-size: 11.5px;">0 items</span>
                </div>
                <button type="button" class="btn btn-sm btn-light" onclick="clearCart()" title="Empty cart">Clear Cart</button>
            </div>

            <!-- Customer Details -->
            <div class="customer-info-box">
                <div>
                    <label style="font-size: 11.5px; margin-bottom: 2px;">Customer / Buyer Name</label>
                    <input type="text" name="buyer_name" class="form-control" placeholder="Walk-in Customer" style="padding: 6px 10px; font-size: 12.5px;">
                </div>
                <div>
                    <label style="font-size: 11.5px; margin-bottom: 2px;">Phone Number (Optional)</label>
                    <input type="text" name="buyer_phone" class="form-control" placeholder="0300-XXXXXXX" style="padding: 6px 10px; font-size: 12.5px;">
                </div>
            </div>

            <!-- Cart Line Items -->
            <div class="pos-cart-items" id="cartItemsContainer">
                <div class="text-center text-muted" style="padding: 40px 10px;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" style="margin-bottom: 10px;">
                        <circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                    </svg>
                    <p style="font-size: 13px; font-weight: 600;">Cart is empty</p>
                    <small>Click on items from the left or scan barcode to add</small>
                </div>
            </div>

            <!-- Cart Summary & Actions -->
            <div class="cart-summary">
                <div class="summary-line">
                    <span>Subtotal</span>
                    <strong id="subtotalDisplay">Rs 0.00</strong>
                </div>

                <div class="summary-line" style="align-items: center;">
                    <span>Bonus / Discount</span>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span style="font-size: 12px; color: var(--text-muted);">Rs</span>
                        <input type="number" step="0.01" name="bonus_discount" id="discountInput" value="0" class="form-control" style="width: 90px; padding: 4px 8px; text-align: right; font-weight: 700;" oninput="renderCart()">
                    </div>
                </div>

                <div class="summary-line summary-total">
                    <span>NET PAYABLE</span>
                    <span id="netPayableDisplay" style="color: var(--primary);">Rs 0.00</span>
                </div>

                <!-- Payment Modes (Cash, Bank, Udhar) -->
                <div style="margin-top: 10px;">
                    <label style="font-size: 11.5px; margin-bottom: 4px;">Payment Method</label>
                    <div class="payment-modes-grid">
                        <button type="button" class="payment-mode-btn active" data-mode="Cash" onclick="selectPaymentMode('Cash')">
                            💵 Cash
                        </button>
                        <button type="button" class="payment-mode-btn" data-mode="Bank" onclick="selectPaymentMode('Bank')">
                            💳 Bank
                        </button>
                        <button type="button" class="payment-mode-btn" data-mode="Udhar" onclick="selectPaymentMode('Udhar')">
                            📒 Udhar (Credit)
                        </button>
                    </div>
                </div>

                <!-- Cash Tendered & Change Due -->
                <div id="cashCalculatorBox">
                    <div style="display: flex; gap: 10px; margin-bottom: 8px;">
                        <div style="flex: 1;">
                            <label style="font-size: 11px; margin-bottom: 2px;">Cash Received (Rs)</label>
                            <input type="number" step="0.01" name="paid_amount" id="cashTendered" class="form-control" placeholder="0.00" style="padding: 7px 10px; font-weight: 700;" oninput="calcChange()">
                        </div>
                    </div>
                    <div class="change-due-box" id="changeBox">
                        <span>Change Return (Baqaya):</span>
                        <span id="changeDisplay">Rs 0.00</span>
                    </div>
                </div>

                <!-- Udhar Notice -->
                <div id="udharNoticeBox" class="alert alert-warning" style="display:none; padding: 8px 12px; margin-bottom: 10px; font-size: 12px;">
                    ⚠️ This bill will be recorded under <strong>Customer Udhar Khata</strong>.
                </div>

                <!-- Auto-print Checkbox -->
                <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 8px; font-size: 12.5px;">
                    <input type="checkbox" name="autoprint" id="autoPrintCheck" value="1" checked style="width: auto; margin: 0;">
                    <label for="autoPrintCheck" style="margin: 0; cursor: pointer; font-weight: 600;">
                        🖨️ Auto-open receipt print after checkout
                    </label>
                </div>

                <button type="submit" class="btn btn-success btn-block btn-lg" id="checkoutBtn" disabled>
                    ✓ Complete Sale & Print Bill
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== RECENT BILLS MODAL ==================== -->
<div id="recentBillsModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Recent Sales Bills</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <div class="modal-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Time</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Print</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentInvoices)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted" style="padding: 25px;">No recent bills found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentInvoices as $inv): ?>
                                <tr>
                                    <td><strong><?= e($inv['invoice_number']) ?></strong></td>
                                    <td><?= e($inv['buyer_name'] ?: 'Walk-in') ?></td>
                                    <td><?= date('h:i A', strtotime($inv['created_at'])) ?></td>
                                    <td class="text-right font-bold text-success">Rs <?= money($inv['net_payable']) ?></td>
                                    <td class="text-right">
                                        <button type="button" class="btn btn-sm btn-primary" onclick="printReceipt('<?= e($inv['invoice_number']) ?>')">
                                            🖨️ Print
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-modal-close>Close</button>
        </div>
    </div>
</div>

<!-- ==================== SALE SUCCESS MODAL ==================== -->
<?php if ($completedSaleInfo): ?>
<div id="saleSuccessModal" class="modal-overlay active">
    <div class="modal-content" style="max-width: 440px; text-align: center;">
        <div class="modal-header" style="justify-content: center; border-bottom: none; padding-bottom: 0;">
            <div style="width: 50px; height: 50px; background: #ecfdf5; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; font-size: 26px;">
                ✓
            </div>
        </div>
        <div class="modal-body" style="padding-top: 10px;">
            <h3 style="font-size: 20px; font-weight: 800; margin-bottom: 4px;">Sale Completed!</h3>
            <p class="text-muted" style="margin-bottom: 16px;">Invoice: <code><?= e($completedSaleInfo['invoice']) ?></code></p>
            
            <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px; padding: 14px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span class="text-muted">Customer:</span>
                    <strong><?= e($completedSaleInfo['buyer']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span class="text-muted">Payment Mode:</span>
                    <span class="badge badge-primary"><?= e($completedSaleInfo['mode']) ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 16px; border-top: 1px dashed var(--border-color); padding-top: 8px; margin-top: 6px;">
                    <strong>Net Payable:</strong>
                    <strong style="color: var(--primary);">Rs <?= money($completedSaleInfo['amount']) ?></strong>
                </div>
            </div>

            <button type="button" class="btn btn-primary btn-block btn-lg" onclick="printReceipt('<?= e($completedSaleInfo['invoice']) ?>')">
                🖨️ Print Customer Bill (80mm)
            </button>
            <button type="button" class="btn btn-light btn-block" data-modal-close style="margin-top: 8px;">
                Next Sale / Close
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
let cart = [];
let activeCategory = '';

function formatMoney(num) {
    return 'Rs ' + Number(num).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function printReceipt(invNum) {
    window.open('/POS_software/print_invoice.php?invoice=' + encodeURIComponent(invNum) + '&autoprint=1', '_blank', 'width=420,height=650');
}

function addToCart(prod) {
    if (prod.stock <= 0) {
        alert('Item is currently out of stock!');
        return;
    }

    const existing = cart.find(i => i.id === prod.id);
    if (existing) {
        if (existing.qty < prod.stock) {
            existing.qty++;
        } else {
            alert('Cannot add more! Available stock is ' + prod.stock + ' units.');
            return;
        }
    } else {
        cart.push({
            id: prod.id,
            name: prod.name,
            price: Number(prod.price),
            stock: Number(prod.stock),
            qty: 1
        });
    }
    renderCart();
}

function updateQty(id, delta) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    const newQty = item.qty + delta;
    if (newQty <= 0) {
        cart = cart.filter(i => i.id !== id);
    } else if (newQty > item.stock) {
        alert('Stock limit reached! Only ' + item.stock + ' available.');
    } else {
        item.qty = newQty;
    }
    renderCart();
}

function setQty(id, val) {
    const qty = parseInt(val, 10);
    const item = cart.find(i => i.id === id);
    if (!item) return;

    if (isNaN(qty) || qty <= 0) {
        cart = cart.filter(i => i.id !== id);
    } else if (qty > item.stock) {
        alert('Stock limit reached! Max available: ' + item.stock);
        item.qty = item.stock;
    } else {
        item.qty = qty;
    }
    renderCart();
}

function removeFromCart(id) {
    cart = cart.filter(i => i.id !== id);
    renderCart();
}

function clearCart() {
    if (cart.length === 0) return;
    if (confirm('Are you sure you want to clear the entire cart?')) {
        cart = [];
        renderCart();
    }
}

function renderCart() {
    const container = document.getElementById('cartItemsContainer');
    const itemCountSpan = document.getElementById('cartItemCount');
    const checkoutBtn = document.getElementById('checkoutBtn');

    const totalItems = cart.reduce((acc, i) => acc + i.qty, 0);
    itemCountSpan.textContent = totalItems + (totalItems === 1 ? ' item' : ' items');

    if (cart.length === 0) {
        container.innerHTML = `
            <div class="text-center text-muted" style="padding: 40px 10px;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" style="margin-bottom: 10px;">
                    <circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                </svg>
                <p style="font-size: 13px; font-weight: 600;">Cart is empty</p>
                <small>Click on items from the left or scan barcode to add</small>
            </div>
        `;
        checkoutBtn.disabled = true;
    } else {
        container.innerHTML = cart.map(item => `
            <div class="cart-item-row">
                <div class="cart-item-name">
                    ${item.name}
                    <div class="cart-item-price">${formatMoney(item.price)} each</div>
                </div>
                <div class="cart-qty-ctrl">
                    <button type="button" class="qty-btn" onclick="updateQty(${item.id}, -1)">&minus;</button>
                    <input type="number" class="qty-input" value="${item.qty}" min="1" max="${item.stock}" onchange="setQty(${item.id}, this.value)">
                    <button type="button" class="qty-btn" onclick="updateQty(${item.id}, 1)">&#43;</button>
                </div>
                <div class="cart-item-total">${formatMoney(item.price * item.qty)}</div>
                <button type="button" class="cart-remove-btn" onclick="removeFromCart(${item.id})" title="Remove item">&times;</button>
            </div>
        `).join('');
        checkoutBtn.disabled = false;
    }

    const subtotal = cart.reduce((acc, i) => acc + (i.price * i.qty), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const net = Math.max(0, subtotal - discount);

    document.getElementById('subtotalDisplay').textContent = formatMoney(subtotal);
    document.getElementById('netPayableDisplay').textContent = formatMoney(net);
    document.getElementById('itemsPayload').value = JSON.stringify(cart.map(i => ({id: i.id, qty: i.qty})));

    calcChange();
}

function selectPaymentMode(mode) {
    document.getElementById('selectedPaymentMode').value = mode;
    document.querySelectorAll('.payment-mode-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.mode === mode);
    });

    const cashBox = document.getElementById('cashCalculatorBox');
    const udharNotice = document.getElementById('udharNoticeBox');

    if (mode === 'Cash') {
        cashBox.style.display = 'block';
        udharNotice.style.display = 'none';
    } else if (mode === 'Udhar') {
        cashBox.style.display = 'none';
        udharNotice.style.display = 'block';
    } else {
        cashBox.style.display = 'none';
        udharNotice.style.display = 'none';
    }
}

function calcChange() {
    const subtotal = cart.reduce((acc, i) => acc + (i.price * i.qty), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const net = Math.max(0, subtotal - discount);

    const tenderedInput = document.getElementById('cashTendered');
    const tendered = parseFloat(tenderedInput.value) || 0;
    const change = Math.max(0, tendered - net);

    document.getElementById('changeDisplay').textContent = formatMoney(change);
}

// Product search & barcode scanning
const searchInput = document.getElementById('posProductSearch');
searchInput.addEventListener('input', () => {
    filterProducts();
});

searchInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        const query = searchInput.value.trim().toLowerCase();
        if (!query) return;

        const cards = document.querySelectorAll('.pos-product-card');
        let matchedCard = null;

        for (const card of cards) {
            if (card.dataset.barcode && card.dataset.barcode.toLowerCase() === query) {
                matchedCard = card;
                break;
            }
        }

        if (!matchedCard) {
            const visibleCards = Array.from(cards).filter(c => !c.hidden);
            if (visibleCards.length === 1) {
                matchedCard = visibleCards[0];
            }
        }

        if (matchedCard) {
            matchedCard.click();
            searchInput.value = '';
            filterProducts();
        }
    }
});

function clearSearch() {
    searchInput.value = '';
    filterCategory('');
}

function filterCategory(catName) {
    activeCategory = catName.toLowerCase();
    document.querySelectorAll('.category-chip').forEach(chip => {
        chip.classList.toggle('active', chip.textContent.trim().toLowerCase() === (catName ? catName.toLowerCase() : 'all items'));
    });
    filterProducts();
}

function filterProducts() {
    const query = searchInput.value.trim().toLowerCase();
    document.querySelectorAll('.pos-product-card').forEach(card => {
        const name = card.dataset.name;
        const barcode = card.dataset.barcode;
        const cat = card.dataset.category;

        const matchesQuery = !query || name.includes(query) || barcode.includes(query);
        const matchesCategory = !activeCategory || cat === activeCategory;

        card.hidden = !(matchesQuery && matchesCategory);
    });
}

function validateCheckout() {
    if (cart.length === 0) {
        alert('Cart is empty.');
        return false;
    }
    const mode = document.getElementById('selectedPaymentMode').value;
    if (mode === 'Udhar') {
        const buyerName = document.querySelector('input[name="buyer_name"]').value.trim();
        if (!buyerName || buyerName.toLowerCase() === 'walk-in customer') {
            if (!confirm('You selected Udhar (Credit) with generic "Walk-in Customer". It is recommended to enter the customer\'s name and phone. Proceed anyway?')) {
                return false;
            }
        }
    }
    return true;
}

// Auto open print if completed sale has autoprint enabled
<?php if ($completedSaleInfo && !empty($completedSaleInfo['autoprint'])): ?>
window.addEventListener('load', () => {
    printReceipt('<?= e($completedSaleInfo['invoice']) ?>');
});
<?php endif; ?>

renderCart();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
