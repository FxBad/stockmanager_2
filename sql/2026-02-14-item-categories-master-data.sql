-- StockManager migration: introduce item_categories master data
-- Date: 2026-02-14
-- Purpose: normalize category management and remove direct dependency on DISTINCT items.category

USE `stockmanager_test`;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `item_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill existing categories from items
INSERT INTO `item_categories` (`name`, `display_order`, `is_active`)
SELECT src.name, src.display_order, 1
FROM (
  SELECT DISTINCT TRIM(`category`) AS name,
         ROW_NUMBER() OVER (ORDER BY TRIM(`category`)) * 10 AS display_order
  FROM `items`
  WHERE `category` IS NOT NULL AND TRIM(`category`) <> ''
) src
LEFT JOIN `item_categories` c ON c.`name` = src.`name`
WHERE c.`id` IS NULL;

COMMIT;

SELECT id, name, display_order, is_active
FROM item_categories
ORDER BY display_order ASC, name ASC;
