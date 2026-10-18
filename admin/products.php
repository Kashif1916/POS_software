<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Admin');

$pdo = db();
$page_title = 'Products & Inventory Management';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 'add');

    if ($action === 'add') {
        $name = post_string('name');
        $barcode = post_string('barcode');
        $cost = post_float('manufacturer_price');
        $sale = post_float('market_sale_price');
        $stock = post_float('stock_quantity');
        $minAlert = post_float('min_alert_limit', 5.0);
        $categoryId = post_int('category_id') ?: null;
        $dealerId = post_int('dealer_id') ?: null;
        $unit = post_string('unit', 'piece');
        $expiryDate = post_string('expiry_date') ?: null;

        // Quick category add if provided
        $newCategory = post_string('new_category');
        if ($newCategory) {
            $catStmt = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
            $catStmt->execute([$newCategory]);
            $foundCat = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $foundCat->execute([$newCategory]);
            $categoryId = (int) $foundCat->fetchColumn();

            // Auto-detect kilogram from category name
            if (stripos($newCategory, 'kilo') !== false || stripos($newCategory, 'kg') !== false) {
                $unit = 'kg';
            }
        }

        if ($name && $sale >= 0) {
            $stmt = $pdo->prepare("INSERT INTO products (name, category_id, barcode, manufacturer_price, market_sale_price, dealer_id, stock_quantity, min_alert_limit, unit, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $categoryId, $barcode ?: null, $cost, $sale, $dealerId, $stock, $minAlert, $unit, $expiryDate]);
            flash('success', "Product '$name' added successfully!");
        } else {
            flash('error', 'Product name and valid sale price are required.');
        }
    } elseif ($action === 'edit') {
        $id = post_int('id');
        $name = post_string('name');
        $barcode = post_string('barcode');
        $cost = post_float('manufacturer_price');
        $sale = post_float('market_sale_price');
        $stock = post_float('stock_quantity');
        $minAlert = post_float('min_alert_limit', 5.0);
        $categoryId = post_int('category_id') ?: null;
        $dealerId = post_int('dealer_id') ?: null;
        $unit = post_string('unit', 'piece');
        $expiryDate = post_string('expiry_date') ?: null;

        if ($id && $name && $sale >= 0) {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, barcode = ?, manufacturer_price = ?, market_sale_price = ?, dealer_id = ?, stock_quantity = ?, min_alert_limit = ?, unit = ?, expiry_date = ? WHERE id = ?");
            $stmt->execute([$name, $categoryId, $barcode ?: null, $cost, $sale, $dealerId, $stock, $minAlert, $unit, $expiryDate, $id]);
            flash('success', "Product '$name' updated successfully!");
        } else {
            flash('error', 'Valid product details required for updating.');
        }
    } elseif ($action === 'adjust_stock') {
        $id = post_int('id');
        $addQty = (float) ($_POST['add_quantity'] ?? 0);
        $newExpiry = post_string('expiry_date') ?: null;

        if ($id && ($addQty != 0 || $newExpiry)) {
            if ($newExpiry) {
                $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, expiry_date = ? WHERE id = ?");
                $stmt->execute([$addQty, $newExpiry, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                $stmt->execute([$addQty, $id]);
            }
            flash('success', 'Stock quantity & expiry date updated successfully.');
        }
    } elseif ($action === 'delete') {
        $id = post_int('id');
        if ($id) {
            // Check if product has sales
            $soldCount = (int) $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id = ?")->execute([$id]) ? $pdo->query("SELECT COUNT(*) FROM sale_items WHERE product_id = $id")->fetchColumn() : 0;
            if ($soldCount > 0) {
                flash('error', 'Cannot delete this product because it has past sales history. You can set its stock to 0 instead.');
            } else {
                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$id]);
                flash('success', 'Product deleted permanently.');
            }
        }
    }

    redirect('/POS_software/admin/products.php' . (isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
}

// Fetch categories and dealers
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$dealers = $pdo->query("SELECT id, name, company_name FROM dealers ORDER BY name ASC")->fetchAll();

// Product filtering
$filter = $_GET['filter'] ?? '';
$whereClause = '';
if ($filter === 'low') {
    $whereClause = 'WHERE p.stock_quantity <= p.min_alert_limit';
} elseif ($filter === 'expired') {
    $whereClause = 'WHERE p.expiry_date IS NOT NULL AND p.expiry_date <= CURDATE() + INTERVAL 30 DAY';
} elseif (str_starts_with($filter, 'cat_')) {
    $catId = (int) substr($filter, 4);
    $whereClause = "WHERE p.category_id = $catId";
}

$products = $pdo->query("SELECT p.*, c.name as category_name, d.name as dealer_name, d.company_name as dealer_company,
    DATEDIFF(p.expiry_date, CURDATE()) as days_until_expiry 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    LEFT JOIN dealers d ON d.id = p.dealer_id 
    $whereClause 
    ORDER BY p.id DESC")->fetchAll();

$totalInventoryValue = 0;
$totalItemsInStock = 0;
$lowCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_alert_limit")->fetchColumn();
$expiredCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE expiry_date IS NOT NULL AND expiry_date <= CURDATE() + INTERVAL 30 DAY")->fetchColumn();

foreach ($products as $p) {
    $totalInventoryValue += ((float) $p['manufacturer_price'] * (float) $p['stock_quantity']);
    $totalItemsInStock += (float) $p['stock_quantity'];
}

// Edit product prefetch if requested via query
$editProduct = null;
if (isset($_GET['edit_id'])) {
    $eStmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $eStmt->execute([(int) $_GET['edit_id']]);
    $editProduct = $eStmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Products Metrics Bar -->
<div class="stats-row" style="margin-bottom: 20px;">
    <div class="stat-box primary">
        <div class="stat-box-header"><span class="stat-title">Total Listed Products</span></div>
        <div class="stat-value"><?= count($products) ?></div>
        <div class="stat-desc">Inventory Catalog items</div>
    </div>
    <div class="stat-box info">
        <div class="stat-box-header"><span class="stat-title">Total Units in Stock</span></div>
        <div class="stat-value"><?= number_format($totalItemsInStock) ?></div>
        <div class="stat-desc">Units available across all items</div>
    </div>
    <div class="stat-box success">
        <div class="stat-box-header"><span class="stat-title">Inventory Cost Value</span></div>
        <div class="stat-value">Rs <?= money($totalInventoryValue) ?></div>
        <div class="stat-desc">Based on purchase cost prices</div>
    </div>
    <div class="stat-box danger">
        <div class="stat-box-header"><span class="stat-title">Low Stock Items</span></div>
        <div class="stat-value text-danger"><?= $lowCount ?></div>
        <div class="stat-desc">Stock at or below alert limit</div>
    </div>
</div>

<!-- Controls and Action Toolbar -->
<div class="filter-bar">
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" data-table-search="[data-product-row]" placeholder="Search products by name, barcode, dealer...">
        </div>

        <div style="display: flex; gap: 6px;">
            <a href="/POS_software/admin/products.php" class="btn btn-sm <?= empty($filter) ? 'btn-primary' : 'btn-light' ?>">All Products</a>
            <a href="/POS_software/admin/products.php?filter=low" class="btn btn-sm <?= $filter === 'low' ? 'btn-danger' : 'btn-light' ?>">
                ⚠️ Low Stock Only (<?= $lowCount ?>)
            </a>
            <a href="/POS_software/admin/products.php?filter=expired" class="btn btn-sm <?= $filter === 'expired' ? 'btn-danger' : 'btn-light' ?>" style="<?= $expiredCount > 0 ? 'background:#fee2e2; color:#991b1b; border:1px solid #f87171;' : '' ?>">
                ⏳ Expired / Expiring Soon (<?= $expiredCount ?>)
            </a>
        </div>
    </div>

    <div>
        <button type="button" class="btn btn-primary" data-modal-open="addProductModal">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            + Add New Product
        </button>
    </div>
</div>

<!-- Products Table Card -->
<div class="card">
    <div class="card-header">
        <h3>Products Inventory (<?= count($products) ?> items)</h3>
        <span class="text-muted" style="font-size: 12px;">Alert triggers when stock &le; threshold limit or expiry within 30 days</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product & Barcode</th>
                        <th>Category & Unit</th>
                        <th>Dealer / Supplier</th>
                        <th class="text-right">Cost Price</th>
                        <th class="text-right">Sale Price</th>
                        <th class="text-right">Margin</th>
                        <th class="text-center">Stock Level</th>
                        <th class="text-center">Expiry Date</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted" style="padding: 40px;">
                                No products found. Click <strong>+ Add New Product</strong> above to add items to your inventory.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $p): 
                            $isKg = ($p['unit'] ?? 'piece') === 'kg';
                            $unitLabel = $isKg ? 'KG' : 'Pcs';
                            $stockQty = (float) $p['stock_quantity'];
                            $minAlert = (float) $p['min_alert_limit'];
                            $isLow = $stockQty <= $minAlert;
                            $margin = (float) $p['market_sale_price'] - (float) $p['manufacturer_price'];
                            $stockText = $isKg ? (rtrim(rtrim(number_format($stockQty, 3), '0'), '.') . ' kg') : ($stockQty . ' pcs');
                        ?>
                            <tr data-product-row>
                                <td>
                                    <strong><?= e($p['name']) ?></strong>
                                    <?php if ($p['barcode']): ?>
                                        <br><code style="font-size: 11px; color: var(--text-muted); background: #f1f5f9; padding: 2px 5px; border-radius: 4px;"><?= e($p['barcode']) ?></code>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-gray"><?= e($p['category_name'] ?? 'Uncategorized') ?></span>
                                    <?php if ($isKg): ?>
                                        <span class="badge badge-primary" style="background:#0284c7; color:#fff; font-size:10px; margin-left:3px;">⚖️ KG</span>
                                    <?php else: ?>
                                        <span class="badge badge-gray" style="font-size:10px; margin-left:3px;">Piece</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['dealer_name']): ?>
                                        <strong><?= e($p['dealer_name']) ?></strong>
                                        <?php if ($p['dealer_company']): ?>
                                            <br><small class="text-muted"><?= e($p['dealer_company']) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">Rs <?= money($p['manufacturer_price']) ?><?= $isKg ? '/kg' : '' ?></td>
                                <td class="text-right font-bold text-success">Rs <?= money($p['market_sale_price']) ?><?= $isKg ? '/kg' : '' ?></td>
                                <td class="text-right" style="color: <?= $margin >= 0 ? '#059669' : '#dc2626' ?>;">
                                    Rs <?= money($margin) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($stockQty <= 0): ?>
                                        <span class="badge badge-danger">Out of Stock (0)</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge badge-danger" title="Threshold: <?= $minAlert ?> <?= $unitLabel ?>">
                                            ⚠️ <?= $stockText ?> (Low)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><?= $stockText ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($p['expiry_date'])): 
                                        $expDate = strtotime($p['expiry_date']);
                                        $today = strtotime(date('Y-m-d'));
                                        $daysLeft = (int) round(($expDate - $today) / 86400);
                                    ?>
                                        <?php if ($daysLeft < 0): ?>
                                            <span class="badge badge-danger" title="<?= $p['expiry_date'] ?>">❌ Expired (<?= abs($daysLeft) ?>d ago)</span>
                                        <?php elseif ($daysLeft <= 30): ?>
                                            <span class="badge" style="background:#fed7aa; color:#9a3412; font-weight:600;" title="<?= $p['expiry_date'] ?>">⚠️ <?= $daysLeft ?>d left</span>
                                            <br><small class="text-muted" style="font-size:10px;"><?= date('d M Y', $expDate) ?></small>
                                        <?php else: ?>
                                            <span style="font-size:12px; font-weight:500; color:#334155;"><?= date('d M Y', $expDate) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <div style="display: inline-flex; gap: 6px;">
                                        <!-- Edit Button -->
                                        <button type="button" class="btn btn-sm btn-light" 
                                            onclick="openEditModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)" title="Edit Product">
                                            Edit
                                        </button>

                                        <!-- Quick Restock Button -->
                                        <button type="button" class="btn btn-sm btn-light"
                                            onclick="openRestockModal(<?= $p['id'] ?>, '<?= e(addslashes($p['name'])) ?>', '<?= $stockText ?>', '<?= $p['expiry_date'] ?? '' ?>', '<?= $p['unit'] ?? 'piece' ?>')" title="Add Stock Quantity & Expiry">
                                            + Stock
                                        </button>

                                        <!-- Delete Form -->
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete \'<?= e(addslashes($p['name'])) ?>\'?');">
                                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">✕</button>
                                        </form>
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

