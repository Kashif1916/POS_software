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
<style>
    /* School Receipt Jaisi Sharp & Dark Thermal Formatting */
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        background: #f8fafc;
        color: #000000;
        width: 80mm;
    }
    .thermal-receipt {
        width: 76mm;
        margin: 10px auto;
        background: #fff;
        padding: 2mm;
        box-sizing: border-box;
        font-family: Arial, sans-serif;
        font-size: 13px;
        color: #000000;
        line-height: 1.3;
    }
    .thermal-header {
        text-align: center;
        margin-bottom: 2mm;
    }
    .thermal-header h2 {
        font-size: 18px;
        font-weight: 900;
        margin: 0 0 2px 0;
        text-transform: uppercase;
        color: #000000;
    }
    .thermal-header p {
        margin: 1mm 0;
        font-size: 12px;
        font-weight: bold;
        color: #000000;
    }
    .thermal-line {
        border-top: 1.5px solid #000000;
        margin: 2mm 0;
    }
    .thermal-meta {
        font-size: 12px;
        font-weight: bold;
        margin-bottom: 2mm;
        color: #000000;
    }
    .thermal-meta div {
        margin-bottom: 1.5mm;
    }
    .thermal-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        color: #000000;
        margin-bottom: 2mm;
    }
    .thermal-table th {
        border-bottom: 1.5px solid #000000;
        border-top: 1.5px solid #000000;
        padding: 2mm 0;
        text-align: left;
        font-weight: 900;
        color: #000000;
        text-transform: uppercase;
        font-size: 11px;
    }
    .thermal-table td {
        padding: 2mm 0;
        vertical-align: top;
        color: #000000;
        border-bottom: 1px dashed #000000;
        font-weight: 600;
    }
    .thermal-totals {
        margin-top: 2mm;
        font-size: 13px;
        font-weight: bold;
        color: #000000;
    }
    .thermal-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 1.5mm;
    }
    .thermal-grand {
        font-size: 16px;
        font-weight: 900;
        border-top: 2px solid #000000;
        border-bottom: 2px solid #000000;
        padding: 2mm 0;
        margin: 2mm 0;
        color: #000000;
    }
    .thermal-footer {
        text-align: center;
        font-size: 11px;
        font-weight: bold;
        margin-top: 3mm;
        color: #000000;
    }

    /* Offline Urdu Font Style */
    .urdu-text {
        font-family: 'Urdu Typesetting', 'Traditional Arabic', 'Jameel Noori Nastaliq', 'Noto Nastaliq Urdu', 'Segoe UI', Arial, sans-serif !important;
        font-size: 18.5px !important;
        font-weight: bold !important;
        line-height: 1.6 !important;
        direction: rtl;
        text-align: center;
        color: #000000 !important;
    }

    /* Print Specific Styles */
    @media print {
        @page {
            margin: 0;
            size: 80mm auto;
        }
        body {
            background: #fff !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 80mm !important;
        }
        .no-print {
            display: none !important;
        }
        .thermal-receipt {
            width: 76mm !important;
            margin: 0 !important;
            padding: 2mm !important;
            border: none !important;
            box-shadow: none !important;
        }
        * {
            color: #000000 !important;
            text-shadow: none !important;
        }
    }
</style>
</head>
<body>
<div style="text-align: center; margin: 20px 0;" class="no-print">
    <button onclick="window.print()" style="padding: 8px 16px; font-weight: bold; cursor: pointer;">
        🖨️ Print Receipt (80mm)
    </button>
    <button onclick="window.close()" style="padding: 8px 16px; font-weight: bold; cursor: pointer;">Close Window</button>
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
            <?php foreach ($items as $item): 
                $isKg = ($item['unit'] ?? 'piece') === 'kg';
                $qty = (float) $item['quantity'];
                if ($isKg) {
                    $qtyText = ($qty < 1.0) ? (round($qty * 1000) . 'g') : (rtrim(rtrim(number_format($qty, 3), '0'), '.') . ' kg');
                } else {
                    $qtyText = (int) $qty;
                }
            ?>
                <tr>
                    <td style="font-weight: bold;">
                        <?= e($item['product_name']) ?>
                        <?php if ($isKg): ?><small style="font-size:10px;"> (KG)</small><?php endif; ?>
                    </td>
                    <td style="text-align: center; font-weight: bold;"><?= $qtyText ?></td>
                    <td style="text-align: right;"><?= money($item['unit_price']) ?><?= $isKg ? '<small style="font-size:10px;">/kg</small>' : '' ?></td>
                    <td style="text-align: right; font-weight: 900;"><?= money($item['total_price']) ?></td>
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
            <div class="thermal-row" style="font-weight: 900;">
                <span>CREDIT / UDHAR BILL</span>
                <span>Pending</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="thermal-line"></div>

    <div class="thermal-footer">
         <p class="urdu-text" style="margin: 0 0 4px 0;">اپنے کاروبار کے لیے 2000 روپے ماہانہ پر سافٹ ویئر حاصل کرنے کے لیے رابطہ کریں</p>
            <p style="font-size: 12px; color: #000; margin: 0;">KJ Software House Khushab<br>Phone: 03180711280</p>
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