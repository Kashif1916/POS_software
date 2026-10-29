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
    $udharCustomerId = post_int('udhar_customer_id') ?: null;
    $paidAmount = post_float('paid_amount');
    $autoprint = isset($_POST['autoprint']) ? 1 : 0;

    if (empty($items)) {
        flash('error', 'Cart is empty. Please add at least one product.');
        redirect('/operator/index.php');
    }

    try {
        $pdo->beginTransaction();

        $ids = array_map('intval', array_column($items, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pStmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) FOR UPDATE");
        $pStmt->execute($ids);
        $productsById = [];
        foreach ($pStmt as $p) {
            $productsById[(int) $p['id']] = $p;
        }

        $totalAmount = 0.0;
        $processedItems = [];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $submittedPrice = isset($item['price']) ? (float) $item['price'] : 0.0;

            if (!$id || $qty <= 0.0001 || !isset($productsById[$id])) {
                throw new RuntimeException('Invalid product or quantity selected.');
            }

            $prod = $productsById[$id];
            $basePrice = (float) $prod['market_sale_price'];

            if (!empty($prod['expiry_date']) && strtotime($prod['expiry_date']) < strtotime(date('Y-m-d'))) {
                if (!in_array($user['role'], ['Admin', 'Master'], true)) {
                    throw new RuntimeException("Product '{$prod['name']}' has expired. Only Admin/Master can bill expired stock.");
                }
            }

            if (!in_array($user['role'], ['Admin', 'Master'], true)) {
                if ($submittedPrice > 0 && $submittedPrice < ($basePrice - 0.01)) {
                    throw new RuntimeException("Security Restriction: Operator cannot sell '{$prod['name']}' below rate of Rs " . money($basePrice) . ".");
                }
            }

            $unitPrice = ($submittedPrice > 0) ? $submittedPrice : $basePrice;

            if ((float) $prod['stock_quantity'] < $qty) {
                throw new RuntimeException("Insufficient stock for '{$prod['name']}'. Only {$prod['stock_quantity']} left.");
            }

            $lineTotal = round($unitPrice * $qty, 2);
            $totalAmount += $lineTotal;

            $processedItems[] = [
                'prod' => $prod,
                'qty' => $qty,
                'unit' => $prod['unit'] ?? 'piece',
                'costPrice' => (float) $prod['manufacturer_price'],
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineTotal
            ];
        }

        $netPayable = max(0.0, $totalAmount - $discount);
        $changeReturned = 0.0;

        if ($mode === 'Udhar' && $udharCustomerId) {
            $cStmt = $pdo->prepare("SELECT uc.*, COALESCE(SUM(ul.balance_amount), 0) as current_balance 
                FROM udhar_customers uc 
                LEFT JOIN udhar_ledger ul ON ul.customer_id = uc.id AND ul.status != 'Cleared' 
                WHERE uc.id = ? GROUP BY uc.id");
            $cStmt->execute([$udharCustomerId]);
            $uCust = $cStmt->fetch();

            if ($uCust) {
                $buyerName = $uCust['name'];
                if (!empty($uCust['phone'])) $buyerPhone = $uCust['phone'];
                $activeBalance = (float) $uCust['current_balance'];
                $creditLimit = (float) $uCust['credit_limit'];

                if (($activeBalance + $netPayable) > $creditLimit) {
                    if (!in_array($user['role'], ['Admin', 'Master'], true)) {
                        throw new RuntimeException("Credit limit exceeded for '{$uCust['name']}'!");
                    }
                }
            }
        }

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

        $sStmt = $pdo->prepare("INSERT INTO sales (invoice_number, buyer_name, buyer_phone, total_amount, bonus_discount, net_payable, paid_amount, change_returned, payment_mode, operator_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $sStmt->execute([$invoice, $buyerName, $buyerPhone, $totalAmount, $discount, $netPayable, $paidAmount, $changeReturned, $mode, $user['id']]);
        $saleId = (int) $pdo->lastInsertId();

        $hasTrigger = (bool) $pdo->query("SHOW TRIGGERS WHERE `Trigger` = 'sale_item_after_insert'")->fetch();

        $siStmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit, cost_price, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stockDecStmt = $hasTrigger ? null : $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");

        foreach ($processedItems as $item) {
            $prod = $item['prod'];
            $qty = $item['qty'];
            $costPrice = $item['costPrice'];
            $unitPrice = $item['unitPrice'];
            $lineTotal = $item['lineTotal'];
            $itemUnit = $item['unit'];

            $siStmt->execute([$saleId, $prod['id'], $qty, $itemUnit, $costPrice, $unitPrice, $lineTotal]);

            if ($stockDecStmt) {
                $stockDecStmt->execute([$qty, $prod['id']]);
            }
        }

        if ($mode === 'Udhar') {
            $uStmt = $pdo->prepare("INSERT INTO udhar_ledger (customer_id, sale_id, buyer_name, buyer_phone, total_amount, balance_amount, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $uStmt->execute([$udharCustomerId, $saleId, $buyerName, $buyerPhone, $netPayable, $netPayable]);
        }

        $pdo->commit();

        $_SESSION['last_sale'] = [
            'invoice' => $invoice,
            'buyer' => $buyerName,
            'amount' => $netPayable,
            'mode' => $mode,
            'autoprint' => $autoprint
        ];

        redirect("/operator/index.php?sale_done=" . urlencode($invoice));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
        redirect('/operator/index.php');
    }
}

// Fetch categories & products
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$products = $pdo->query("SELECT p.*, c.name as category_name,
    DATEDIFF(p.expiry_date, CURDATE()) as days_until_expiry 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    ORDER BY p.name ASC")->fetchAll();

$udharCustomers = $pdo->query("
    SELECT uc.*, 
    COALESCE(SUM(ul.balance_amount), 0) as current_balance,
    GREATEST(0, uc.credit_limit - COALESCE(SUM(ul.balance_amount), 0)) as remaining_limit
    FROM udhar_customers uc
    LEFT JOIN udhar_ledger ul ON ul.customer_id = uc.id AND ul.status != 'Cleared'
    GROUP BY uc.id
    ORDER BY uc.name ASC
")->fetchAll();

$recentInvoices = $pdo->query("SELECT s.*, u.username as cashier 
    FROM sales s 
    LEFT JOIN users u ON u.id = s.operator_id 
    ORDER BY s.id DESC 
    LIMIT 10")->fetchAll();

$latestInvoiceNumber = $recentInvoices[0]['invoice_number'] ?? null;
$completedSaleInfo =$_SESSION['last_sale'] ?? null;
unset($_SESSION['last_sale']);

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Optimized Dual-Column Professional Layout */
.pos-layout-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 16px;
    align-items: start;
}

/* Left Column Styling */
.pos-left-column {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.search-dropdown-wrapper {
    position: relative;
    width: 100%;
}

.search-results-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.12);
    max-height: 280px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    margin-top: 4px;
}

.search-dropdown-item {
    padding: 10px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: background 0.15s;
}

.search-dropdown-item:hover, .search-dropdown-item.selected {
    background: #f0fdf4;
}

.search-dropdown-item:last-child {
    border-bottom: none;
}

/* Cart Table Layout */
.pos-cart-panel {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
}

.pos-cart-table-wrapper {
    max-height: 300px;
    overflow-y: auto;
}

.pos-cart-table {
    width: 100%;
    border-collapse: collapse;
}

.pos-cart-table th {
    background: #f8fafc;
    color: #475569;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    padding: 10px 14px;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
}

.pos-cart-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

/* Product Selection Cards */
.pos-catalog-panel {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 16px;
}

.pos-products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    max-height: 280px;
    overflow-y: auto;
    padding-top: 8px;
}

/* Right Side Checkout Box */
.pos-checkout-sidebar {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    position: sticky;
    top: 80px;
}

.cart-qty-btn {
    width: 26px;
    height: 26px;
    border-radius: 4px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    font-weight: 700;
    cursor: pointer;
}
.cart-qty-btn:hover {
    background: #e2e8f0;
}
</style>

<?php if ($completedSaleInfo): ?>
    <div class="card" style="border-left: 4px solid var(--success); background: #f0fdf4; margin-bottom: 16px;">
        <div class="card-body" style="padding: 12px 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="badge badge-success" style="font-size: 12px;">✓ Sale Completed</span>
                <span>Invoice: <strong><?= e($completedSaleInfo['invoice']) ?></strong> · Total: <strong>Rs <?= money($completedSaleInfo['amount']) ?></strong> (<?= e($completedSaleInfo['mode']) ?>)</span>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-primary btn-sm" onclick="printReceipt('<?= e($completedSaleInfo['invoice']) ?>')">
                    🖨️ Print Bill Now
                </button>
                <button type="button" class="btn btn-light btn-sm" onclick="this.closest('.card').remove()">
                    Dismiss
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<form method="post" id="checkoutForm" onsubmit="return validateCheckout()">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="checkout">
    <input type="hidden" name="items" id="itemsPayload">
    <input type="hidden" name="payment_mode" id="selectedPaymentMode" value="Cash">

    <div class="pos-layout-grid">
        <!-- LEFT MAIN COLUMN -->
        <div class="pos-left-column">
            <!-- 1. Search Box with Live Dropdown & Category Bar -->
            <div class="card" style="padding: 14px 16px; margin-bottom: 0;">
                <div style="display: flex; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                    <div class="search-dropdown-wrapper">
                        <div class="search-box">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="text" id="posProductSearch" placeholder="Search product name or scan barcode..." autofocus autocomplete="off" onkeyup="handleSearchKey(event)" oninput="filterSearchDropdown()">
                        </div>
                        <!-- Live Search Results Dropdown -->
                        <div id="searchResultsDropdown" class="search-results-dropdown"></div>
                    </div>

                    <button type="button" class="btn btn-light btn-sm" onclick="clearSearch()">Clear</button>
                    <?php if ($latestInvoiceNumber): ?>
                        <button type="button" class="btn btn-light btn-sm" onclick="printReceipt('<?= e($latestInvoiceNumber) ?>')">
                            🖨️ Reprint Last
                        </button>
                    <?php endif; ?>
                </div>

                <div style="display: flex; gap: 6px; overflow-x: auto; padding-top: 4px;">
                    <button type="button" class="category-chip active" data-cat="" onclick="filterCategory(this, '')">All Categories</button>
                    <?php foreach ($categories as$cat): ?>
                        <button type="button" class="category-chip" data-cat="<?= e(strtolower($cat['name'])) ?>" onclick="filterCategory(this, '<?= e(addslashes(strtolower($cat['name']))) ?>')">
                            <?= e($cat['name']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 2. Wide Cart Table -->
            <div class="pos-cart-panel">
                <div style="padding: 12px 16px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h3 style="font-size: 15px; font-weight: 800; margin: 0; color: #0f172a;">🛒 Current Order Items</h3>
                        <span class="badge badge-gray" id="cartItemCount">0 items</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-light" onclick="clearCart()" style="color: #dc2626;">Clear Order Cart</button>
                </div>

                <div class="pos-cart-table-wrapper">
                    <table class="pos-cart-table">
                        <thead>
                            <tr>
                                <th style="width: 35%;">Item Name</th>
                                <th style="width: 25%;">Quantity / Weight</th>
                                <th style="width: 15%; text-align: right;">Unit Rate (Rs)</th>
                                <th style="width: 15%; text-align: right;">Total (Rs)</th>
                                <th style="width: 10%; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="cartItemsContainer">
                            <tr>
                                <td colspan="5" class="text-center text-muted" style="padding: 30px;">
                                    Cart is empty. Type above or click products below to add.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. Product Quick Selection Grid -->
            <div class="pos-catalog-panel">
                <div style="font-size: 12.5px; font-weight: 700; color: #475569; margin-bottom: 6px;">
                    Quick Click Product Items
                </div>
                <div class="pos-products-grid" id="posProductsGrid">
                    <?php foreach ($products as$p): 
                        $isOut = (float)$p['stock_quantity'] <= 0;
                        $isKg = ($p['unit'] ?? 'piece') === 'kg';
                        if ($isKg) {
                            $stockText = rtrim(rtrim(number_format((float)$p['stock_quantity'], 3, '.', ''), '0'), '.') . ' kg';
                        } else {
                            $stockText = (int)$p['stock_quantity'] . ' Left';
                        }
                    ?>
                        <div class="pos-product-card <?= $isOut ? 'out-of-stock' : '' ?>"
                             data-id="<?= $p['id'] ?>"
                             data-name="<?= e(strtolower($p['name'])) ?>"
                             data-barcode="<?= e(strtolower($p['barcode'] ?? '')) ?>"
                             data-category="<?= e(strtolower($p['category_name'] ?? '')) ?>"
                             onclick="addToCart(<?= htmlspecialchars(json_encode([
                                 'id' => (int) $p['id'],
                                 'name' => $p['name'],
                                 'price' => (float) $p['market_sale_price'],
                                 'stock' => (float) $p['stock_quantity'],
                                 'unit' => $p['unit'] ?? 'piece',
                                 'barcode' => $p['barcode'] ?? '',
                                 'expiry_date' => $p['expiry_date'] ?? '',
                                 'days_until_expiry' => $p['days_until_expiry'] ?? null
                             ]), ENT_QUOTES, 'UTF-8') ?>)">
                            
                            <div style="font-size: 12.5px; font-weight: 700; color: #0f172a; margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= e($p['name']) ?>
                            </div>
                            <div class="product-card-footer">
                                <div class="product-card-price">Rs <?= money($p['market_sale_price']) ?></div>
                                <span class="badge <?= $isOut ? 'badge-danger' : 'badge-success' ?>"><?= $stockText ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT CHECKOUT SIDEBAR (Shifted & Fixed) -->
        <div class="pos-checkout-sidebar">
            <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin-bottom: 14px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                Checkout Summary
            </h3>

            <!-- Udhar & Customer Details -->
            <div style="margin-bottom: 12px;">
                <div id="udharCustomerSection" style="display: none; margin-bottom: 8px;">
                    <label style="font-size: 11px; font-weight: 700; color: #b45309;">Select Udhar Customer *</label>
                    <select name="udhar_customer_id" id="udharCustomerSelect" class="form-control" onchange="onUdharCustomerSelected(this.value)" style="font-size: 12px; padding: 5px 8px;">
                        <option value="">-- Select Udhar Customer --</option>
                        <?php foreach ($udharCustomers as$uc): ?>
                            <option value="<?= $uc['id'] ?>" data-name="<?= e($uc['name']) ?>" data-phone="<?= e($uc['phone'] ?? '') ?>">
                                <?= e($uc['name']) ?> (Left: Rs <?= money($uc['remaining_limit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom: 6px;">
                    <label style="font-size: 11px; font-weight: 600;">Customer Name</label>
                    <input type="text" name="buyer_name" id="buyerNameInput" class="form-control" placeholder="Walk-in Customer" style="padding: 5px 8px; font-size: 12px;">
                </div>
                <div>
                    <label style="font-size: 11px; font-weight: 600;">Phone Number</label>
                    <input type="text" name="buyer_phone" id="buyerPhoneInput" class="form-control" placeholder="0300-XXXXXXX" style="padding: 5px 8px; font-size: 12px;">
                </div>
            </div>

            <!-- Bill Calculations -->
            <div style="background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px;">
                    <span>Subtotal:</span>
                    <strong id="subtotalDisplay">Rs 0.00</strong>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 13px;">Discount / Bonus:</span>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span style="font-size: 11px; color: #64748b;">Rs</span>
                        <input type="number" step="0.01" name="bonus_discount" id="discountInput" value="0" class="form-control" style="width: 80px; padding: 3px 6px; text-align: right; font-weight: 700;" oninput="updateCartTotalsOnly()">
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 8px; border-top: 1px dashed #cbd5e1;">
                    <span style="font-size: 13px; font-weight: 800; color: #0f172a;">NET PAYABLE:</span>
                    <span id="netPayableDisplay" style="color: var(--primary); font-size: 19px; font-weight: 800;">Rs 0.00</span>
                </div>
            </div>

            <!-- Payment Method Selector -->
            <div style="margin-bottom: 12px;">
                <label style="font-size: 11px; font-weight: 600; margin-bottom: 4px; display: block;">Payment Mode:</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px;">
                    <button type="button" class="payment-mode-btn active" data-mode="Cash" onclick="selectPaymentMode('Cash')" style="padding: 6px; font-size: 11px;">💵 Cash</button>
                    <button type="button" class="payment-mode-btn" data-mode="Bank" onclick="selectPaymentMode('Bank')" style="padding: 6px; font-size: 11px;">💳 Bank</button>
                    <button type="button" class="payment-mode-btn" data-mode="Udhar" onclick="selectPaymentMode('Udhar')" style="padding: 6px; font-size: 11px;">📒 Udhar</button>
                </div>
            </div>

            <!-- Cash Return Calculation -->
            <div id="cashCalculatorBox" style="margin-bottom: 12px;">
                <div style="margin-bottom: 6px;">
                    <label style="font-size: 11px; font-weight: 600;">Cash Received (Rs):</label>
                    <input type="number" step="0.01" name="paid_amount" id="cashTendered" class="form-control" placeholder="0.00" style="padding: 6px 8px; font-weight: 700; font-size: 13px;" oninput="calcChange()">
                </div>
                <div style="padding: 8px 10px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 11.5px; font-weight: 700; color: #065f46;">Change Return:</span>
                    <span id="changeDisplay" style="font-size: 14px; font-weight: 800; color: #047857;">Rs 0.00</span>
                </div>
            </div>

            <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                <input type="checkbox" name="autoprint" id="autoPrintCheck" value="1" checked style="width: auto;">
                <label for="autoPrintCheck" style="margin: 0; font-size: 11.5px; font-weight: 600; cursor: pointer;">
                    🖨️ Auto-print receipt
                </label>
            </div>

            <button type="submit" class="btn btn-success btn-block" id="checkoutBtn" disabled style="padding: 11px; font-size: 14px; font-weight: 800;">
                ✓ Complete Sale & Print
            </button>
        </div>
    </div>
</form>

<script>
const userRole = '<?= $user['role'] ?>';
const productsList = <?= json_encode($products) ?>;
const udharCustomers = <?= json_encode($udharCustomers) ?>;

let cart = [];
let activeCategory = '';

function formatMoney(num) {
    return 'Rs ' + Number(num).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function printReceipt(invNum) {
    window.open('<?= url('/print_invoice.php') ?>?invoice=' + encodeURIComponent(invNum) + '&autoprint=1', '_blank', 'width=420,height=650');
}

/* Add to Cart Handler */
function addToCart(prod) {
    const isSuper = (userRole === 'Admin' || userRole === 'Master');
    if (prod.days_until_expiry !== null && prod.days_until_expiry < 0) {
        if (!isSuper) {
            alert('❌ Expired Product: ' + prod.name);
            return;
        } else if (!confirm('⚠️ Admin Alert: Product expired. Add anyway?')) {
            return;
        }
    }

    if (prod.stock <= 0) {
        alert('Item is out of stock!');
        return;
    }

    const isKg = (prod.unit === 'kg');
    const existing = cart.find(i => i.id === prod.id);

    if (existing) {
        if (isKg) {
            const nextQty = existing.qty + 0.25;
            if (nextQty <= prod.stock) {
                existing.qty = nextQty;
                existing.grams = Math.round(existing.qty * 1000);
                existing.amount = Math.round(existing.qty * existing.price * 100) / 100;
            } else {
                alert('Stock limit reached!');
                return;
            }
        } else {
            if (existing.qty < prod.stock) {
                existing.qty++;
                existing.amount = Math.round(existing.qty * existing.price * 100) / 100;
            } else {
                alert('Stock limit reached!');
                return;
            }
        }
    } else {
        if (isKg) {
            const defaultQty = prod.stock >= 1 ? 1 : Number(prod.stock);
            const defaultGrams = Math.round(defaultQty * 1000);
            cart.push({
                id: prod.id,
                name: prod.name,
                unit: 'kg',
                price: Number(prod.market_sale_price || prod.price),
                original_price: Number(prod.market_sale_price || prod.price),
                stock: Number(prod.stock_quantity || prod.stock),
                qty: defaultQty,
                grams: defaultGrams,
                amount: Math.round(defaultQty * Number(prod.market_sale_price || prod.price) * 100) / 100
            });
        } else {
            cart.push({
                id: prod.id,
                name: prod.name,
                unit: 'piece',
                price: Number(prod.market_sale_price || prod.price),
                original_price: Number(prod.market_sale_price || prod.price),
                stock: Number(prod.stock_quantity || prod.stock),
                qty: 1,
                grams: 0,
                amount: Number(prod.market_sale_price || prod.price)
            });
        }
    }
    
    // Clear search dropdown after selection
    clearSearch();
    renderCart();
}

function updateQty(id, delta) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    const newQty = item.qty + delta;
    if (newQty <= 0) {
        cart = cart.filter(i => i.id !== id);
    } else if (newQty > item.stock) {
        alert('Stock limit reached!');
    } else {
        item.qty = newQty;
        item.amount = Math.round(item.qty * item.price * 100) / 100;
    }
    renderCart();
}

function setQty(id, val) {
    const qty = parseFloat(val);
    const item = cart.find(i => i.id === id);
    if (!item) return;

    if (isNaN(qty) || qty <= 0) {
        cart = cart.filter(i => i.id !== id);
    } else if (qty > item.stock) {
        alert('Stock limit reached!');
        item.qty = item.stock;
        item.amount = Math.round(item.qty * item.price * 100) / 100;
    } else {
        item.qty = qty;
        item.amount = Math.round(item.qty * item.price * 100) / 100;
    }
    renderCart();
}

function onKgGramsChange(id, gramsVal) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    let grams = parseFloat(gramsVal) || 0;
    let qty = grams / 1000;

    if (qty > item.stock) {
        alert('Weight exceeds available stock');
        qty = item.stock;
        grams = Math.round(qty * 1000);
        const gInput = document.getElementById('kgGrams_' + id);
        if (gInput) gInput.value = grams;
    }

    item.grams = grams;
    item.qty = qty;
    item.amount = Math.round(qty * item.price * 100) / 100;

    const amountInput = document.getElementById('kgAmount_' + id);
    if (amountInput) amountInput.value = item.amount.toFixed(2);

    updateCartTotalsOnly();
}

function onKgAmountChange(id, amountVal) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    let amount = parseFloat(amountVal) || 0;
    let qty = (item.price > 0) ? (amount / item.price) : 0;
    let grams = Math.round(qty * 1000);

    if (qty > item.stock) {
        alert('Amount exceeds available stock');
        qty = item.stock;
        grams = Math.round(qty * 1000);
        amount = Math.round(qty * item.price * 100) / 100;
        const aInput = document.getElementById('kgAmount_' + id);
        if (aInput) aInput.value = amount.toFixed(2);
    }

    item.amount = amount;
    item.qty = qty;
    item.grams = grams;

    const gramsInput = document.getElementById('kgGrams_' + id);
    if (gramsInput) gramsInput.value = item.grams;

    updateCartTotalsOnly();
}

function updateItemPrice(id, newPriceVal) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    let newPrice = parseFloat(newPriceVal);
    if (isNaN(newPrice) || newPrice <= 0) {
        renderCart();
        return;
    }

    const isSuper = (userRole === 'Admin' || userRole === 'Master');
    if (!isSuper && newPrice < (item.original_price - 0.01)) {
        alert('Operator cannot sell below set rate Rs ' + item.original_price);
        renderCart();
        return;
    }

    item.price = newPrice;
    if (item.unit === 'kg') {
        item.amount = Math.round((item.grams / 1000) * item.price * 100) / 100;
    } else {
        item.amount = Math.round(item.qty * item.price * 100) / 100;
    }
    renderCart();
}

