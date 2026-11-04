-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 01, 2026 at 12:52 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pos_software`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`) VALUES
(1, 'Beverages'),
(4, 'Dairy & Bakery'),
(8, 'ghee'),
(2, 'Grocery & Staples'),
(6, 'jamm'),
(7, 'normal'),
(9, 'oil'),
(5, 'Shampoo'),
(3, 'Snacks & Confectionery');

-- --------------------------------------------------------

--
-- Table structure for table `dealers`
--

CREATE TABLE `dealers` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `products_supplied` text DEFAULT NULL,
  `pending_payable_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `dealers`
--

INSERT INTO `dealers` (`id`, `name`, `phone`, `company_name`, `products_supplied`, `pending_payable_amount`, `created_at`) VALUES
(1, 'Haji Muhammad Aslam', '0300-9876543', 'Aslam Foods Distributors', 'Rice, Flour, Sugar, Cooking Oil', 39000.00, '2026-10-13 16:37:08'),
(2, 'Tariq Mehmood', '0321-4567890', 'Beverage Wholesale Direct', 'Cold Drinks, Juices, Mineral Water', 0.00, '2026-10-13 16:37:08'),
(3, 'Sheikh Rizwan', '0333-1122334', 'Snacks & Confectionery Mart', 'Biscuits, Chips, Chocolates', 0.00, '2026-10-13 16:37:08'),
(4, 'Kashif', '454654564', 'hilal food', '', 0.00, '2026-10-15 12:33:20');

-- --------------------------------------------------------

--
-- Table structure for table `dealer_payments`
--

CREATE TABLE `dealer_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `dealer_id` int(10) UNSIGNED NOT NULL,
  `paid_amount` decimal(12,2) NOT NULL,
  `payment_mode` enum('Cash','Bank') NOT NULL DEFAULT 'Cash',
  `receipt_number` varchar(40) NOT NULL,
  `date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `dealer_payments`
--

INSERT INTO `dealer_payments` (`id`, `dealer_id`, `paid_amount`, `payment_mode`, `receipt_number`, `date`, `notes`, `created_by`, `created_at`) VALUES
(1, 3, 6000.00, 'Cash', 'DPR-261013-2598', '2026-10-13', 'aj pay kia mene', 1, '2026-10-13 16:48:53'),
(2, 2, 2000.00, 'Cash', 'DPR-261013-1357', '2026-10-13', 'mene oay kia hai', 2, '2026-10-13 16:55:40'),
(3, 2, 2000.00, 'Cash', 'DPR-261013-8727', '2026-10-13', 'mene oay kia hai', 2, '2026-10-13 16:55:54'),
(4, 2, 14500.00, 'Cash', 'DPR-261014-1379', '2026-10-14', '', 1, '2026-10-14 16:28:10'),
(5, 3, 6000.00, 'Cash', 'DPR-261014-5408', '2026-10-14', '', 1, '2026-10-14 16:44:02'),
(6, 4, 5000.00, 'Cash', 'DPR-261015-1417', '2026-10-15', 'sake man le gia', 1, '2026-10-15 12:34:12'),
(7, 4, 5000.00, 'Cash', 'DPR-261015-2046', '2026-10-15', 'sdsadasda', 2, '2026-10-15 12:52:11'),
(8, 4, 8000.00, 'Cash', 'DPR-261031-1564', '2026-10-31', '', 1, '2026-10-31 15:52:26'),
(9, 4, 1000.00, 'Cash', 'DPR-261031-6821', '2026-10-31', '', 3, '2026-10-31 16:05:28'),
(10, 1, 6000.00, 'Cash', 'DPR-261101-2651', '2026-11-01', '', 1, '2026-11-01 04:59:41');

--
-- Triggers `dealer_payments`
--
DELIMITER $$
CREATE TRIGGER `dealer_payment_after_insert` AFTER INSERT ON `dealer_payments` FOR EACH ROW BEGIN 
    UPDATE dealers SET pending_payable_amount = GREATEST(0, pending_payable_amount - NEW.paid_amount) WHERE id = NEW.dealer_id; 
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `category_title` varchar(150) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_mode` enum('Cash','Bank') NOT NULL DEFAULT 'Cash',
  `notes` text DEFAULT NULL,
  `date` date NOT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `category_title`, `amount`, `payment_mode`, `notes`, `date`, `recorded_by`, `created_at`) VALUES
