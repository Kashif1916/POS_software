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
    <style>
        /* Sharp & Dark Thermal Formatting - Matched with print_invoice.php */
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
        .receipt-tag {
            display: inline-block;
            font-weight: 900;
            font-size: 13px;
            padding: 2px 8px;
            border: 1.5px solid #000000;
            margin-top: 4px;
            text-transform: uppercase;
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
        .thermal-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5mm;
            font-size: 12.5px;
            font-weight: bold;
            color: #000000;
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
        .sig-container {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 5px;
        }
        .sig-box {
            width: 120px;
            border-top: 1.5px dashed #000000;
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            color: #000000;
            padding-top: 4px;
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
            🖨️ Print Thermal Slip (80mm)
        </button>
        <button onclick="window.close()" style="padding: 8px 16px; font-weight: bold; cursor: pointer;">Close</button>
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

        <div style="font-size: 12px; margin-bottom: 2mm;">
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

        <div class="thermal-row" style="font-size: 12.5px; margin-top: 6px;">
            <span>Remaining Balance:</span>
            <strong>Rs <?= money($payment['pending_payable_amount']) ?></strong>
        </div>

        <div class="sig-container">
            <div class="sig-box">Dealer Sign</div>
            <div class="sig-box">Store Stamp / Sign</div>
        </div>

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