function removeFromCart(id) {
    cart = cart.filter(i => i.id !== id);
    renderCart();
}

function clearCart() {
    if (cart.length === 0) return;
    if (confirm('Clear current cart?')) {
        cart = [];
        renderCart();
    }
}

function updateCartTotalsOnly() {
    const subtotal = cart.reduce((acc, i) => acc + (i.unit === 'kg' ? i.amount : (i.price * i.qty)), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const net = Math.max(0, subtotal - discount);

    document.getElementById('subtotalDisplay').textContent = formatMoney(subtotal);
    document.getElementById('netPayableDisplay').textContent = formatMoney(net);
    document.getElementById('itemsPayload').value = JSON.stringify(cart.map(i => ({
        id: i.id,
        qty: i.qty,
        unit: i.unit,
        price: i.price
    })));

    calcChange();
}

/* Render Cart Items Table */
function renderCart() {
    const container = document.getElementById('cartItemsContainer');
    const itemCountSpan = document.getElementById('cartItemCount');
    const checkoutBtn = document.getElementById('checkoutBtn');

    const totalItems = cart.length;
    itemCountSpan.textContent = totalItems + (totalItems === 1 ? ' item' : ' items');

    if (cart.length === 0) {
        container.innerHTML = `
            <tr>
                <td colspan="5" class="text-center text-muted" style="padding: 30px;">
                    Cart is empty. Type product name above or click items below.
                </td>
            </tr>
        `;
        checkoutBtn.disabled = true;
    } else {
        container.innerHTML = cart.map(item => {
            const isKg = (item.unit === 'kg');

            if (isKg) {
                return `
                <tr style="background: #f0f9ff;">
                    <td>
                        <strong style="font-size:13px; color:#0369a1;">${item.name}</strong> 
                        <span class="badge" style="background:#0284c7; color:#fff; font-size:9.5px;">⚖️ KG</span>
                    </td>
                    <td>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <label style="font-size:11px; margin:0; font-weight:600;">Grams:</label>
                            <input type="number" id="kgGrams_${item.id}" value="${item.grams}" oninput="onKgGramsChange(${item.id}, this.value)" style="width:70px; padding:3px; font-size:12px; font-weight:700; border:1px solid #93c5fd; border-radius:4px; text-align:center;">
                            <label style="font-size:11px; margin:0; font-weight:600;">Rs:</label>
                            <input type="number" id="kgAmount_${item.id}" value="${item.amount.toFixed(2)}" oninput="onKgAmountChange(${item.id}, this.value)" style="width:80px; padding:3px; font-size:12px; font-weight:700; border:1px solid #93c5fd; border-radius:4px; text-align:right;">
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <input type="number" value="${item.price}" onchange="updateItemPrice(${item.id}, this.value)" style="width:65px; padding:3px; font-size:12px; font-weight:700; border:1px solid #93c5fd; border-radius:4px; text-align:right;">
                    </td>
                    <td style="text-align: right;">
                        <strong style="font-size:14px; color:#0284c7;">${formatMoney(item.amount)}</strong>
                    </td>
                    <td style="text-align: center;">
                        <button type="button" onclick="removeFromCart(${item.id})" style="color:#ef4444; background:none; border:none; cursor:pointer; font-size:18px; font-weight:bold;">&times;</button>
                    </td>
                </tr>
                `;
            } else {
                return `
                <tr>
                    <td>
                        <strong style="font-size:13px; color:#0f172a;">${item.name}</strong>
                    </td>
                    <td>
                        <div style="display:flex; align-items:center; gap:4px;">
                            <button type="button" class="cart-qty-btn" onclick="updateQty(${item.id}, -1)">&minus;</button>
                            <input type="number" value="${item.qty}" min="1" max="${item.stock}" onchange="setQty(${item.id}, this.value)" style="width:45px; padding:3px; text-align:center; font-size:12px; font-weight:700; border:1px solid #cbd5e1; border-radius:4px;">
                            <button type="button" class="cart-qty-btn" onclick="updateQty(${item.id}, 1)">&#43;</button>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <input type="number" value="${item.price}" onchange="updateItemPrice(${item.id}, this.value)" style="width:65px; padding:3px; font-size:12px; font-weight:700; border:1px solid #cbd5e1; border-radius:4px; text-align:right;">
                    </td>
                    <td style="text-align: right;">
                        <strong style="font-size:14px; color:#0f172a;">${formatMoney(item.price * item.qty)}</strong>
                    </td>
                    <td style="text-align: center;">
                        <button type="button" onclick="removeFromCart(${item.id})" style="color:#ef4444; background:none; border:none; cursor:pointer; font-size:18px; font-weight:bold;">&times;</button>
                    </td>
                </tr>
                `;
            }
        }).join('');
        checkoutBtn.disabled = false;
    }

    updateCartTotalsOnly();
}

/* Instant Search Dropdown Logic */
function filterSearchDropdown() {
    const query = (document.getElementById('posProductSearch').value || '').trim().toLowerCase();
    const dropdown = document.getElementById('searchResultsDropdown');

    if (!query) {
        dropdown.style.display = 'none';
        filterProducts();
        return;
    }

    const matches = productsList.filter(p => {
        const name = (p.name || '').toLowerCase();
        const barcode = (p.barcode || '').toLowerCase();
        return name.includes(query) || barcode.includes(query);
    });

    if (matches.length > 0) {
        dropdown.innerHTML = matches.map(p => `
            <div class="search-dropdown-item" onclick='addToCart(${JSON.stringify(p)})'>
                <div>
                    <strong style="font-size:13px; color:#0f172a;">${p.name}</strong>
                    <div style="font-size:11px; color:#64748b;">${p.category_name || 'General'}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:13px; font-weight:700; color:var(--primary);">Rs ${p.market_sale_price}</div>
                    <small style="font-size:10px; color:${p.stock_quantity > 0 ? '#10b981' : '#ef4444'};">${p.stock_quantity > 0 ? p.stock_quantity + ' left' : 'Out of Stock'}</small>
                </div>
            </div>
        `).join('');
        dropdown.style.display = 'block';
    } else {
        dropdown.innerHTML = '<div style="padding:12px; font-size:12px; color:#94a3b8; text-align:center;">No product found</div>';
        dropdown.style.display = 'block';
    }

    filterProducts();
}

function handleSearchKey(e) {
    if (e.key === 'Enter') {
        const dropdown = document.getElementById('searchResultsDropdown');
        const firstItem = dropdown.querySelector('.search-dropdown-item');
        if (firstItem) {
            firstItem.click();
        }
    }
}

function selectPaymentMode(mode) {
    document.getElementById('selectedPaymentMode').value = mode;
    document.querySelectorAll('.payment-mode-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.mode === mode);
    });

    const cashBox = document.getElementById('cashCalculatorBox');
    const udharSection = document.getElementById('udharCustomerSection');

    if (mode === 'Cash') {
        cashBox.style.display = 'block';
        udharSection.style.display = 'none';
    } else if (mode === 'Udhar') {
        cashBox.style.display = 'none';
        udharSection.style.display = 'block';
    } else {
        cashBox.style.display = 'none';
        udharSection.style.display = 'none';
    }
}