<!-- ==================== ADD PRODUCT MODAL ==================== -->
<div id="addProductModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 580px;">
        <div class="modal-header">
            <h3>Add New Product</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add">

                <div class="form-group">
                    <label>Product Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Basmati Rice / Chicken / Coca Cola" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" id="add_category_id" class="form-control" onchange="checkCategoryForKg(this, 'add')">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Or Type New Category</label>
                        <input type="text" name="new_category" id="add_new_category" class="form-control" placeholder="e.g. Kilogram / Meat / Fruits" oninput="checkCategoryTextForKg(this, 'add')">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Unit of Measurement <span class="text-danger">*</span></label>
                        <select name="unit" id="add_unit" class="form-control" onchange="syncUnitForm('add')">
                            <option value="piece">Piece / Item (Pcs)</option>
                            <option value="kg">⚖️ Kilogram (KG / Grams)</option>
                        </select>
                        <small class="text-muted" id="add_unit_hint">Select KG for weighted items with gram billing</small>
                    </div>

                    <div class="form-group">
                        <label>Expiry Date (Optional)</label>
                        <input type="date" name="expiry_date" id="add_expiry_date" class="form-control">
                        <small class="text-muted">Auto alerts when nearing/expired</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Barcode / SKU (Optional)</label>
                        <input type="text" name="barcode" class="form-control" placeholder="Scan barcode or enter code">
                    </div>

                    <div class="form-group">
                        <label>Dealer / Supplier</label>
                        <select name="dealer_id" class="form-control">
                            <option value="">-- No Dealer / Direct --</option>
                            <?php foreach ($dealers as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['company_name'] ?: 'Independent') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label id="add_cost_label">Purchase / Cost Price (Rs)</label>
                        <input type="number" step="0.01" name="manufacturer_price" class="form-control" value="0.00" required>
                        <small class="text-muted" id="add_cost_hint">Dealer / Khareed price</small>
                    </div>

                    <div class="form-group">
                        <label id="add_sale_label">Market Sale Price (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="market_sale_price" class="form-control" value="0.00" required>
                        <small class="text-muted" id="add_sale_hint">Customer retail price</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label id="add_stock_label">Opening Stock Quantity</label>
                        <input type="number" step="0.001" name="stock_quantity" class="form-control" value="0" required>
                    </div>

                    <div class="form-group">
                        <label id="add_alert_label">Low Stock Alert Limit</label>
                        <input type="number" step="0.001" name="min_alert_limit" class="form-control" value="10" required>
                        <small class="text-muted" id="add_alert_hint">Alerts when stock is at or below this value</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== EDIT PRODUCT MODAL ==================== -->
<div id="editProductModal" class="modal-overlay <?= $editProduct ? 'active' : '' ?>">
    <div class="modal-content" style="max-width: 580px;">
        <div class="modal-header">
            <h3>Edit Product</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id" value="<?= $editProduct['id'] ?? '' ?>">

                <div class="form-group">
                    <label>Product Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="edit_name" class="form-control" value="<?= e($editProduct['name'] ?? '') ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" id="edit_category_id" class="form-control" onchange="checkCategoryForKg(this, 'edit')">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($editProduct['category_id'] ?? null) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Dealer / Supplier</label>
                        <select name="dealer_id" id="edit_dealer_id" class="form-control">
                            <option value="">-- No Dealer / Direct --</option>
                            <?php foreach ($dealers as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= ($editProduct['dealer_id'] ?? null) == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Unit of Measurement</label>
                        <select name="unit" id="edit_unit" class="form-control" onchange="syncUnitForm('edit')">
                            <option value="piece">Piece / Item (Pcs)</option>
                            <option value="kg">⚖️ Kilogram (KG / Grams)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" id="edit_expiry_date" class="form-control" value="<?= $editProduct['expiry_date'] ?? '' ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Barcode / SKU</label>
                        <input type="text" name="barcode" id="edit_barcode" class="form-control" value="<?= e($editProduct['barcode'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label id="edit_alert_label">Low Stock Alert Limit</label>
                        <input type="number" step="0.001" name="min_alert_limit" id="edit_min_alert_limit" class="form-control" value="<?= $editProduct['min_alert_limit'] ?? 10 ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label id="edit_cost_label">Cost / Purchase Price (Rs)</label>
                        <input type="number" step="0.01" name="manufacturer_price" id="edit_cost" class="form-control" value="<?= $editProduct['manufacturer_price'] ?? 0 ?>" required>
                    </div>

                    <div class="form-group">
                        <label id="edit_sale_label">Market Sale Price (Rs)</label>
                        <input type="number" step="0.01" name="market_sale_price" id="edit_sale" class="form-control" value="<?= $editProduct['market_sale_price'] ?? 0 ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label id="edit_stock_label">Current Stock Quantity</label>
                    <input type="number" step="0.001" name="stock_quantity" id="edit_stock" class="form-control" value="<?= $editProduct['stock_quantity'] ?? 0 ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-success">Update Product</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== RESTOCK MODAL ==================== -->
<div id="restockModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 460px;">
        <div class="modal-header">
            <h3>Quick Restock & Expiry Update</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="id" id="restock_id">

                <div style="background: var(--bg-hover); padding: 12px; border-radius: 8px; margin-bottom: 16px;">
                    <p style="margin: 0 0 6px 0; font-size: 14px;">Product: <strong id="restock_product_name"></strong></p>
                    <p style="margin: 0; font-size: 13px; color: var(--text-muted);">Current Stock: <span id="restock_current_qty" class="badge badge-gray">0</span></p>
                </div>

                <div class="form-group">
                    <label id="restock_qty_label">Add Stock Quantity (e.g. 50 or -5 to deduct)</label>
                    <input type="number" step="0.001" name="add_quantity" id="restock_add_qty" class="form-control" value="10" required>
                </div>

                <div class="form-group">
                    <label>New Batch Expiry Date (Optional)</label>
                    <input type="date" name="expiry_date" id="restock_expiry_date" class="form-control">
                    <small class="text-muted">Updates the expiry date for this new stock arrival</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Apply Restock</button>
            </div>
        </form>
    </div>
</div>

<script>
function syncUnitForm(mode) {
    var unitElem = document.getElementById(mode + '_unit');
    var isKg = unitElem && unitElem.value === 'kg';

    var costLabel = document.getElementById(mode + '_cost_label');
    var saleLabel = document.getElementById(mode + '_sale_label');
    var stockLabel = document.getElementById(mode + '_stock_label');
    var alertLabel = document.getElementById(mode + '_alert_label');

    if (isKg) {
        if (costLabel) costLabel.innerHTML = 'Purchase Cost <strong>per 1 KG</strong> (Rs)';
        if (saleLabel) saleLabel.innerHTML = 'Market Sale Price <strong>per 1 KG</strong> (Rs) <span class="text-danger">*</span>';
        if (stockLabel) stockLabel.innerHTML = 'Opening Stock <strong>(in KG, e.g. 25.5)</strong>';
        if (alertLabel) alertLabel.innerHTML = 'Low Stock Alert Limit <strong>(in KG)</strong>';
    } else {
        if (costLabel) costLabel.innerHTML = 'Purchase / Cost Price (Rs)';
        if (saleLabel) saleLabel.innerHTML = 'Market Sale Price (Rs) <span class="text-danger">*</span>';
        if (stockLabel) stockLabel.innerHTML = 'Opening Stock Quantity';
        if (alertLabel) alertLabel.innerHTML = 'Low Stock Alert Limit';
    }
}

function checkCategoryForKg(selectElem, mode) {
    if (!selectElem) return;
    var selectedText = selectElem.options[selectElem.selectedIndex] ? selectElem.options[selectElem.selectedIndex].text.toLowerCase() : '';
    if (selectedText.includes('kilo') || selectedText.includes('kg') || selectedText.includes('weight')) {
        var unitElem = document.getElementById(mode + '_unit');
        if (unitElem) {
            unitElem.value = 'kg';
            syncUnitForm(mode);
        }
    }
}

function checkCategoryTextForKg(inputElem, mode) {
    if (!inputElem) return;
    var val = inputElem.value.toLowerCase();
    if (val.includes('kilo') || val.includes('kg') || val.includes('weight')) {
        var unitElem = document.getElementById(mode + '_unit');
        if (unitElem) {
            unitElem.value = 'kg';
            syncUnitForm(mode);
        }
    }
}

function openEditModal(prod) {
    document.getElementById('edit_id').value = prod.id;
    document.getElementById('edit_name').value = prod.name;
    document.getElementById('edit_barcode').value = prod.barcode || '';
    document.getElementById('edit_category_id').value = prod.category_id || '';
    document.getElementById('edit_dealer_id').value = prod.dealer_id || '';
    document.getElementById('edit_cost').value = prod.manufacturer_price;
    document.getElementById('edit_sale').value = prod.market_sale_price;
    document.getElementById('edit_stock').value = prod.stock_quantity;
    document.getElementById('edit_min_alert_limit').value = prod.min_alert_limit;
    document.getElementById('edit_unit').value = prod.unit || 'piece';
    document.getElementById('edit_expiry_date').value = prod.expiry_date || '';
    syncUnitForm('edit');
    document.getElementById('editProductModal').classList.add('active');
}

function openRestockModal(id, name, currentQtyText, currentExpiry, unit) {
    document.getElementById('restock_id').value = id;
    document.getElementById('restock_product_name').textContent = name;
    document.getElementById('restock_current_qty').textContent = currentQtyText;
    document.getElementById('restock_expiry_date').value = currentExpiry || '';
    
    var qtyLabel = document.getElementById('restock_qty_label');
    if (qtyLabel) {
        qtyLabel.textContent = (unit === 'kg') ? 'Add Stock Quantity in KG (e.g. 20 or 5.5)' : 'Add Stock Quantity (e.g. 50 or -5 to deduct)';
    }

    document.getElementById('restockModal').classList.add('active');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
