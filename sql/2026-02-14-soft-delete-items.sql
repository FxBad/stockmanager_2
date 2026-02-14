-- StockManager soft-delete migration for items
-- Date: 2026-02-14
-- Safe to run once on environments that may not yet have soft-delete columns.

USE `stockmanager_test`;

START TRANSACTION;

ALTER TABLE `items`
  ADD COLUMN IF NOT EXISTS `deleted_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `deleted_by` int(11) DEFAULT NULL;

-- Ensure index exists for active-item filtering
SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'items'
    AND INDEX_NAME = 'idx_items_deleted_at'
);
SET @sql_idx := IF(@idx_exists = 0,
  'ALTER TABLE `items` ADD INDEX `idx_items_deleted_at` (`deleted_at`)',
  'SELECT 1'
);
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;

-- Add FK for deleted_by -> users.id if missing
SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'items'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'items_ibfk_3'
);
SET @sql_fk := IF(@fk_exists = 0,
  'ALTER TABLE `items` ADD CONSTRAINT `items_ibfk_3` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt_fk FROM @sql_fk;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

COMMIT;

SHOW COLUMNS FROM `items`;
