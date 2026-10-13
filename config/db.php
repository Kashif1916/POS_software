<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'pos_software';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        // Connect directly to MySQL
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        // Ensure database exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");

        // Ensure all required tables and columns exist automatically
        ensure_schema($pdo);

    } catch (PDOException $e) {
        die('Database Connection Failed: ' . htmlspecialchars($e->getMessage()));
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
        full_name VARCHAR(120) NULL,
        role ENUM('Admin','Operator') NOT NULL DEFAULT 'Operator',
        status ENUM('Active','Frozen') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
        sale_id BIGINT UNSIGNED NOT NULL UNIQUE,
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
        sale_id BIGINT UNSIGNED NOT NULL,
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
        store_name VARCHAR(150) NOT NULL DEFAULT 'Super POS Mart',
        phone VARCHAR(50) NOT NULL DEFAULT '0300-1234567',
        address VARCHAR(255) NOT NULL DEFAULT 'Shop # 12, Main Commercial Market',
        currency_symbol VARCHAR(10) NOT NULL DEFAULT 'Rs',
        receipt_footer VARCHAR(255) NOT NULL DEFAULT 'Thank you for shopping with us! Items can be returned within 3 days with receipt.'
    ) ENGINE=InnoDB");

    // Seed default settings if empty
    $chkSettings = $pdo->query("SELECT COUNT(*) FROM store_settings")->fetchColumn();
    if ($chkSettings == 0) {
        $pdo->exec("INSERT INTO store_settings (id, store_name, phone, address, currency_symbol, receipt_footer) 
            VALUES (1, 'Super POS Mart', '0300-1234567', 'Shop # 12, Main Commercial Market', 'Rs', 'Thank you for shopping with us! Items can be returned within 3 days with receipt.')");
    }

    // Seed default admin if empty
    $chkAdmin = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn();
    if ($chkAdmin == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, status) VALUES ('admin', ?, 'Administrator', 'Admin', 'Active')")->execute([$hash]);
    }

    // Seed default operator if empty
    $chkOp = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Operator'")->fetchColumn();
    if ($chkOp == 0) {
        $hash = password_hash('operator123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, status) VALUES ('operator', ?, 'Cashier Operator', 'Operator', 'Active')")->execute([$hash]);
    }

    $migrated = true;
}
