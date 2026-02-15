-- --------------------------------------------------------
-- Host:                         srv1321.hstgr.io
-- Server version:               11.8.3-MariaDB-log - MariaDB Server
-- Server OS:                    Linux
-- HeidiSQL Version:             12.11.0.7065
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for u471204330_stockmanager_2
DROP DATABASE IF EXISTS `u471204330_stockmanager_2`;
CREATE DATABASE IF NOT EXISTS `u471204330_stockmanager_2` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;
USE `u471204330_stockmanager_2`;

-- Dumping structure for table u471204330_stockmanager_2.access_log
DROP TABLE IF EXISTS `access_log`;
CREATE TABLE IF NOT EXISTS `access_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `role` varchar(20) DEFAULT NULL,
  `page` varchar(255) DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table u471204330_stockmanager_2.items
DROP TABLE IF EXISTS `items`;
CREATE TABLE IF NOT EXISTS `items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `category` varchar(100) NOT NULL,
  `field_stock` int(11) NOT NULL DEFAULT 0,
  `unit` varchar(20) NOT NULL DEFAULT '',
  `unit_conversion` decimal(12,4) NOT NULL DEFAULT 1.0000,
  `level_conversion` decimal(12,4) NOT NULL DEFAULT 1.0000,
  `calculation_mode` enum('combined','multiplied') NOT NULL DEFAULT 'combined',
  `daily_consumption` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `min_days_coverage` int(11) NOT NULL DEFAULT 1,
  `description` text DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `status` enum('in-stock','low-stock','warning-stock','out-stock') NOT NULL DEFAULT 'in-stock',
  `added_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `has_level` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `added_by` (`added_by`),
  KEY `updated_by` (`updated_by`),
  KEY `idx_items_deleted_at` (`deleted_at`),
  KEY `deleted_by` (`deleted_by`),
  CONSTRAINT `items_ibfk_1` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`),
  CONSTRAINT `items_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `items_ibfk_3` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table u471204330_stockmanager_2.item_categories
DROP TABLE IF EXISTS `item_categories`;
CREATE TABLE IF NOT EXISTS `item_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item_categories_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table u471204330_stockmanager_2.item_stock_history
DROP TABLE IF EXISTS `item_stock_history`;
CREATE TABLE IF NOT EXISTS `item_stock_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `category` varchar(100) NOT NULL,
  `action` varchar(20) NOT NULL,
  `field_stock_old` int(11) DEFAULT NULL,
  `field_stock_new` int(11) DEFAULT NULL,
  `status_old` varchar(20) DEFAULT NULL,
  `status_new` varchar(20) DEFAULT NULL,
  `total_stock_old` decimal(12,4) DEFAULT NULL,
  `total_stock_new` decimal(12,4) DEFAULT NULL,
  `days_coverage_old` decimal(12,4) DEFAULT NULL,
  `days_coverage_new` decimal(12,4) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `unit_conversion` decimal(12,4) DEFAULT NULL,
  `daily_consumption` decimal(12,4) DEFAULT NULL,
  `min_days_coverage` int(11) DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `item_stock_history_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table u471204330_stockmanager_2.products
DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table u471204330_stockmanager_2.realtime_notifications
DROP TABLE IF EXISTS `realtime_notifications`;
CREATE TABLE IF NOT EXISTS `realtime_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `target_role` varchar(32) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `payload_json` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_target_role_id` (`target_role`,`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table u471204330_stockmanager_2.units
DROP TABLE IF EXISTS `units`;
CREATE TABLE IF NOT EXISTS `units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `value` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `value` (`value`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table u471204330_stockmanager_2.users
DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `role` enum('field','office','admin') NOT NULL DEFAULT 'field',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
