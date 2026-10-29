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

function detect_base_url(): string
{
    if (defined('CUSTOM_BASE_PATH')) {
        return rtrim((string) CUSTOM_BASE_PATH, '/');
    }

    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' 
        ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) 
        : '';
    $appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));

    if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
        $sub = substr($appRoot, strlen($docRoot));
        return rtrim(str_replace('\\', '/', $sub), '/');
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptName !== '') {
        foreach (['/admin/', '/operator/', '/includes/', '/assets/', '/config/'] as $folder) {
            $pos = strpos($scriptName, $folder);
            if ($pos !== false) {
                return rtrim(substr($scriptName, 0, $pos), '/');
            }
        }
        $dir = dirname($scriptName);
        if ($dir !== '/' && $dir !== '\\' && $dir !== '.') {
            return rtrim($dir, '/');
        }
    }

    return '';
}

function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $base = detect_base_url();
    }

    if (str_starts_with($path, '/POS_software/') || $path === '/POS_software') {
        $path = substr($path, strlen('/POS_software'));
    }

    if ($base !== '' && (str_starts_with($path, $base . '/') || $path === $base)) {
        return $path;
    }

    $cleanPath = '/' . ltrim($path, '/');
    if ($path === '' || $path === '/') {
        return $base === '' ? '/' : $base . '/';
    }
    return $base . $cleanPath;
}

function url(string $path = ''): string
{
    return base_url($path);
}

function redirect(string $path): never
{
    if (preg_match('#^https?://#i', $path)) {
        header('Location: ' . $path);
        exit;
    }

    $base = base_url('');
    if ($base !== '' && (str_starts_with($path, $base . '/') || $path === $base)) {
        header('Location: ' . $path);
        exit;
    }

    if (str_starts_with($path, '/POS_software/') || $path === '/POS_software') {
        $path = substr($path, strlen('/POS_software'));
    }

    header('Location: ' . url($path));
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
    return $user && in_array($user['role'] ?? '', ['Admin', 'Master'], true);
}

function is_master(): bool
{
    $user = current_user();
    return $user && ($user['role'] ?? '') === 'Master';
}

function require_login(?string $role = null): void
{
    $user = current_user();
    if (!$user) {
        redirect('/login.php');
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user['id']]);
    $status = $stmt->fetchColumn();

    if ($status === 'Frozen') {
        session_destroy();
        session_start();
        flash('error', 'Your account has been frozen by an administrator.');
        redirect('/login.php');
    }

    if ($role !== null) {
        $allowed = false;
        if ($role === 'Master') {
            $allowed = ($user['role'] === 'Master');
        } elseif ($role === 'Admin') {
            $allowed = in_array($user['role'], ['Admin', 'Master'], true);
        } elseif ($role === 'Operator') {
            $allowed = in_array($user['role'], ['Operator', 'Admin', 'Master'], true);
        } else {
            $allowed = ($user['role'] === $role);
        }

        if (!$allowed) {
            flash('error', 'You do not have permission to access that page.');
            redirect(in_array($user['role'], ['Admin', 'Master'], true) ? '/admin/dashboard.php' : '/operator/index.php');
        }
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
            'store_name' => 'Al Mehbob Traders',
            'phone' => '0309-7107977',
            'address' => 'Janubi Bazar Jauharabad',
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
    $salesRow = $pdo->query("SELECT
        COALESCE(SUM(CASE WHEN status = 'Completed' THEN net_payable ELSE 0 END), 0) AS total_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Cash' THEN net_payable ELSE 0 END), 0) AS cash_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Bank' THEN net_payable ELSE 0 END), 0) AS bank_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_mode = 'Udhar' THEN net_payable ELSE 0 END), 0) AS udhar_sales,
        COALESCE(SUM(CASE WHEN status = 'Completed' THEN bonus_discount ELSE 0 END), 0) AS total_discounts,
        COUNT(CASE WHEN status = 'Completed' THEN 1 END) AS invoice_count
        FROM sales WHERE DATE(created_at) = CURDATE()")->fetch();

    $cogsRow = $pdo->query("SELECT
        COALESCE(SUM(si.cost_price * si.quantity), 0) AS total_cogs
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE s.status = 'Completed' AND DATE(s.created_at) = CURDATE()")->fetch();

    $dealerRow = $pdo->query("SELECT
        COALESCE(SUM(paid_amount), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN paid_amount ELSE 0 END), 0) AS cash_paid,
        COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN paid_amount ELSE 0 END), 0) AS bank_paid
        FROM dealer_payments WHERE date = CURDATE()")->fetch();

    $expenseRow = $pdo->query("SELECT
        COALESCE(SUM(amount), 0) AS total_expense,
        COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount ELSE 0 END), 0) AS cash_expense,
        COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN amount ELSE 0 END), 0) AS bank_expense
        FROM expenses WHERE date = CURDATE()")->fetch();

    $udharRecRow = $pdo->query("SELECT
        COALESCE(SUM(amount_paid), 0) AS total_recovered,
        COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount_paid ELSE 0 END), 0) AS cash_recovered,
        COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN amount_paid ELSE 0 END), 0) AS bank_recovered
        FROM udhar_payments WHERE date = CURDATE()")->fetch();

    $returnsRow = $pdo->query("SELECT
        COUNT(*) AS returns_count,
        COALESCE(SUM(refund_amount), 0) AS total_refund
        FROM returns WHERE date = CURDATE()")->fetch();

    $lowStockCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_alert_limit")->fetchColumn();
    $totalPendingUdhar = (float) $pdo->query("SELECT COALESCE(SUM(balance_amount), 0) FROM udhar_ledger WHERE status = 'Pending'")->fetchColumn();

    $drawerCash = ((float) $salesRow['cash_sales'] + (float) $udharRecRow['cash_recovered'])
                - ((float) $dealerRow['cash_paid'] + (float) $expenseRow['cash_expense'] + (float) $returnsRow['total_refund']);

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

/**
 * PERSISTENT SOFTWARE LOCK FUNCTIONS
 */
function get_active_software_lock(): ?array
{
    static $lockChecked = false;
    static $activeLock = null;

    if ($lockChecked) {
        return $activeLock;
    }
    $lockChecked = true;

    try {
        $pdo = db();
        // Fetch any lock triggered on or before today that has NOT been unlocked yet
        $stmt = $pdo->prepare("SELECT * FROM software_locks WHERE lock_date <= CURDATE() AND is_active = 1 AND is_unlocked = 0 ORDER BY lock_date ASC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        $activeLock = $row ?: null;
    } catch (Throwable $e) {
        $activeLock = null;
    }

    return $activeLock;
}

function is_software_locked(): bool
{
    return (get_active_software_lock() !== null);
}

function check_software_lock(): void
{
    if (php_sapi_name() === 'cli') {
        return;
    }

    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    // Allow lock screen itself and logout script
    if (in_array($scriptName, ['lock.php', 'logout.php'], true)) {
        return;
    }

    if (!is_software_locked()) {
        return;
    }

    // Allow Master user to navigate admin pages to manage locks/settings
    $normalizedScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (is_master() && str_contains($normalizedScript, '/admin/')) {
        return;
    }

    // Everyone else (including login.php, operator, unauthenticated visitors) goes straight to lock screen
    redirect('/lock.php');
}

// Enforce software lock automatically on every request
check_software_lock();