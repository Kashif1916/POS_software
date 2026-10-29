<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Admin');

$pdo = db();
$page_title = 'Store & Receipt Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $storeName = post_string('store_name', 'Al Mehbob Traders');
    $phone = post_string('phone', '0309-7107977');
    $address = post_string('address', 'Janubi Bazar Jauharabad');
    $currencySymbol = post_string('currency_symbol', 'Rs');
    $receiptFooter = post_string('receipt_footer');

    if ($storeName === '') {
        flash('error', 'Store name cannot be empty.');
    } else {
        $stmt = $pdo->prepare("UPDATE store_settings SET 
            store_name = ?, 
            phone = ?, 
            address = ?, 
            currency_symbol = ?, 
            receipt_footer = ? 
            WHERE id = 1");
        $stmt->execute([$storeName, $phone, $address, $currencySymbol, $receiptFooter]);
        
        flash('success', 'Store details and receipt settings updated successfully!');
        redirect('/admin/settings.php');
    }
}

$settings = get_store_settings($pdo);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 24px; align-items: start;">
        
        <!-- Store Settings Form -->
        <div class="card">
            <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                <h3 style="margin: 0; font-size: 16px;">🏪 Store Profile & Receipt Settings</h3>
                <span class="text-muted" style="font-size: 12px;">These details appear on your topbar, sidebar, invoices, and thermal receipts</span>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

                    <div class="form-group">
                        <label>Store / Business Name <span class="text-danger">*</span></label>
                        <input type="text" name="store_name" id="set_store_name" class="form-control" value="<?= e($settings['store_name']) ?>" required oninput="updatePreview()">
                        <small class="text-muted">Displays on software title, topbar, and top of all printed receipts.</small>
                    </div>

                    <div class="form-group">
                        <label>Phone / WhatsApp Number <span class="text-danger">*</span></label>
                        <input type="text" name="phone" id="set_phone" class="form-control" value="<?= e($settings['phone']) ?>" required oninput="updatePreview()">
                        <small class="text-muted">Customer contact number printed on invoices.</small>
                    </div>

                    <div class="form-group">
                        <label>Store Address <span class="text-danger">*</span></label>
                        <input type="text" name="address" id="set_address" class="form-control" value="<?= e($settings['address']) ?>" required oninput="updatePreview()">
                        <small class="text-muted">Physical shop location / market address.</small>
                    </div>

                    <div class="form-group">
                        <label>Currency Symbol</label>
                        <input type="text" name="currency_symbol" class="form-control" value="<?= e($settings['currency_symbol'] ?? 'Rs') ?>" style="max-width: 150px;">
                        <small class="text-muted">e.g. Rs, PKR, $, etc.</small>
                    </div>

                    <div class="form-group">
                        <label>Receipt Footer Note</label>
                        <textarea name="receipt_footer" id="set_footer" class="form-control" rows="3" oninput="updatePreview()"><?= e($settings['receipt_footer'] ?? '') ?></textarea>
                        <small class="text-muted">Message printed at the bottom of customer receipts.</small>
                    </div>

                    <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700;">
                        💾 Save Store Settings
                    </button>
                </form>
            </div>
        </div>

        <!-- Receipt Live Preview -->
        <div class="card">
            <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                <h3 style="margin: 0; font-size: 16px;">🧾 Live Thermal Receipt Preview</h3>
                <span class="text-muted" style="font-size: 12px;">Real-time preview of how your receipts look</span>
            </div>
            <div class="card-body" style="background: #f1f5f9; display: flex; justify-content: center; padding: 24px 16px;">
                <div style="background: #fff; width: 280px; padding: 20px 16px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); font-size: 11.5px; font-family: monospace; line-height: 1.4;">
                    <div style="text-align: center; border-bottom: 1px dashed #94a3b8; padding-bottom: 12px; margin-bottom: 12px;">
                        <div id="prev_store_name" style="font-size: 15px; font-weight: 800; color: #0f172a; margin-bottom: 4px;">
                            <?= e($settings['store_name']) ?>
                        </div>
                        <div id="prev_address" style="color: #475569; font-size: 11px;">
                            <?= e($settings['address']) ?>
                        </div>
                        <div id="prev_phone" style="color: #475569; font-size: 11px;">
                            Phone: <?= e($settings['phone']) ?>
                        </div>
                    </div>

                    <div style="margin-bottom: 10px; font-size: 11px;">
                        <div>Invoice: #INV-20261020-001</div>
                        <div>Date: <?= date('d/m/Y h:i A') ?></div>
                        <div>Cashier: admin</div>
                    </div>

                    <div style="border-top: 1px dashed #94a3b8; border-bottom: 1px dashed #94a3b8; padding: 6px 0; margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between;">
                            <span>1x Basmati Rice 5kg</span>
                            <span>1,750.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>2x Coca Cola 1.5L</span>
                            <span>420.00</span>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 13px; margin-bottom: 12px;">
                        <span>TOTAL:</span>
                        <span>Rs 2,170.00</span>
                    </div>

                    <div id="prev_footer" style="text-align: center; color: #64748b; font-size: 10.5px; border-top: 1px dashed #94a3b8; padding-top: 10px;">
                        <?= e($settings['receipt_footer'] ?: 'Thank you for shopping with us!') ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
function updatePreview() {
    const store = document.getElementById('set_store_name').value || 'Al Mehbob Traders';
    const phone = document.getElementById('set_phone').value || '0309-7107977';
    const address = document.getElementById('set_address').value || 'Janubi Bazar Jauharabad';
    const footer = document.getElementById('set_footer').value || 'Thank you for shopping with us!';

    document.getElementById('prev_store_name').textContent = store;
    document.getElementById('prev_phone').textContent = 'Phone: ' + phone;
    document.getElementById('prev_address').textContent = address;
    document.getElementById('prev_footer').textContent = footer;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
