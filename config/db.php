<?php
declare(strict_types=1);

/**
 * DATABASE CONFIGURATION
 * 
 * Local XAMPP Default:
 *   DB_HOST = '127.0.0.1' or 'localhost'
 *   DB_NAME = 'pos_software'
 *   DB_USER = 'root'
 *   DB_PASS = ''
 * 
 * InfinityFree Hosting Example:
 *   DB_HOST = 'sqlxxx.infinityfree.com' (check your InfinityFree vPanel -> MySQL Details)
 *   DB_NAME = 'epiz_xxxxxx_pos_software' (check vPanel -> MySQL Databases)
 *   DB_USER = 'epiz_xxxxxx'
 *   DB_PASS = 'Your vPanel Password'
 */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'pos_software';
const DB_USER = 'root';
const DB_PASS = '';

/**
 * OPTIONAL: Base path override.
 * Leave as null to auto-detect base path dynamically (works for both InfinityFree and XAMPP).
 * If you ever need to manually force a specific base path, uncomment and set below:
 * define('CUSTOM_BASE_PATH', ''); // For domain root, e.g. https://yourdomain.com/
 * define('CUSTOM_BASE_PATH', '/POS_software'); // For subfolder
 */
// define('CUSTOM_BASE_PATH', '');

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        // Attempt connecting directly with dbname specified in DSN (standard for live shared hosting like InfinityFree)
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            // ── Force MySQL timezone to Pakistan Standard Time (+05:00) ───────
            $pdo->exec("SET time_zone = '+05:00'");
            // ─────────────────────────────────────────────────────────────────
        } catch (PDOException $ex) {
            // If unknown database error (error code 1049) on local development, try to create it automatically
            if ($ex->getCode() == 1049 || str_contains($ex->getMessage(), 'Unknown database')) {
                $tempPdo = new PDO(
                    'mysql:host=' . DB_HOST . ';charset=utf8mb4',
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                unset($tempPdo);

                $pdo = new PDO(
                    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } else {
                throw $ex;
            }
        }

        // Ensure all required tables and columns exist automatically
        ensure_schema($pdo);

    } catch (PDOException $e) {
        die('Database Connection Failed: ' . htmlspecialchars($e->getMessage()) . '<br><br><b>Tip for InfinityFree:</b> Please check your database credentials in <code>config/db.php</code> (DB_HOST, DB_NAME, DB_USER, DB_PASS).');
    }

    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    static $migrated = false;
    if ($migrated) return;

    // Users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        plain_password VARCHAR(255) NULL,
        full_name VARCHAR(120) NULL,
        role VARCHAR(30) NOT NULL DEFAULT 'Operator',
        status ENUM('Active','Frozen') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS software_locks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lock_date DATE NOT NULL,
        unlock_password VARCHAR(255) NOT NULL,
        lock_message TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_unlocked TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Categories table
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE
    ) ENGINE=InnoDB");

    // Dealers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS dealers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(40) NULL,
        company_name VARCHAR(150) NULL,
        products_supplied TEXT NULL,
        pending_payable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Products table
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        category_id INT UNSIGNED NULL,
        barcode VARCHAR(80) NULL,
        manufacturer_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        market_sale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        dealer_id INT UNSIGNED NULL,
        stock_quantity INT NOT NULL DEFAULT 0,
        min_alert_limit INT NOT NULL DEFAULT 10,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(category_id),
        INDEX(dealer_id)
    ) ENGINE=InnoDB");

    // Sales table
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(40) NOT NULL UNIQUE,
        buyer_name VARCHAR(150) NULL,
        buyer_phone VARCHAR(40) NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        bonus_discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        net_payable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        change_returned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_mode ENUM('Cash','Bank','Udhar') NOT NULL DEFAULT 'Cash',
        status ENUM('Completed','Returned') NOT NULL DEFAULT 'Completed',
        operator_id INT UNSIGNED NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(operator_id),
        INDEX(created_at)
    ) ENGINE=InnoDB");

    // Sale items table
    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sale_id BIGINT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        quantity INT NOT NULL,
        cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        unit_price DECIMAL(12,2) NOT NULL,
        total_price DECIMAL(12,2) NOT NULL,
        INDEX(sale_id),
        INDEX(product_id)
    ) ENGINE=InnoDB");

    // Dealer payments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS dealer_payments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dealer_id INT UNSIGNED NOT NULL,
        paid_amount DECIMAL(12,2) NOT NULL,
        payment_mode ENUM('Cash','Bank') NOT NULL DEFAULT 'Cash',
        receipt_number VARCHAR(40) NOT NULL UNIQUE,
        date DATE NOT NULL,
        notes TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(dealer_id)
    ) ENGINE=InnoDB");

    // Expenses table
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_title VARCHAR(150) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_mode ENUM('Cash','Bank') NOT NULL DEFAULT 'Cash',
        notes TEXT NULL,
        date DATE NOT NULL,
        recorded_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Returns table
    $pdo->exec("CREATE TABLE IF NOT EXISTS returns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sale_id BIGINT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        quantity INT NOT NULL,
        refund_amount DECIMAL(12,2) NOT NULL,
        date DATE NOT NULL,
        reason VARCHAR(255) NULL,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(sale_id),
        INDEX(product_id)
    ) ENGINE=InnoDB");

    // Udhar ledger table
    $pdo->exec("CREATE TABLE IF NOT EXISTS udhar_ledger (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sale_id BIGINT UNSIGNED NULL,
        buyer_name VARCHAR(150) NULL,
        buyer_phone VARCHAR(40) NULL,
        total_amount DECIMAL(12,2) NOT NULL,
        paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        balance_amount DECIMAL(12,2) NOT NULL,
        status ENUM('Pending','Cleared') NOT NULL DEFAULT 'Pending',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(sale_id)
    ) ENGINE=InnoDB");

    // Udhar payments table (customer credit recovery)
    $pdo->exec("CREATE TABLE IF NOT EXISTS udhar_payments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        udhar_id BIGINT UNSIGNED NOT NULL,
        sale_id BIGINT UNSIGNED NULL,
        amount_paid DECIMAL(12,2) NOT NULL,
        payment_mode ENUM('Cash','Bank') NOT NULL DEFAULT 'Cash',
        receipt_number VARCHAR(40) NOT NULL UNIQUE,
        date DATE NOT NULL,
        notes VARCHAR(255) NULL,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(udhar_id)
    ) ENGINE=InnoDB");

    // Store settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_settings (
        id INT PRIMARY KEY DEFAULT 1,
        store_name VARCHAR(150) NOT NULL DEFAULT 'Al Mehbob Traders',
        phone VARCHAR(50) NOT NULL DEFAULT '0309-7107977',
        address VARCHAR(255) NOT NULL DEFAULT 'Janubi Bazar Jauharabad',
        currency_symbol VARCHAR(10) NOT NULL DEFAULT 'Rs',
        receipt_footer VARCHAR(255) NOT NULL DEFAULT 'Thank you for shopping with us! Items can be returned within 3 days with receipt.'
    ) ENGINE=InnoDB");

    // Software locks table (for master scheduled system lockouts)
    $pdo->exec("CREATE TABLE IF NOT EXISTS software_locks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lock_date DATE NOT NULL UNIQUE,
        unlock_password VARCHAR(255) NOT NULL,
        lock_message TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_unlocked TINYINT(1) NOT NULL DEFAULT 0,
        unlocked_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Udhar Customers (for customer credit registry and limit enforcement)
    $pdo->exec("CREATE TABLE IF NOT EXISTS udhar_customers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(40) NULL,
        address VARCHAR(255) NULL,
        credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Dynamic Column & Precision Migrations for existing tables
    try {
        // Users table migrations: role column and plain_password
        $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('plain_password', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN plain_password VARCHAR(255) NULL AFTER password_hash");
        }
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(30) NOT NULL DEFAULT 'Operator'");

        // Populate plain_password for known defaults if empty
        $pdo->exec("UPDATE users SET plain_password = 'admin123' WHERE username = 'admin' AND (plain_password IS NULL OR plain_password = '')");
        $pdo->exec("UPDATE users SET plain_password = 'operator123' WHERE username = 'operator' AND (plain_password IS NULL OR plain_password = '')");

        // Products table migrations: unit, expiry_date, and decimal stock
        $prodCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('unit', $prodCols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'piece' AFTER min_alert_limit");
        }
        if (!in_array('expiry_date', $prodCols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN expiry_date DATE NULL AFTER unit");
        }
        // Ensure decimal precision for kilograms/grams
        $pdo->exec("ALTER TABLE products MODIFY stock_quantity DECIMAL(10,3) NOT NULL DEFAULT 0.000");
        $pdo->exec("ALTER TABLE products MODIFY min_alert_limit DECIMAL(10,3) NOT NULL DEFAULT 5.000");

        // Sale items migrations: decimal quantity and unit
        $saleItemCols = $pdo->query("SHOW COLUMNS FROM sale_items")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('unit', $saleItemCols)) {
            $pdo->exec("ALTER TABLE sale_items ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'piece' AFTER quantity");
        }
        $pdo->exec("ALTER TABLE sale_items MODIFY quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000");

        // Returns migrations: decimal quantity
        $pdo->exec("ALTER TABLE returns MODIFY quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000");

        // Udhar ledger migrations: customer_id
        $udharLedgerCols = $pdo->query("SHOW COLUMNS FROM udhar_ledger")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('customer_id', $udharLedgerCols)) {
            $pdo->exec("ALTER TABLE udhar_ledger ADD COLUMN customer_id INT UNSIGNED NULL AFTER sale_id");
        }

        // FIX: Make sale_id NULLABLE for opening balance / purana udhar entries in running systems
        $pdo->exec("ALTER TABLE udhar_ledger MODIFY COLUMN sale_id BIGINT UNSIGNED NULL");
        $pdo->exec("ALTER TABLE udhar_payments MODIFY COLUMN sale_id BIGINT UNSIGNED NULL");

        // Software locks migrations: is_unlocked and unlocked_at
        $lockCols = $pdo->query("SHOW COLUMNS FROM software_locks")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_unlocked', $lockCols)) {
            $pdo->exec("ALTER TABLE software_locks ADD COLUMN is_unlocked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        }
        if (!in_array('unlocked_at', $lockCols)) {
            $pdo->exec("ALTER TABLE software_locks ADD COLUMN unlocked_at DATETIME NULL AFTER is_unlocked");
        }

        // Udhar Customers migrations: opening_balance
        $udharCustCols = $pdo->query("SHOW COLUMNS FROM udhar_customers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('opening_balance', $udharCustCols)) {
            $pdo->exec("ALTER TABLE udhar_customers ADD COLUMN opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER credit_limit");
        }
    } catch (Throwable $e) {
        // Log migration note if already modified
    }

    // Seed or update default settings
    $chkSettings = $pdo->query("SELECT COUNT(*) FROM store_settings")->fetchColumn();
    if ($chkSettings == 0) {
        $pdo->exec("INSERT INTO store_settings (id, store_name, phone, address, currency_symbol, receipt_footer) 
            VALUES (1, 'Al Mehbob Traders', '0309-7107977', 'Janubi Bazar Jauharabad', 'Rs', 'Thank you for shopping with us! Items can be returned within 3 days with receipt.')");
    } else {
        // If settings still has the default old store name, update to user's desired store name
        $pdo->exec("UPDATE store_settings SET store_name = 'Al Mehbob Traders', phone = '0309-7107977', address = 'Janubi Bazar Jauharabad' WHERE id = 1 AND store_name = 'Super POS Mart'");
    }

    // Seed default master if empty
    $chkMaster = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Master' OR username = 'master'")->fetchColumn();
    if ($chkMaster == 0) {
        $hash = password_hash('master123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password_hash, plain_password, full_name, role, status) VALUES ('master', ?, 'master123', 'Master User', 'Master', 'Active')")->execute([$hash]);
    }

    // Seed default admin if empty
    $chkAdmin = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn();
    if ($chkAdmin == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password_hash, plain_password, full_name, role, status) VALUES ('admin', ?, 'admin123', 'Administrator', 'Admin', 'Active')")->execute([$hash]);
    }

    // Seed default operator if empty
    $chkOp = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Operator'")->fetchColumn();
    if ($chkOp == 0) {
        $hash = password_hash('operator123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password_hash, plain_password, full_name, role, status) VALUES ('operator', ?, 'operator123', 'Cashier Operator', 'Operator', 'Active')")->execute([$hash]);
    }

    $migrated = true;
}