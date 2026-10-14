<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$invoiceNum = post_string('invoice') ?: ($_GET['invoice'] ?? '');

if (!$invoiceNum) {
    die('Invoice number required.');
}

$stmt = $pdo->prepare("SELECT s.*, u.username as cashier_username, u.full_name as cashier_fullname 
FROM sales s 
LEFT JOIN users u ON u.id = s.operator_id 
WHERE s.invoice_number = ? LIMIT 1");
$stmt->execute([$invoiceNum]);
$sale = $stmt->fetch();

if (!$sale) {
    die('Invoice not found.');
}

$itemsStmt = $pdo->prepare("SELECT si.*, p.name as product_name, p.barcode 
FROM sale_items si 
JOIN products p ON p.id = si.product_id 
WHERE si.sale_id = ?");
$itemsStmt->execute([$sale['id']]);
$items = $itemsStmt->fetchAll();

$settings = get_store_settings($pdo);
$autoprint = isset($_GET['autoprint']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt #<?= e($sale['invoice_number']) ?></title>
<link rel="stylesheet" href="/POS_software/assets/css/style.css">
<style>
    body {
        font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    }
    .thermal-receipt {
        width: 320px;
        margin: 20px auto;
        background: #fff;
        padding: 20px 16px;
        font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        font-size: 12px;
        color: #0f172a;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        line-height: 1.4;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .thermal-header {
        text-align: center;
        margin-bottom: 12px;
    }
    .thermal-header h2 {
        font-size: 17px;
        font-weight: 800;
        margin: 0 0 2px 0;
        text-transform: uppercase;
        color: #0f172a;
        letter-spacing: -0.2px;
    }
    .thermal-header p {
        margin: 2px 0;
        font-size: 11px;
        color: #475569;
    }
    .thermal-line {
        border-top: 1px dashed #cbd5e1;
        margin: 10px 0;
    }
    .thermal-meta {
        font-size: 11.5px;
        margin-bottom: 8px;
        color: #334155;
    }
    .thermal-meta div {
        margin-bottom: 2px;
    }
    .thermal-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11.5px;
    }
    .thermal-table th {
        border-top: 1px dashed #cbd5e1;
        border-bottom: 1px dashed #cbd5e1;
        padding: 5px 0;
        text-align: left;
        font-weight: 700;
        color: #0f172a;
        text-transform: uppercase;
        font-size: 10.5px;
    }
    .thermal-table td {
        padding: 5px 0;
        vertical-align: top;
        color: #1e293b;
    }
    .thermal-totals {
        margin-top: 6px;
        font-size: 12px;
        color: #1e293b;
    }
    .thermal-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 4px;
    }
    .thermal-grand {
        font-size: 14.5px;
        font-weight: 800;
        border-top: 1px dashed #0f172a;
        border-bottom: 1px dashed #0f172a;
        padding: 7px 0;
        margin: 8px 0;
        color: #0f172a;
    }
    .thermal-footer {
        text-align: center;
        font-size: 11px;
        margin-top: 15px;
        color: #475569;
    }
    .barcode-text {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 1.5px;
        margin-top: 6px;
        color: #0f172a;
        font-family: inherit;
    }
</style>
</head>
<body style="background: #f8fafc;">
<div style="text-align: center; margin: 20px 0;" class="no-print">
    <button onclick="window.print()" class="btn btn-primary">
        🖨️ Print Receipt (80mm)
    </button>
    <button onclick="window.close()" class="btn btn-light">Close Window</button>
</div>

<div class="thermal-receipt">
    <div class="thermal-header">
        <h2><?= e($settings['store_name']) ?></h2>
        <p><?= e($settings['address']) ?></p>
        <p>Phone: <?= e($settings['phone']) ?></p>
    </div>

    <div class="thermal-line"></div>

    <div class="thermal-meta">
        <div><strong>Invoice:</strong> <?= e($sale['invoice_number']) ?></div>
        <div><strong>Date:</strong> <?= date('d/m/Y h:i A', strtotime($sale['created_at'])) ?></div>
        <div><strong>Cashier:</strong> <?= e($sale['cashier_fullname'] ?: $sale['cashier_username']) ?></div>
        <?php if ($sale['buyer_name']): ?>
            <div><strong>Customer:</strong> <?= e($sale['buyer_name']) ?></div>
        <?php endif; ?>
        <?php if ($sale['buyer_phone']): ?>
            <div><strong>Phone:</strong> <?= e($sale['buyer_phone']) ?></div>
        <?php endif; ?>
    </div>

    <table class="thermal-table">
        <thead>
            <tr>
                <th>Item</th>
                <th style="text-align: center;">Qty</th>
                <th style="text-align: right;">Price</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?= e($item['product_name']) ?>
                    </td>
                    <td style="text-align: center; font-weight: 600;"><?= $item['quantity'] ?></td>
                    <td style="text-align: right;"><?= money($item['unit_price']) ?></td>
                    <td style="text-align: right; font-weight: 700;"><?= money($item['total_price']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="thermal-totals">
        <div class="thermal-row">
            <span>Subtotal:</span>
            <span>Rs <?= money($sale['total_amount']) ?></span>
        </div>
        <?php if ($sale['bonus_discount'] > 0): ?>
            <div class="thermal-row">
                <span>Discount / Bonus:</span>
                <span>&minus; Rs <?= money($sale['bonus_discount']) ?></span>
            </div>
        <?php endif; ?>
        <div class="thermal-row thermal-grand">
            <span>NET PAYABLE:</span>
            <span>Rs <?= money($sale['net_payable']) ?></span>
        </div>
        <div class="thermal-row">
            <span>Payment Mode:</span>
            <strong><?= e($sale['payment_mode']) ?></strong>
        </div>
        <?php if ($sale['payment_mode'] === 'Cash' && $sale['paid_amount'] > 0): ?>
            <div class="thermal-row">
                <span>Cash Received:</span>
                <span>Rs <?= money($sale['paid_amount']) ?></span>
            </div>
            <div class="thermal-row">
                <span>Change Returned:</span>
                <span>Rs <?= money($sale['change_returned']) ?></span>
            </div>
        <?php elseif ($sale['payment_mode'] === 'Udhar'): ?>
            <div class="thermal-row" style="color: #b45309; font-weight: 700;">
                <span>CREDIT / UDHAR BILL</span>
                <span>Pending</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="thermal-line"></div>

    <div class="thermal-footer">
        <p><?= e($settings['receipt_footer']) ?></p>
        <div class="barcode-text">*<?= e($sale['invoice_number']) ?>*</div>
        <p style="font-size: 10px; color: #64748b; margin-top: 4px;">Powered by QuickPOS</p>
    </div>
</div>

<?php if ($autoprint): ?>
    <script>
        window.addEventListener('load', () => {
            window.print();
        });
    </script>
<?php endif; ?>
</body>
</html>