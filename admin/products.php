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
        $stock = post_int('stock_quantity');
        $minAlert = post_int('min_alert_limit', 10);
        $categoryId = post_int('category_id') ?: null;
        $dealerId = post_int('dealer_id') ?: null;

        // Quick category add if provided
        $newCategory = post_string('new_category');
        if ($newCategory) {
            $catStmt = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
            $catStmt->execute([$newCategory]);
            $foundCat = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $foundCat->execute([$newCategory]);
            $categoryId = (int) $foundCat->fetchColumn();
        }

        if ($name && $sale >= 0) {
            $stmt = $pdo->prepare("INSERT INTO products (name, category_id, barcode, manufacturer_price, market_sale_price, dealer_id, stock_quantity, min_alert_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $categoryId, $barcode ?: null, $cost, $sale, $dealerId, $stock, $minAlert]);
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
        $stock = post_int('stock_quantity');
        $minAlert = post_int('min_alert_limit', 10);
        $categoryId = post_int('category_id') ?: null;
        $dealerId = post_int('dealer_id') ?: null;

        if ($id && $name && $sale >= 0) {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, barcode = ?, manufacturer_price = ?, market_sale_price = ?, dealer_id = ?, stock_quantity = ?, min_alert_limit = ? WHERE id = ?");
            $stmt->execute([$name, $categoryId, $barcode ?: null, $cost, $sale, $dealerId, $stock, $minAlert, $id]);
            flash('success', "Product '$name' updated successfully!");
        } else {
            flash('error', 'Valid product details required for updating.');
        }
    } elseif ($action === 'adjust_stock') {
        $id = post_int('id');
        $addQty = (int) ($_POST['add_quantity'] ?? 0);
        if ($id && $addQty !== 0) {
            $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
            $stmt->execute([$addQty, $id]);
            flash('success', 'Stock quantity adjusted successfully.');
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
} elseif (str_starts_with($filter, 'cat_')) {
    $catId = (int) substr($filter, 4);
    $whereClause = "WHERE p.category_id = $catId";
}

$products = $pdo->query("SELECT p.*, c.name as category_name, d.name as dealer_name, d.company_name as dealer_company 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    LEFT JOIN dealers d ON d.id = p.dealer_id 
    $whereClause 
    ORDER BY p.id DESC")->fetchAll();

$totalInventoryValue = 0;
$totalItemsInStock = 0;
$lowCount = 0;

foreach ($products as $p) {
    $totalInventoryValue += ((float) $p['manufacturer_price'] * (int) $p['stock_quantity']);
    $totalItemsInStock += (int) $p['stock_quantity'];
    if ($p['stock_quantity'] <= $p['min_alert_limit']) {
        $lowCount++;
    }
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
        <span class="text-muted" style="font-size: 12px;">Alert triggers when stock &le; threshold limit</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product & Barcode</th>
                        <th>Category</th>
                        <th>Dealer / Supplier</th>
                        <th class="text-right">Cost Price</th>
                        <th class="text-right">Sale Price</th>
                        <th class="text-right">Margin / Unit</th>
                        <th class="text-center">Stock Level</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted" style="padding: 40px;">
                                No products found. Click <strong>+ Add New Product</strong> above to add items to your inventory.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $p): 
                            $isLow = $p['stock_quantity'] <= $p['min_alert_limit'];
                            $margin = (float) $p['market_sale_price'] - (float) $p['manufacturer_price'];
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
                                <td class="text-right">Rs <?= money($p['manufacturer_price']) ?></td>
                                <td class="text-right font-bold text-success">Rs <?= money($p['market_sale_price']) ?></td>
                                <td class="text-right" style="color: <?= $margin >= 0 ? '#059669' : '#dc2626' ?>;">
                                    Rs <?= money($margin) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($p['stock_quantity'] <= 0): ?>
                                        <span class="badge badge-danger">Out of Stock (0)</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge badge-danger" title="Threshold: <?= $p['min_alert_limit'] ?>">
                                            ⚠️ <?= $p['stock_quantity'] ?> left (Low)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><?= $p['stock_quantity'] ?> in stock</span>
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
                                            onclick="openRestockModal(<?= $p['id'] ?>, '<?= e(addslashes($p['name'])) ?>', <?= $p['stock_quantity'] ?>)" title="Add Stock Quantity">
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
    <div class="modal-content">
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
                    <input type="text" name="name" class="form-control" placeholder="e.g. Basmati Rice 5kg / Coca Cola 1.5L" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" class="form-control">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Or Type New Category</label>
                        <input type="text" name="new_category" class="form-control" placeholder="e.g. Beverages">
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
                        <label>Purchase / Cost Price (Rs)</label>
                        <input type="number" step="0.01" name="manufacturer_price" class="form-control" value="0.00" required>
                        <small class="text-muted">Dealer / Khareed price</small>
                    </div>

                    <div class="form-group">
                        <label>Market Sale Price (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="market_sale_price" class="form-control" value="0.00" required>
                        <small class="text-muted">Customer retail price</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Opening Stock Quantity</label>
                        <input type="number" name="stock_quantity" class="form-control" value="0" required>
                    </div>

                    <div class="form-group">
                        <label>Low Stock Alert Limit</label>
                        <input type="number" name="min_alert_limit" class="form-control" value="10" required>
                        <small class="text-muted">Triggers alert when stock &le; this number</small>
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
    <div class="modal-content">
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
                        <select name="category_id" id="edit_category_id" class="form-control">
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
                        <label>Barcode / SKU</label>
                        <input type="text" name="barcode" id="edit_barcode" class="form-control" value="<?= e($editProduct['barcode'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Low Stock Alert Limit</label>
                        <input type="number" name="min_alert_limit" id="edit_min_alert_limit" class="form-control" value="<?= $editProduct['min_alert_limit'] ?? 10 ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Cost / Purchase Price (Rs)</label>
                        <input type="number" step="0.01" name="manufacturer_price" id="edit_cost" class="form-control" value="<?= $editProduct['manufacturer_price'] ?? 0 ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Market Sale Price (Rs)</label>
                        <input type="number" step="0.01" name="market_sale_price" id="edit_sale" class="form-control" value="<?= $editProduct['market_sale_price'] ?? 0 ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Current Stock Quantity</label>
                    <input type="number" name="stock_quantity" id="edit_stock" class="form-control" value="<?= $editProduct['stock_quantity'] ?? 0 ?>" required>
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
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <h3>Quick Stock Adjustment</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="id" id="restock_id">

                <p style="margin-bottom: 14px;">Product: <strong id="restock_product_name"></strong></p>
                <p class="text-muted" style="margin-bottom: 14px;">Current Stock: <span id="restock_current_qty" class="badge badge-gray">0</span></p>

                <div class="form-group">
                    <label>Add Stock Quantity (e.g. 50 or -5 to deduct)</label>
                    <input type="number" name="add_quantity" class="form-control" value="10" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Apply Adjustment</button>
            </div>
        </form>
    </div>
</div>

<script>
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
    document.getElementById('editProductModal').classList.add('active');
}

function openRestockModal(id, name, currentQty) {
    document.getElementById('restock_id').value = id;
    document.getElementById('restock_product_name').textContent = name;
    document.getElementById('restock_current_qty').textContent = currentQty;
    document.getElementById('restockModal').classList.add('active');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
