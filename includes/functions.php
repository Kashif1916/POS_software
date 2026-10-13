<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user && ($user['role'] ?? '') === 'Admin';
}

function require_login(?string $role = null): void
{
    $user = current_user();
    if (!$user) {
        redirect('/POS_software/login.php');
    }

    // Check if user is frozen in DB
    $pdo = db();
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user['id']]);
    $status = $stmt->fetchColumn();

    if ($status === 'Frozen') {
        session_destroy();
        session_start();
        flash('error', 'Your account has been frozen by an administrator.');
        redirect('/POS_software/login.php');
    }

    if ($role !== null && $user['role'] !== $role) {
        flash('error', 'You do not have permission to access that page.');
        redirect($user['role'] === 'Admin' ? '/POS_software/admin/dashboard.php' : '/POS_software/operator/index.php');
    }
}

function money(float|int|string|null $amount): string
{
    return number_format((float) ($amount ?? 0), 2);
}

function currency(): string
{
    return 'Rs';
}

function get_store_settings(PDO $pdo): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $row = $pdo->query("SELECT * FROM store_settings WHERE id = 1 LIMIT 1")->fetch();
    if (!$row) {
        $row = [
            'store_name' => 'Super POS Mart',
            'phone' => '0300-1234567',
            'address' => 'Shop # 12, Main Commercial Market',
            'currency_symbol' => 'Rs',
            'receipt_footer' => 'Thank you for shopping with us! Items can be returned within 3 days with receipt.'
        ];
    }
    $settings = $row;
    return $settings;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid security session token. Please refresh the page and try again.');
    }
}

function post_string(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function post_int(string $key, int $default = 0): int
{
    return max(0, (int) ($_POST[$key] ?? $default));
}

function post_float(string $key, float $default = 0.0): float
{
    return max(0.0, (float) ($_POST[$key] ?? $default));
}

function today_stats(PDO $pdo): array
{
    // Today's Sales breakdown
    $salesRow = $pdo->query("SELECT
        COALESCE(SUM(CASE WHEN status = 'Completed' THEN net_payable ELSE 0 END), 0) AS total_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Cash' THEN net_payable ELSE 0 END), 0) AS cash_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Bank' THEN net_payable ELSE 0 END), 0) AS bank_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Udhar' THEN net_payable ELSE 0 END), 0) AS udhar_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' THEN bonus_discount ELSE 0 END), 0) AS total_discounts,
        COUNT(CASE WHEN status = 'Completed' THEN 1 END) AS invoice_count
        FROM sales WHERE DATE(created_at) = CURDATE()")->fetch();

    // Cost of goods sold today for profit calculation
    $cogsRow = $pdo->query("SELECT
        COALESCE(SUM(si.cost_price * si.quantity), 0) AS total_cogs
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE s.status = 'Completed' AND DATE(s.created_at) = CURDATE()")->fetch();

    // Dealer payments today
    $dealerRow = $pdo->query("SELECT
        COALESCE(SUM(paid_amount), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN paid_amount ELSE 0 END), 0) AS cash_paid,
        COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN paid_amount ELSE 0 END), 0) AS bank_paid
        FROM dealer_payments WHERE date = CURDATE()")->fetch();

    // Expenses today
    $expenseRow = $pdo->query("SELECT
        COALESCE(SUM(amount), 0) AS total_expense,
        COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount ELSE 0 END), 0) AS cash_expense,
        COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN amount ELSE 0 END), 0) AS bank_expense
        FROM expenses WHERE date = CURDATE()")->fetch();

    // Udhar payments recovered today
    $udharRecRow = $pdo->query("SELECT
        COALESCE(SUM(amount_paid), 0) AS total_recovered,
        COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount_paid ELSE 0 END), 0) AS cash_recovered,
        COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN amount_paid ELSE 0 END), 0) AS bank_recovered
        FROM udhar_payments WHERE date = CURDATE()")->fetch();

    // Returns today
    $returnsRow = $pdo->query("SELECT
        COUNT(*) AS returns_count,
        COALESCE(SUM(refund_amount), 0) AS total_refund
        FROM returns WHERE date = CURDATE()")->fetch();

    // Low stock count
    $lowStockCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_alert_limit")->fetchColumn();

    // Overall outstanding Udhar balance
    $totalPendingUdhar = (float) $pdo->query("SELECT COALESCE(SUM(balance_amount), 0) FROM udhar_ledger WHERE status = 'Pending'")->fetchColumn();

    // Drawer Cash calculation:
    // (Cash Sales + Cash Udhar Collected) - (Cash Dealer Payments + Cash Expenses + Cash Refunds)
    $drawerCash = ((float) $salesRow['cash_sales'] + (float) $udharRecRow['cash_recovered'])
                - ((float) $dealerRow['cash_paid'] + (float) $expenseRow['cash_expense'] + (float) $returnsRow['total_refund']);

    // Profit calculation:
    // Gross Profit = Total Sales - Cost of Goods Sold
    // Net Profit = Gross Profit - Expenses - Returns Refunded
    $grossProfit = (float) $salesRow['total_sales'] - (float) $cogsRow['total_cogs'];
    $netProfit = $grossProfit - (float) $expenseRow['total_expense'] - (float) $returnsRow['total_refund'];

    return [
        'total_sales' => (float) $salesRow['total_sales'],
        'cash_sales' => (float) $salesRow['cash_sales'],
        'bank_sales' => (float) $salesRow['bank_sales'],
        'udhar_sales' => (float) $salesRow['udhar_sales'],
        'total_discounts' => (float) $salesRow['total_discounts'],
        'invoice_count' => (int) $salesRow['invoice_count'],
        'total_cogs' => (float) $cogsRow['total_cogs'],
        'dealer_paid' => (float) $dealerRow['total_paid'],
        'dealer_cash_paid' => (float) $dealerRow['cash_paid'],
        'dealer_bank_paid' => (float) $dealerRow['bank_paid'],
        'total_expense' => (float) $expenseRow['total_expense'],
        'cash_expense' => (float) $expenseRow['cash_expense'],
        'bank_expense' => (float) $expenseRow['bank_expense'],
        'udhar_recovered' => (float) $udharRecRow['total_recovered'],
        'udhar_cash_recovered' => (float) $udharRecRow['cash_recovered'],
        'returns_count' => (int) $returnsRow['returns_count'],
        'total_refund' => (float) $returnsRow['total_refund'],
        'drawer_cash' => max(0, $drawerCash),
        'gross_profit' => $grossProfit,
        'net_profit' => $netProfit,
        'low_stock_count' => $lowStockCount,
        'pending_udhar_balance' => $totalPendingUdhar
    ];
}
