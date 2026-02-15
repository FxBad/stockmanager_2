-- Migration: add FULLTEXT index for robust item name search
-- Safe to re-run (idempotent)

SET @schema_name = DATABASE();

SET @idx_exists = (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = @schema_name
      AND table_name = 'items'
      AND index_name = 'idx_items_name_fulltext'
);

SET @sql_stmt = IF(
    @idx_exists = 0,
    'ALTER TABLE `items` ADD FULLTEXT INDEX `idx_items_name_fulltext` (`name`)',
    'SELECT "idx_items_name_fulltext already exists"'
);

PREPARE stmt FROM @sql_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
