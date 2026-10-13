<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

if (!$id) {
    die('Invalid receipt ID');
}

$stmt = $pdo->prepare("SELECT dp.*, d.name as dealer_name, d.phone, d.company_name, d.pending_payable_amount, u.username as paid_by, u.full_name as paid_by_fullname 
    FROM dealer_payments dp 
    JOIN dealers d ON d.id = dp.dealer_id 
    LEFT JOIN users u ON u.id = dp.created_by 
    WHERE dp.id = ?");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    die('Dealer payment voucher not found.');
}

$settings = get_store_settings($pdo);
$autoprint = isset($_GET['autoprint']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dealer Receipt #<?= e($payment['receipt_number']) ?></title>
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
            <div class="receipt-tag">DEALER PAYMENT SLIP</div>
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
                <span>Paid By:</span>
                <span><?= e($payment['paid_by_fullname'] ?: $payment['paid_by']) ?></span>
            </div>
        </div>

        <div class="thermal-line"></div>

        <div style="font-size: 11.5px; margin-bottom: 8px;">
            <div class="thermal-row">
                <span>Dealer:</span>
                <strong><?= e($payment['dealer_name']) ?></strong>
            </div>
            <?php if ($payment['company_name']): ?>
                <div class="thermal-row">
                    <span>Company:</span>
                    <span><?= e($payment['company_name']) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($payment['phone']): ?>
                <div class="thermal-row">
                    <span>Phone:</span>
                    <span><?= e($payment['phone']) ?></span>
                </div>
            <?php endif; ?>
            <div class="thermal-row">
                <span>Payment Mode:</span>
                <strong><?= e($payment['payment_mode']) ?></strong>
            </div>
            <?php if ($payment['notes']): ?>
                <div class="thermal-row">
                    <span>Notes / Ref:</span>
                    <span><?= e($payment['notes']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="thermal-row thermal-grand">
            <span>AMOUNT PAID:</span>
            <span>Rs <?= money($payment['paid_amount']) ?></span>
        </div>

        <div class="thermal-row" style="font-size: 11.5px; margin-top: 6px;">
            <span>Remaining Balance:</span>
            <strong>Rs <?= money($payment['pending_payable_amount']) ?></strong>
        </div>

        <div class="sig-container">
            <div class="sig-box">Dealer Sign</div>
            <div class="sig-box">Store Stamp / Sign</div>
        </div>

        <div class="thermal-footer">
            <p>Verified Payment Disbursement Voucher</p>
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
