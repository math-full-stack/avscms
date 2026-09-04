-- Migration: Add last_update column for stuck video cleanup
-- Fully idempotent: checks existence before ALTER and ADD KEY

SET @dbname = DATABASE();

-- last_update column
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'video' AND COLUMN_NAME = 'last_update';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `video` ADD COLUMN `last_update` INT(11) NOT NULL DEFAULT 0 AFTER `active`',
    'SELECT "Column last_update already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- active_lastupdate index
SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'video' AND INDEX_NAME = 'active_lastupdate';

SET @sql2 = IF(@idx_exists = 0,
    'ALTER TABLE `video` ADD KEY `active_lastupdate` (`active`, `last_update`)',
    'SELECT "Index active_lastupdate already exists" AS info'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