function onUdharCustomerSelected(custId) {
    const cust = udharCustomers.find(c => String(c.id) === String(custId));
    const nameInput = document.getElementById('buyerNameInput');
    const phoneInput = document.getElementById('buyerPhoneInput');

    if (cust) {
        nameInput.value = cust.name;
        if (cust.phone) phoneInput.value = cust.phone;
    }
}

function getNetPayable() {
    const subtotal = cart.reduce((acc, i) => acc + (i.unit === 'kg' ? i.amount : (i.price * i.qty)), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    return Math.max(0, subtotal - discount);
}

function calcChange() {
    const net = getNetPayable();
    const tenderedInput = document.getElementById('cashTendered');
    const tendered = parseFloat(tenderedInput.value) || 0;
    const change = Math.max(0, tendered - net);

    document.getElementById('changeDisplay').textContent = formatMoney(change);
}

function clearSearch() {
    document.getElementById('posProductSearch').value = '';
    document.getElementById('searchResultsDropdown').style.display = 'none';
    activeCategory = '';
    document.querySelectorAll('.category-chip').forEach(btn => btn.classList.remove('active'));
    const allBtn = document.querySelector('.category-chip[data-cat=""]');
    if (allBtn) allBtn.classList.add('active');
    filterProducts();
}

function filterCategory(btnElement, catName) {
    activeCategory = (catName || '').trim().toLowerCase();
    document.querySelectorAll('.category-chip').forEach(chip => chip.classList.remove('active'));
    btnElement.classList.add('active');
    filterProducts();
}

function filterProducts() {
    const query = (document.getElementById('posProductSearch').value || '').trim().toLowerCase();
    const cards = document.querySelectorAll('.pos-product-card');

    cards.forEach(card => {
        const name = (card.dataset.name || '').toLowerCase();
        const barcode = (card.dataset.barcode || '').toLowerCase();
        const cat = (card.dataset.category || '').toLowerCase();

        const matchesSearch = !query || name.includes(query) || barcode.includes(query);
        const matchesCategory = !activeCategory || cat === activeCategory;

        if (matchesSearch && matchesCategory) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

function validateCheckout() {
    if (cart.length === 0) {
        alert('Cart is empty.');
        return false;
    }
    return true;
}

// Close search dropdown on clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('searchResultsDropdown');
    const searchInput = document.getElementById('posProductSearch');
    if (dropdown && !dropdown.contains(e.target) && e.target !== searchInput) {
        dropdown.style.display = 'none';
    }
});

<?php if ($completedSaleInfo && !empty($completedSaleInfo['autoprint'])): ?>
window.addEventListener('load', () => {
    printReceipt('<?= e($completedSaleInfo['invoice']) ?>');
});
<?php endif; ?>

renderCart();
filterProducts();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>