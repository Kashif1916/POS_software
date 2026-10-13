-- ============================================================
-- Super POS Mart Database Schema
-- Compatible with XAMPP MySQL / MariaDB and phpMyAdmin
-- ============================================================

CREATE DATABASE IF NOT EXISTS pos_software CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pos_software;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS udhar_payments, udhar_ledger, returns, sale_items, sales, expenses, dealer_payments, products, categories, dealers, users, store_settings;
SET FOREIGN_KEY_CHECKS=1;

-- Users table
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NULL,
    role ENUM('Admin','Operator') NOT NULL DEFAULT 'Operator',
    status ENUM('Active','Frozen') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Categories table
CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Dealers / Suppliers table
CREATE TABLE dealers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(40) NULL,
    company_name VARCHAR(150) NULL,
    products_supplied TEXT NULL,
    pending_payable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Products & Inventory table
CREATE TABLE products (
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
    FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY(dealer_id) REFERENCES dealers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Sales invoices table
CREATE TABLE sales (
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
    FOREIGN KEY(operator_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Sale line items table
CREATE TABLE sale_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit_price DECIMAL(12,2) NOT NULL,
    total_price DECIMAL(12,2) NOT NULL,
    FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY(product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- Dealer payments table
CREATE TABLE dealer_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT UNSIGNED NOT NULL,
    paid_amount DECIMAL(12,2) NOT NULL,
    payment_mode ENUM('Cash','Bank') NOT NULL DEFAULT 'Cash',
    receipt_number VARCHAR(40) NOT NULL UNIQUE,
    date DATE NOT NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(dealer_id) REFERENCES dealers(id),
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Expenses table
CREATE TABLE expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_title VARCHAR(150) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_mode ENUM('Cash','Bank') NOT NULL DEFAULT 'Cash',
    notes TEXT NULL,
    date DATE NOT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Returns table
CREATE TABLE returns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    refund_amount DECIMAL(12,2) NOT NULL,
    date DATE NOT NULL,
    reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(sale_id) REFERENCES sales(id),
    FOREIGN KEY(product_id) REFERENCES products(id),
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Customer credit (Udhar) ledger
CREATE TABLE udhar_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL UNIQUE,
    buyer_name VARCHAR(150) NULL,
    buyer_phone VARCHAR(40) NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance_amount DECIMAL(12,2) NOT NULL,
    status ENUM('Pending','Cleared') NOT NULL DEFAULT 'Pending',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Customer credit recovery payments (Wasooli)
CREATE TABLE udhar_payments (
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
    FOREIGN KEY(udhar_id) REFERENCES udhar_ledger(id) ON DELETE CASCADE,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Store settings table
CREATE TABLE store_settings (
    id INT PRIMARY KEY DEFAULT 1,
    store_name VARCHAR(150) NOT NULL DEFAULT 'Super POS Mart',
    phone VARCHAR(50) NOT NULL DEFAULT '0300-1234567',
    address VARCHAR(255) NOT NULL DEFAULT 'Shop # 12, Main Commercial Market',
    currency_symbol VARCHAR(10) NOT NULL DEFAULT 'Rs',
    receipt_footer VARCHAR(255) NOT NULL DEFAULT 'Thank you for shopping with us! Items can be returned within 3 days with receipt.'
) ENGINE=InnoDB;

-- Automated triggers for inventory & balances
DELIMITER //
CREATE TRIGGER sale_item_after_insert AFTER INSERT ON sale_items FOR EACH ROW
BEGIN 
    UPDATE products SET stock_quantity = stock_quantity - NEW.quantity WHERE id = NEW.product_id; 
END//

CREATE TRIGGER return_after_insert AFTER INSERT ON returns FOR EACH ROW
BEGIN 
    UPDATE products SET stock_quantity = stock_quantity + NEW.quantity WHERE id = NEW.product_id; 
END//

CREATE TRIGGER dealer_payment_after_insert AFTER INSERT ON dealer_payments FOR EACH ROW
BEGIN 
    UPDATE dealers SET pending_payable_amount = GREATEST(0, pending_payable_amount - NEW.paid_amount) WHERE id = NEW.dealer_id; 
END//
DELIMITER ;

-- ============================================================
-- Seed Sample Initial Data
-- ============================================================

-- Store settings
INSERT INTO store_settings (id, store_name, phone, address, currency_symbol, receipt_footer)
VALUES (1, 'Super POS Mart', '0300-1234567', 'Shop # 12, Main Commercial Market', 'Rs', 'Thank you for shopping with us! Items can be returned within 3 days with receipt.');

-- Default accounts (admin / admin123, operator / operator123)
INSERT INTO users (id, username, password_hash, full_name, role, status) VALUES 
(1, 'admin', '$2y$10$b50XHR2jq6Uv2v6kvFcF/.BN983ppxiPlnduOIrUu/jgk1PdKAdXe', 'Store Administrator', 'Admin', 'Active'),
(2, 'operator', '$2y$10$G/QdkOuEfmJDz0k/OFtrm.blfAeQXVoaMp8atGa7xuMpxF2K.7nma', 'Cashier Operator', 'Operator', 'Active');

-- Categories
INSERT INTO categories (id, name) VALUES 
(1, 'Beverages'),
(2, 'Grocery & Staples'),
(3, 'Snacks & Confectionery'),
(4, 'Dairy & Bakery');

-- Dealers / Suppliers
INSERT INTO dealers (id, name, phone, company_name, products_supplied, pending_payable_amount) VALUES 
(1, 'Haji Muhammad Aslam', '0300-9876543', 'Aslam Foods Distributors', 'Rice, Flour, Sugar, Cooking Oil', 45000.00),
(2, 'Tariq Mehmood', '0321-4567890', 'Beverage Wholesale Direct', 'Cold Drinks, Juices, Mineral Water', 18500.00),
(3, 'Sheikh Rizwan', '0333-1122334', 'Snacks & Confectionery Mart', 'Biscuits, Chips, Chocolates', 12000.00);

-- Sample Products
INSERT INTO products (id, name, category_id, barcode, manufacturer_price, market_sale_price, dealer_id, stock_quantity, min_alert_limit) VALUES 
(1, 'Coca Cola 1.5 Litre', 1, '890123450001', 170.00, 210.00, 2, 45, 10),
(2, 'Sprite 1.5 Litre', 1, '890123450002', 170.00, 210.00, 2, 38, 10),
(3, 'Basmati Rice Premium 5kg', 2, '890123450003', 1400.00, 1750.00, 1, 20, 5),
(4, 'Dalda Cooking Oil 1 Litre', 2, '890123450004', 510.00, 560.00, 1, 8, 10),
(5, 'Lipton Yellow Label Tea 400g', 2, '890123450005', 680.00, 780.00, 1, 15, 5),
(6, 'Lays Masala Large Pack', 3, '890123450006', 80.00, 100.00, 3, 50, 15),
(7, 'Oreo Biscuits Family Pack', 3, '890123450007', 95.00, 120.00, 3, 5, 10),
(8, 'Olpers Milk 1 Litre Pack', 4, '890123450008', 260.00, 290.00, 2, 28, 10);
