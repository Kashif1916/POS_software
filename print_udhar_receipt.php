<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

if (!$id) {
    die('Invalid receipt ID');
}

$stmt = $pdo->prepare("SELECT up.*, ul.buyer_name, ul.buyer_phone, ul.balance_amount as current_balance, s.invoice_number, u.username as received_by, u.full_name as received_by_fullname 
    FROM udhar_payments up 
    JOIN udhar_ledger ul ON ul.id = up.udhar_id 
    JOIN sales s ON s.id = up.sale_id 
    LEFT JOIN users u ON u.id = up.created_by 
    WHERE up.id = ?");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    die('Udhar payment voucher not found.');
}

$settings = get_store_settings($pdo);
$autoprint = isset($_GET['autoprint']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wasooli Receipt #<?= e($payment['receipt_number']) ?></title>
    <link rel="stylesheet" href="/POS_software/assets/css/style.css">
    <style>
        .thermal-receipt {
            width: 320px;
            margin: 20px auto;
            background: #fff;
            padding: 20px 15px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            color: #000;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            line-height: 1.35;
        }
        .thermal-header {
            text-align: center;
            margin-bottom: 12px;
        }
        .thermal-header h2 {
            font-size: 17px;
            font-weight: 900;
            margin: 0;
            text-transform: uppercase;
        }
        .thermal-header p {
            margin: 2px 0;
            font-size: 11px;
        }
        .receipt-tag {
            display: inline-block;
            font-weight: 900;
            font-size: 13px;
            padding: 2px 8px;
            border: 1px dashed #000;
            margin-top: 6px;
            text-transform: uppercase;
        }
        .thermal-line {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }
        .thermal-meta {
            font-size: 11px;
            margin-bottom: 8px;
        }
        .thermal-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }
        .thermal-grand {
            font-size: 15px;
            font-weight: 900;
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            padding: 6px 0;
            margin: 8px 0;
        }
        .sig-container {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 5px;
        }
        .sig-box {
            width: 130px;
            border-top: 1px dashed #000;
            text-align: center;
            font-size: 10px;
            padding-top: 3px;
        }
        .thermal-footer {
            text-align: center;
            font-size: 10px;
            margin-top: 16px;
        }
    </style>
</head>
<body style="background: #f8fafc;">
    <div style="text-align: center; margin: 20px 0;" class="no-print">
        <button onclick="window.print()" class="btn btn-primary">
            🖨️ Print Thermal Slip (80mm)
        </button>
        <button onclick="window.close()" class="btn btn-light">Close</button>
    </div>

    <div class="thermal-receipt">
        <div class="thermal-header">
            <h2><?= e($settings['store_name']) ?></h2>
            <p><?= e($settings['address']) ?></p>
            <p>Phone: <?= e($settings['phone']) ?></p>
            <div class="receipt-tag">UDHAR WASOOLI SLIP</div>
        </div>

        <div class="thermal-line"></div>

        <div class="thermal-meta">
            <div class="thermal-row">
                <span>Receipt #:</span>
                <strong><?= e($payment['receipt_number']) ?></strong>
            </div>
            <div class="thermal-row">
                <span>Date:</span>
                <span><?= date('d/m/Y h:i A', strtotime($payment['created_at'])) ?></span>
            </div>
            <div class="thermal-row">
                <span>Cashier:</span>
                <span><?= e($payment['received_by_fullname'] ?: $payment['received_by']) ?></span>
            </div>
            <div class="thermal-row">
                <span>Invoice Ref:</span>
                <span><?= e($payment['invoice_number']) ?></span>
            </div>
        </div>

        <div class="thermal-line"></div>

        <div style="font-size: 11.5px; margin-bottom: 8px;">
            <div class="thermal-row">
                <span>Customer:</span>
                <strong><?= e($payment['buyer_name']) ?></strong>
            </div>
            <?php if ($payment['buyer_phone']): ?>
                <div class="thermal-row">
                    <span>Phone:</span>
                    <span><?= e($payment['buyer_phone']) ?></span>
                </div>
            <?php endif; ?>
            <div class="thermal-row">
                <span>Payment Mode:</span>
                <strong><?= e($payment['payment_mode']) ?></strong>
            </div>
            <?php if ($payment['notes']): ?>
                <div class="thermal-row">
                    <span>Notes:</span>
                    <span><?= e($payment['notes']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="thermal-row thermal-grand">
            <span>AMOUNT RECEIVED:</span>
            <span>Rs <?= money($payment['amount_paid']) ?></span>
        </div>

        <div class="thermal-row" style="font-size: 11.5px; margin-top: 6px;">
            <span>Remaining Udhar:</span>
            <strong style="color: <?= $payment['current_balance'] > 0 ? '#b45309' : '#16a34a' ?>;">
                Rs <?= money($payment['current_balance']) ?>
            </strong>
        </div>

        <div class="sig-container">
            <div class="sig-box">Customer Sign</div>
            <div class="sig-box">Store Stamp / Sign</div>
        </div>

        <div class="thermal-footer">
            <p>Thank you for clearing your account!</p>
            <p style="font-size: 9px; color: #555; margin-top: 3px;">Powered by QuickPOS</p>
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