(1, 'roti', 200.00, 'Cash', 'mene rot kjhayi', '2026-10-15', 1, '2026-10-15 12:41:26');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(180) NOT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `barcode` varchar(80) DEFAULT NULL,
  `manufacturer_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `market_sale_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `dealer_id` int(10) UNSIGNED DEFAULT NULL,
  `stock_quantity` decimal(10,3) NOT NULL DEFAULT 0.000,
  `min_alert_limit` decimal(10,3) NOT NULL DEFAULT 5.000,
  `unit` varchar(20) NOT NULL DEFAULT 'piece',
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `category_id`, `barcode`, `manufacturer_price`, `market_sale_price`, `dealer_id`, `stock_quantity`, `min_alert_limit`, `unit`, `expiry_date`, `created_at`) VALUES
(1, 'Coca Cola 1.5 Litre', 1, '890123450001', 170.00, 210.00, 2, 12.000, 10.000, 'piece', NULL, '2026-10-13 16:37:08'),
(2, 'Sprite 1.5 Litre', 1, '890123450002', 170.00, 210.00, 2, 38.000, 10.000, 'piece', NULL, '2026-10-13 16:37:08'),
(3, 'Basmati Rice Premium 5kg', 2, '890123450003', 1400.00, 1750.00, 1, 96.000, 5.000, 'piece', NULL, '2026-10-13 16:37:08'),
(4, 'Dalda Cooking Oil 1 Litre', 2, '890123450004', 510.00, 560.00, 1, 99.000, 10.000, 'piece', NULL, '2026-10-13 16:37:08'),
(5, 'Lipton Yellow Label Tea 400g', 2, '890123450005', 680.00, 780.00, 1, 8.000, 5.000, 'piece', NULL, '2026-10-13 16:37:08'),
(6, 'Lays Masala Large Pack', 3, '890123450006', 80.00, 100.00, 3, 38.000, 15.000, 'piece', NULL, '2026-10-13 16:37:08'),
(7, 'Oreo Biscuits Family Pack', 3, '890123450007', 95.00, 120.00, 3, 994.000, 10.000, 'piece', NULL, '2026-10-13 16:37:08'),
(8, 'Olpers Milk 1 Litre Pack', 4, '890123450008', 260.00, 290.00, 2, 2008.000, 10.000, 'piece', NULL, '2026-10-13 16:37:08'),
(9, 'Shampo life bouy 1 liter', 5, NULL, 230.00, 250.00, NULL, 23.000, 10.000, 'piece', NULL, '2026-10-13 16:48:08'),
(10, 'jammm 1.5 liter', 6, NULL, 600.00, 1000.00, NULL, 24.000, 12.000, 'piece', NULL, '2026-10-15 12:30:10'),
(11, 'Sugar', 7, '324234', 180.00, 200.00, NULL, 19.450, 1.000, 'kg', '2027-10-18', '2026-10-18 05:59:31'),
(12, 'basmati jhklhdfkas', 8, NULL, 1000.00, 1200.00, NULL, 2099.000, 10.000, 'piece', '2027-11-20', '2026-10-19 12:43:41'),
(13, 'oil', 9, NULL, 150.00, 200.00, NULL, 95.000, 10.000, 'piece', NULL, '2026-11-01 04:55:41');

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 1.000,
  `refund_amount` decimal(12,2) NOT NULL,
  `date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `returns`
--

INSERT INTO `returns` (`id`, `sale_id`, `product_id`, `quantity`, `refund_amount`, `date`, `reason`, `created_by`, `created_at`) VALUES
(1, 1, 1, 1.000, 210.00, '2026-10-14', 'wrong product', 2, '2026-10-14 15:24:59'),
(2, 11, 3, 3.000, 5250.00, '2026-10-15', 'kharab thi', 2, '2026-10-15 12:51:17'),
(3, 44, 10, 3.000, 3000.00, '2026-11-01', '', 1, '2026-11-01 05:02:10'),
(4, 46, 10, 1.000, 1000.00, '2026-11-01', '', 1, '2026-11-01 11:05:40'),
(5, 46, 10, 1.000, 1000.00, '2026-11-01', '', 1, '2026-11-01 11:24:34'),
(6, 47, 10, 2.000, 2000.00, '2026-11-01', '', 1, '2026-11-01 11:27:44');

--
-- Triggers `returns`
--
DELIMITER $$
CREATE TRIGGER `return_after_insert` AFTER INSERT ON `returns` FOR EACH ROW BEGIN 
    UPDATE products SET stock_quantity = stock_quantity + NEW.quantity WHERE id = NEW.product_id; 
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_number` varchar(40) NOT NULL,
  `buyer_name` varchar(150) DEFAULT NULL,
  `buyer_phone` varchar(40) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bonus_discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_payable` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `change_returned` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_mode` enum('Cash','Bank','Udhar') NOT NULL DEFAULT 'Cash',
  `status` enum('Completed','Returned') NOT NULL DEFAULT 'Completed',
  `operator_id` int(10) UNSIGNED DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `invoice_number`, `buyer_name`, `buyer_phone`, `total_amount`, `bonus_discount`, `net_payable`, `paid_amount`, `change_returned`, `payment_mode`, `status`, `operator_id`, `notes`, `created_at`) VALUES
