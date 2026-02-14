-- Add separate conversion factor for level-based stock calculation
ALTER TABLE items
    ADD COLUMN level_conversion DECIMAL(12,4) NOT NULL DEFAULT 1.0000 AFTER unit_conversion;