(1, 'INV-261013-2470', 'Walk-in Customer', '', 4590.00, 0.00, 4590.00, 5000.00, 410.00, 'Cash', 'Returned', 2, NULL, '2026-10-13 17:10:33'),
(2, 'INV-261013-8982', 'Ali Ahmed', '03230854511', 2160.00, 0.00, 2160.00, 2200.00, 40.00, 'Cash', 'Completed', 2, NULL, '2026-10-13 17:28:12'),
(3, 'INV-261014-4758', 'Walk-in Customer', '', 1740.00, 0.00, 1740.00, 1740.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-14 15:53:31'),
(4, 'INV-261014-6509', 'Walk-in Customer', '', 210.00, 0.00, 210.00, 210.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-14 16:27:49'),
(5, 'INV-261014-9105', 'Walk-in Customer', '', 2520.00, 0.00, 2520.00, 2520.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-14 16:43:30'),
(6, 'INV-261014-2220', 'Ali Ahmed', '032307883276', 1680.00, 0.00, 1680.00, 1680.00, 0.00, 'Udhar', 'Completed', 1, NULL, '2026-10-14 16:45:12'),
(7, 'INV-261014-9373', 'Walk-in Customer', '', 780.00, 0.00, 780.00, 780.00, 0.00, 'Cash', 'Completed', 2, NULL, '2026-10-14 16:54:50'),
(8, 'INV-261014-2812', 'Walk-in Customer', '', 240.00, 0.00, 240.00, 240.00, 0.00, 'Cash', 'Completed', 2, NULL, '2026-10-14 16:59:29'),
(9, 'INV-261015-4740', 'Walk-in Customer', '', 1260.00, 0.00, 1260.00, 1260.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-15 12:22:47'),
(10, 'INV-261015-1916', 'Kashif', '746574654564', 2300.00, 200.00, 2100.00, 2500.00, 400.00, 'Cash', 'Completed', 1, NULL, '2026-10-15 12:27:01'),
(11, 'INV-261015-2215', 'Ali abas', '4545646545665', 8750.00, 0.00, 8750.00, 8750.00, 0.00, 'Udhar', 'Returned', 1, NULL, '2026-10-15 12:39:21'),
(12, 'INV-261015-4512', 'Walk-in Customer', '', 14770.00, 0.00, 14770.00, 14770.00, 0.00, 'Cash', 'Completed', 2, NULL, '2026-10-15 12:54:08'),
(13, 'INV-261018-1538', 'Modal  Bazar Shop', '46548945311', 10000.00, 0.00, 10000.00, 10000.00, 0.00, 'Udhar', 'Completed', 1, NULL, '2026-10-18 05:38:50'),
(14, 'INV-261018-7434', 'Modal  Bazar Shop', '46548945311', 49290.00, 0.00, 49290.00, 49290.00, 0.00, 'Udhar', 'Completed', 2, NULL, '2026-10-18 05:42:31'),
(15, 'INV-261018-2508', 'Walk-in Customer', '', 171.00, 0.00, 171.00, 171.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-18 06:27:07'),
(17, 'INV-261018-6541', 'Walk-in Customer', '', 100.00, 0.00, 100.00, 100.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-18 06:46:26'),
(18, 'INV-261019-6680', 'Walk-in Customer', '', 20.00, 0.00, 20.00, 20.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-19 12:20:00'),
(19, 'INV-261019-7317', 'Walk-in Customer', '', 2620.00, 0.00, 2620.00, 2620.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-19 12:21:41'),
(20, 'INV-261019-6180', 'Ali abbasd', '46548945311', 1570.00, 0.00, 1570.00, 1570.00, 0.00, 'Udhar', 'Completed', 1, NULL, '2026-10-19 12:24:27'),
(21, 'INV-261019-3374', 'tarik shop', '11265354242', 3190.00, 0.00, 3190.00, 3190.00, 0.00, 'Udhar', 'Completed', 1, NULL, '2026-10-19 12:39:53'),
(22, 'INV-261019-6630', 'tarik shop', '11265354242', 7870.00, 0.00, 7870.00, 7870.00, 0.00, 'Udhar', 'Completed', 1, NULL, '2026-10-19 12:41:05'),
(23, 'INV-261020-6201', 'Walk-in Customer', '', 210.00, 0.00, 210.00, 210.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-20 08:08:34'),
(24, 'INV-261020-2001', 'Walk-in Customer', '', 210.00, 0.00, 210.00, 210.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-20 08:08:43'),
(25, 'INV-261020-7246', 'Walk-in Customer', '', 210.00, 0.00, 210.00, 210.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-20 08:10:21'),
(26, 'INV-261020-3858', 'Walk-in Customer', '', 780.00, 0.00, 780.00, 780.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-20 09:13:23'),
(27, 'INV-261020-6067', 'Walk-in Customer', '', 210.00, 0.00, 210.00, 210.00, 0.00, 'Cash', 'Completed', 3, NULL, '2026-10-20 16:32:33'),
(28, 'INV-261020-4829', 'Walk-in Customer', '', 120.00, 0.00, 120.00, 120.00, 0.00, 'Cash', 'Completed', 3, NULL, '2026-10-20 16:33:16'),
(29, 'INV-261020-2038', 'Walk-in Customer', '', 120.00, 0.00, 120.00, 120.00, 0.00, 'Cash', 'Completed', 3, NULL, '2026-10-20 16:34:06'),
(30, 'INV-261020-4947', 'Walk-in Customer', '', 250.00, 0.00, 250.00, 250.00, 0.00, 'Cash', 'Completed', 3, NULL, '2026-10-20 16:40:20'),
(31, 'INV-261020-8284', 'Walk-in Customer', '', 120.00, 0.00, 120.00, 120.00, 0.00, 'Cash', 'Completed', 3, NULL, '2026-10-20 16:45:17'),
(32, 'INV-261020-5771', 'Walk-in Customer', '', 120.00, 0.00, 120.00, 120.00, 0.00, 'Cash', 'Completed', 3, NULL, '2026-10-20 16:57:29'),
(33, 'INV-261030-7094', 'Walk-in Customer', '', 5000.00, 0.00, 5000.00, 5000.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-30 10:00:54'),
(34, 'INV-261031-7790', 'Walk-in Customer', '', 120.00, 0.00, 120.00, 120.00, 0.00, 'Cash', 'Completed', 3, NULL, '2026-10-31 03:00:55'),
(35, 'INV-261031-2614', 'Walk-in Customer', '', 250.00, 0.00, 250.00, 250.00, 0.00, 'Cash', 'Completed', 3, NULL, '2026-10-31 03:06:56'),
(36, 'INV-261031-3067', 'Walk-in Customer', '', 40.00, 0.00, 40.00, 40.00, 0.00, 'Cash', 'Completed', 2, NULL, '2026-10-31 09:51:20'),
(37, 'INV-261031-3555', 'Walk-in Customer', '', 3200.00, 0.00, 3200.00, 3200.00, 0.00, 'Cash', 'Completed', 2, NULL, '2026-10-31 10:19:12'),
(38, 'INV-261031-9276', 'Walk-in Customer', '', 230.00, 0.00, 230.00, 230.00, 0.00, 'Cash', 'Completed', 2, NULL, '2026-10-31 10:20:04'),
(39, 'INV-261031-9453', 'Walk-in Customer', '', 1000.00, 0.00, 1000.00, 1000.00, 0.00, 'Cash', 'Completed', 2, NULL, '2026-10-31 11:44:46'),
(40, 'INV-261031-1188', 'Walk-in Customer', '', 210.00, 0.00, 210.00, 210.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-31 13:03:31'),
(41, 'INV-261031-7513', 'Walk-in Customer', '', 2000.00, 0.00, 2000.00, 2000.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-10-31 13:09:13'),
(42, 'INV-261031-7130', 'new udhar wala', '3424234234', 1560.00, 0.00, 1560.00, 1560.00, 0.00, 'Udhar', 'Completed', 1, NULL, '2026-10-31 13:17:59'),
(43, 'INV-261031-1279', 'Kashif', '03230854511', 2200.00, 0.00, 2200.00, 2200.00, 0.00, 'Udhar', 'Completed', 1, NULL, '2026-10-31 15:46:25'),
(44, 'INV-261101-8253', 'Ali Ahmed', '032307883276', 6000.00, 0.00, 6000.00, 6000.00, 0.00, 'Cash', 'Returned', 1, NULL, '2026-11-01 04:53:13'),
(45, 'INV-261101-3557', 'Sami', '03230854511', 1000.00, 0.00, 1000.00, 1000.00, 0.00, 'Udhar', 'Completed', 1, NULL, '2026-11-01 04:56:28'),
(46, 'INV-261101-8081', 'kashif', '0309-7107977', 7000.00, 0.00, 7000.00, 7000.00, 0.00, 'Udhar', 'Returned', 1, NULL, '2026-11-01 09:26:06'),
(47, 'INV-261101-7332', 'kashif', '0309-7107977', 7000.00, 0.00, 7000.00, 7000.00, 0.00, 'Udhar', 'Returned', 1, NULL, '2026-11-01 11:27:01'),
(48, 'INV-261101-8497', 'Walk-in Customer', '', 7000.00, 0.00, 7000.00, 7000.00, 0.00, 'Cash', 'Completed', 1, NULL, '2026-11-01 11:44:08');

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 1.000,
  `unit` varchar(20) NOT NULL DEFAULT 'piece',
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(12,2) NOT NULL,
  `total_price` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `quantity`, `unit`, `cost_price`, `unit_price`, `total_price`) VALUES
(1, 1, 1, 1.000, 'piece', 170.00, 210.00, 210.00),
(2, 1, 3, 2.000, 'piece', 1400.00, 1750.00, 3500.00),
(3, 1, 6, 1.000, 'piece', 80.00, 100.00, 100.00),
(4, 1, 5, 1.000, 'piece', 680.00, 780.00, 780.00),
(5, 2, 1, 2.000, 'piece', 170.00, 210.00, 420.00),
(6, 2, 4, 2.000, 'piece', 510.00, 560.00, 1120.00),
(7, 2, 7, 1.000, 'piece', 95.00, 120.00, 120.00),
(8, 2, 9, 2.000, 'piece', 230.00, 250.00, 500.00),
(9, 3, 4, 2.000, 'piece', 510.00, 560.00, 1120.00),
(10, 3, 7, 1.000, 'piece', 95.00, 120.00, 120.00),
(11, 3, 9, 2.000, 'piece', 230.00, 250.00, 500.00),
(12, 4, 1, 1.000, 'piece', 170.00, 210.00, 210.00),
(13, 5, 3, 1.000, 'piece', 1400.00, 1750.00, 1750.00),
(14, 5, 1, 1.000, 'piece', 170.00, 210.00, 210.00),
(15, 5, 4, 1.000, 'piece', 510.00, 560.00, 560.00),
(16, 6, 6, 9.000, 'piece', 80.00, 100.00, 900.00),
(17, 6, 5, 1.000, 'piece', 680.00, 780.00, 780.00),
(18, 7, 5, 1.000, 'piece', 680.00, 780.00, 780.00),
(19, 8, 7, 2.000, 'piece', 95.00, 120.00, 240.00),
(20, 9, 1, 6.000, 'piece', 170.00, 210.00, 1260.00),
(21, 10, 4, 2.000, 'piece', 510.00, 560.00, 1120.00),
(22, 10, 6, 1.000, 'piece', 80.00, 100.00, 100.00),
(23, 10, 9, 1.000, 'piece', 230.00, 250.00, 250.00),
(24, 10, 7, 1.000, 'piece', 95.00, 120.00, 120.00),
(25, 10, 8, 1.000, 'piece', 260.00, 290.00, 290.00),
(26, 10, 1, 2.000, 'piece', 170.00, 210.00, 420.00),
(27, 11, 3, 5.000, 'piece', 1400.00, 1750.00, 8750.00),
(28, 12, 1, 12.000, 'piece', 170.00, 210.00, 2520.00),
(29, 12, 3, 7.000, 'piece', 1400.00, 1750.00, 12250.00),
(30, 13, 10, 10.000, 'piece', 600.00, 1000.00, 10000.00),
(31, 14, 10, 34.000, 'piece', 600.00, 1000.00, 34000.00),
(32, 14, 6, 1.000, 'piece', 80.00, 100.00, 100.00),
(33, 14, 3, 8.000, 'piece', 1400.00, 1750.00, 14000.00),
(34, 14, 1, 3.000, 'piece', 170.00, 210.00, 630.00),
(35, 14, 4, 1.000, 'piece', 510.00, 560.00, 560.00),
(36, 15, 1, 1.000, 'piece', 170.00, 21.00, 21.00),
(37, 15, 11, 0.750, 'kg', 180.00, 200.00, 150.00),
(39, 17, 11, 0.500, 'kg', 180.00, 200.00, 100.00),
(40, 18, 11, 0.100, 'kg', 180.00, 200.00, 20.00),
(41, 19, 11, 2.000, 'kg', 180.00, 200.00, 400.00),
(42, 19, 5, 3.000, 'piece', 680.00, 740.00, 2220.00),
(43, 20, 8, 5.000, 'piece', 260.00, 290.00, 1450.00),
(44, 20, 7, 1.000, 'piece', 95.00, 120.00, 120.00),
(45, 21, 8, 11.000, 'piece', 260.00, 290.00, 3190.00),
(46, 22, 8, 3.000, 'piece', 260.00, 290.00, 870.00),
(47, 22, 10, 7.000, 'piece', 600.00, 1000.00, 7000.00),
(48, 23, 1, 1.000, 'piece', 170.00, 210.00, 210.00),
(49, 24, 1, 1.000, 'piece', 170.00, 210.00, 210.00),
(50, 25, 1, 1.000, 'piece', 170.00, 210.00, 210.00),
(51, 26, 5, 1.000, 'piece', 680.00, 780.00, 780.00),
(52, 27, 1, 1.000, 'piece', 170.00, 210.00, 210.00),
(53, 28, 7, 1.000, 'piece', 95.00, 120.00, 120.00),
(54, 29, 7, 1.000, 'piece', 95.00, 120.00, 120.00),
(55, 30, 9, 1.000, 'piece', 230.00, 250.00, 250.00),
(56, 31, 7, 1.000, 'piece', 95.00, 120.00, 120.00),
(57, 32, 7, 1.000, 'piece', 95.00, 120.00, 120.00),
(58, 33, 10, 5.000, 'piece', 600.00, 1000.00, 5000.00),
(59, 34, 7, 1.000, 'piece', 95.00, 120.00, 120.00),
(60, 35, 9, 1.000, 'piece', 230.00, 250.00, 250.00),
(61, 36, 11, 0.200, 'kg', 180.00, 200.00, 40.00),
(62, 37, 10, 3.000, 'piece', 600.00, 1000.00, 3000.00),
(63, 37, 11, 1.000, 'kg', 180.00, 200.00, 200.00),
(64, 38, 11, 1.000, 'kg', 180.00, 230.00, 230.00),
(65, 39, 10, 1.000, 'piece', 600.00, 1000.00, 1000.00),
(66, 40, 1, 1.000, 'piece', 170.00, 210.00, 210.00),
(67, 41, 10, 2.000, 'piece', 600.00, 1000.00, 2000.00),
(68, 42, 4, 1.000, 'piece', 510.00, 560.00, 560.00),
(69, 42, 10, 1.000, 'piece', 600.00, 1000.00, 1000.00),
(70, 43, 11, 5.000, 'kg', 180.00, 200.00, 1000.00),
(71, 43, 12, 1.000, 'piece', 1000.00, 1200.00, 1200.00),
(72, 44, 10, 6.000, 'piece', 600.00, 1000.00, 6000.00),
(73, 45, 13, 5.000, 'piece', 150.00, 200.00, 1000.00),
(74, 46, 10, 7.000, 'piece', 600.00, 1000.00, 7000.00),
(75, 47, 10, 7.000, 'piece', 600.00, 1000.00, 7000.00),
(76, 48, 3, 4.000, 'piece', 1400.00, 1750.00, 7000.00);

--
-- Triggers `sale_items`
--
DELIMITER $$
CREATE TRIGGER `sale_item_after_insert` AFTER INSERT ON `sale_items` FOR EACH ROW BEGIN 
    UPDATE products SET stock_quantity = stock_quantity - NEW.quantity WHERE id = NEW.product_id; 
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `software_locks`
--

CREATE TABLE `software_locks` (
  `id` int(10) UNSIGNED NOT NULL,
  `lock_date` date NOT NULL,
  `unlock_password` varchar(255) NOT NULL,
  `lock_message` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_unlocked` tinyint(1) NOT NULL DEFAULT 0,
  `unlocked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `software_locks`
--

INSERT INTO `software_locks` (`id`, `lock_date`, `unlock_password`, `lock_message`, `is_active`, `is_unlocked`, `unlocked_at`, `created_at`, `updated_at`) VALUES
(7, '2026-11-01', 'key1', 'ye lock hogia hai', 0, 1, NULL, '2026-11-01 02:57:06', '2026-11-01 02:59:37');

-- --------------------------------------------------------

--
-- Table structure for table `store_settings`
--

CREATE TABLE `store_settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `store_name` varchar(150) NOT NULL DEFAULT 'Super POS Mart',
  `phone` varchar(50) NOT NULL DEFAULT '0300-1234567',
  `address` varchar(255) NOT NULL DEFAULT 'Shop # 12, Main Commercial Market',
  `currency_symbol` varchar(10) NOT NULL DEFAULT 'Rs',
  `receipt_footer` varchar(255) NOT NULL DEFAULT 'Thank you for shopping with us! Items can be returned within 3 days with receipt.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `store_settings`
--

INSERT INTO `store_settings` (`id`, `store_name`, `phone`, `address`, `currency_symbol`, `receipt_footer`) VALUES
(1, 'Al Mehbob Traders', '0309-7107977', 'Janubi Bazar Jauharabad', 'Rs', 'اپنے کاروبار کے لیے 2000 روپے ماہانہ پر سافٹ ویئر حاصل کرنے کے لیے رابطہ کریں\r\nKJ Software House Khushab\r\nPhone Number : 03180711280');

-- --------------------------------------------------------

--
-- Table structure for table `udhar_customers`
--

CREATE TABLE `udhar_customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `credit_limit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `udhar_customers`
--

INSERT INTO `udhar_customers` (`id`, `name`, `phone`, `address`, `credit_limit`, `opening_balance`, `notes`, `created_at`) VALUES
(2, 'Modal  Bazar Shop', '46548945311', 'Modal Bazar  khushab', 55000.00, 0.00, 'bas 50000 se ziada ni dena isko credit', '2026-10-18 05:37:35'),
(3, 'Main bazar khushab', '45646445', 'malkaa wali gli', 45000.00, 0.00, 'isko 45K k uper ni dene paise', '2026-10-19 12:37:20'),
(4, 'tarik shop', '11265354242', 'hsdkjahfkjash', 6000.00, 0.00, 'hfsajkfhdkjsahfkja', '2026-10-19 12:39:02'),
(6, 'kk', '3242', 'Khushab,Punjab,Pakistan', 5000.00, 550.00, 'no', '2026-10-31 06:55:09'),
(9, 's', '1234213123', 'Khushab,Punjab,Pakistan', 5000.00, 400.00, 'x', '2026-10-31 07:11:18'),
(11, 'zx', '46548945311', 'Modal Bazar  khushab', 5000.00, 2550.00, 'dsa', '2026-10-31 15:38:29'),
(12, 'rtre', '46548945311', 'Modal Bazar  khushab', 5000.00, 5000.00, 'sda', '2026-10-31 15:38:53'),
(15, 'kashif', '0309-7107977', 'Khushab,', 50000.00, 3000.00, 'just give them', '2026-11-01 09:25:43');

-- --------------------------------------------------------

--
-- Table structure for table `udhar_ledger`
--

CREATE TABLE `udhar_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) UNSIGNED DEFAULT NULL,
  `customer_id` int(10) UNSIGNED DEFAULT NULL,
  `buyer_name` varchar(150) DEFAULT NULL,
  `buyer_phone` varchar(40) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(12,2) NOT NULL,
  `status` enum('Pending','Cleared') NOT NULL DEFAULT 'Pending',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `udhar_ledger`
--

INSERT INTO `udhar_ledger` (`id`, `sale_id`, `customer_id`, `buyer_name`, `buyer_phone`, `total_amount`, `paid_amount`, `balance_amount`, `status`, `updated_at`) VALUES
(1, 6, NULL, 'Ali Ahmed', '032307883276', 1680.00, 1680.00, 0.00, 'Cleared', '2026-10-14 16:45:38'),
(2, 11, NULL, 'Ali abas', '4545646545665', 8750.00, 8750.00, 0.00, 'Cleared', '2026-10-31 15:24:59'),
(3, 13, 2, 'Modal  Bazar Shop', '46548945311', 10000.00, 10000.00, 0.00, 'Cleared', '2026-10-18 05:39:55'),
(4, 14, 2, 'Modal  Bazar Shop', '46548945311', 49290.00, 49290.00, 0.00, 'Cleared', '2026-10-30 09:56:45'),
(5, 20, NULL, 'Ali abbasd', '46548945311', 1570.00, 1570.00, 0.00, 'Cleared', '2026-10-31 15:37:54'),
(6, 21, 4, 'tarik shop', '11265354242', 3190.00, 6380.00, 0.00, 'Cleared', '2026-10-31 15:24:08'),
(7, 22, 4, 'tarik shop', '11265354242', 7870.00, 7870.00, 0.00, 'Cleared', '2026-10-31 15:23:00'),
(8, NULL, 9, 's', '1234213123', 400.00, 800.00, 0.00, 'Cleared', '2026-10-31 15:37:28'),
(9, NULL, 10, 'kashif', '0309-7107977', 600.00, 600.00, 0.00, 'Cleared', '2026-10-31 15:35:47'),
(10, 42, NULL, 'new udhar wala', '3424234234', 1560.00, 1560.00, 0.00, 'Cleared', '2026-10-31 13:18:42'),
(11, NULL, 11, 'zx', '46548945311', 2500.00, 2500.00, 0.00, 'Cleared', '2026-10-31 15:44:57'),
(12, NULL, 12, 'rtre', '46548945311', 2000.00, 2000.00, 0.00, 'Cleared', '2026-10-31 15:39:42'),
(13, NULL, 13, 'kashif', '1234213123', 2500.00, 2500.00, 0.00, 'Cleared', '2026-10-31 15:45:11'),
(14, 43, 5, 'Kashif', '03230854511', 2200.00, 2200.00, 0.00, 'Cleared', '2026-10-31 15:53:49'),
(15, NULL, 14, 'kashif', '1234213123', 5000.00, 5000.00, 0.00, 'Cleared', '2026-10-31 16:01:00'),
(16, 45, NULL, 'Sami', '03230854511', 1000.00, 500.00, 500.00, 'Pending', '2026-11-01 04:57:09'),
(17, NULL, 15, 'kashif', '0309-7107977', 3000.00, 0.00, 3000.00, 'Pending', '2026-11-01 11:28:19'),
(18, 46, 15, 'kashif', '0309-7107977', 6000.00, 6000.00, 0.00, 'Cleared', '2026-11-01 11:24:50'),
(19, 47, 15, 'kashif', '0309-7107977', 5000.00, 0.00, 5000.00, 'Pending', '2026-11-01 11:27:44');

-- --------------------------------------------------------

--
-- Table structure for table `udhar_payments`
--

CREATE TABLE `udhar_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `udhar_id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount_paid` decimal(12,2) NOT NULL,
  `payment_mode` enum('Cash','Bank') NOT NULL DEFAULT 'Cash',
  `receipt_number` varchar(40) NOT NULL,
  `date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `udhar_payments`
--

INSERT INTO `udhar_payments` (`id`, `udhar_id`, `sale_id`, `amount_paid`, `payment_mode`, `receipt_number`, `date`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, 6, 1680.00, 'Cash', 'UPR-261014-3941', '2026-10-14', '', 1, '2026-10-14 16:45:38'),
(2, 2, 11, 5000.00, 'Cash', 'UPR-261015-9147', '2026-10-15', '', 1, '2026-10-15 12:40:39'),
(3, 3, 13, 10000.00, 'Cash', 'UPR-261018-1340', '2026-10-18', '10000 mil gia', 1, '2026-10-18 05:39:55'),
(4, 4, 14, 49290.00, 'Cash', 'UPR-261030-9159', '2026-10-30', '', 2, '2026-10-30 09:56:45'),
(5, 10, 42, 1560.00, 'Cash', 'UPR-261031-5824', '2026-10-31', '', 1, '2026-10-31 13:18:42'),
(6, 7, 22, 7870.00, 'Cash', 'UPR-261031-7702', '2026-10-31', '', 1, '2026-10-31 15:23:00'),
(7, 6, 21, 3190.00, 'Cash', 'UPR-261031-7412', '2026-10-31', '', 1, '2026-10-31 15:23:28'),
(8, 6, 21, 3190.00, 'Cash', 'UPR-261031-3964', '2026-10-31', '', 1, '2026-10-31 15:24:08'),
(9, 2, 11, 3750.00, 'Cash', 'UPR-261031-7468', '2026-10-31', '', 1, '2026-10-31 15:24:59'),
(10, 9, NULL, 600.00, 'Cash', 'UPR-261031-4881', '2026-10-31', '', 1, '2026-10-31 15:35:47'),
(11, 8, NULL, 400.00, 'Cash', 'UPR-261031-5448', '2026-10-31', '', 1, '2026-10-31 15:37:10'),
(12, 8, NULL, 400.00, 'Cash', 'UPR-261031-8398', '2026-10-31', '', 1, '2026-10-31 15:37:28'),
(13, 5, 20, 1570.00, 'Cash', 'UPR-261031-2698', '2026-10-31', '', 1, '2026-10-31 15:37:54'),
(14, 12, NULL, 2000.00, 'Cash', 'UPR-261031-7440', '2026-10-31', '', 1, '2026-10-31 15:39:42'),
(15, 11, NULL, 2500.00, 'Cash', 'UPR-261031-8992', '2026-10-31', '', 1, '2026-10-31 15:44:57'),
(16, 13, NULL, 2500.00, 'Cash', 'UPR-261031-6247', '2026-10-31', '', 1, '2026-10-31 15:45:11'),
(17, 14, 43, 2200.00, 'Cash', 'UPR-261031-2043', '2026-10-31', '', 1, '2026-10-31 15:53:49'),
(18, 15, NULL, 5000.00, 'Cash', 'UPR-261031-7975', '2026-10-31', '', 2, '2026-10-31 16:01:00'),
(19, 16, 45, 500.00, 'Cash', 'UPR-261101-7877', '2026-11-01', '', 1, '2026-11-01 04:57:09'),
(20, 18, 46, 6000.00, 'Cash', 'UPR-261101-5252', '2026-11-01', '', 1, '2026-11-01 11:24:50');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `plain_password` varchar(255) DEFAULT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `role` varchar(30) NOT NULL DEFAULT 'Operator',
  `status` enum('Active','Frozen') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `plain_password`, `full_name`, `role`, `status`, `created_at`) VALUES
(1, 'admin', '$2y$10$b50XHR2jq6Uv2v6kvFcF/.BN983ppxiPlnduOIrUu/jgk1PdKAdXe', 'admin123', 'Store Administrator', 'Admin', 'Active', '2026-10-13 16:37:08'),
(2, 'operator', '$2y$10$G/QdkOuEfmJDz0k/OFtrm.blfAeQXVoaMp8atGa7xuMpxF2K.7nma', 'operator123', 'Cashier Operator', 'Operator', 'Active', '2026-10-13 16:37:08'),
(3, 'master', '$2y$10$vsqTanL5fFyYzZjkwKIL4ubGP2czYqKyGNjYDG3rcupLTKhB5liv2', 'master123', 'Master User', 'Master', 'Active', '2026-10-20 08:24:33');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `dealers`
--
ALTER TABLE `dealers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `dealer_payments`
--
ALTER TABLE `dealer_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `dealer_id` (`dealer_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `dealer_id` (`dealer_id`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `operator_id` (`operator_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `software_locks`
--
ALTER TABLE `software_locks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lock_date` (`lock_date`);

--
-- Indexes for table `store_settings`
--
ALTER TABLE `store_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `udhar_customers`
--
ALTER TABLE `udhar_customers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `udhar_ledger`
--
ALTER TABLE `udhar_ledger`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sale_id` (`sale_id`);

--
-- Indexes for table `udhar_payments`
--
ALTER TABLE `udhar_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `udhar_id` (`udhar_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `dealers`
--
ALTER TABLE `dealers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `dealer_payments`
--
ALTER TABLE `dealer_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `software_locks`
--
ALTER TABLE `software_locks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `udhar_customers`
--
ALTER TABLE `udhar_customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `udhar_ledger`
--
ALTER TABLE `udhar_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `udhar_payments`
--
ALTER TABLE `udhar_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `dealer_payments`
--
ALTER TABLE `dealer_payments`
  ADD CONSTRAINT `dealer_payments_ibfk_1` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`),
  ADD CONSTRAINT `dealer_payments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_ibfk_2` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `returns`
--
ALTER TABLE `returns`
  ADD CONSTRAINT `returns_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  ADD CONSTRAINT `returns_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `returns_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `udhar_ledger`
--
ALTER TABLE `udhar_ledger`
  ADD CONSTRAINT `udhar_ledger_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `udhar_payments`
--
ALTER TABLE `udhar_payments`
  ADD CONSTRAINT `udhar_payments_ibfk_1` FOREIGN KEY (`udhar_id`) REFERENCES `udhar_ledger` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `udhar_payments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
