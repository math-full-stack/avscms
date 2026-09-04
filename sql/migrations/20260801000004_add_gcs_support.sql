-- Migration: Add GCS (Google Cloud Storage) support to servers table
-- Idempotent: checks column existence before ALTER

SET @dbname = DATABASE();

-- server_type column
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 'server_type';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `servers` ADD COLUMN `server_type` enum(''ftp'',''gcs'') NOT NULL DEFAULT ''ftp'' AFTER `status`',
    'SELECT "Column server_type already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- gcs_key_path column
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 'gcs_key_path';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `servers` ADD COLUMN `gcs_key_path` varchar(500) NOT NULL DEFAULT '''' AFTER `server_type`',
    'SELECT "Column gcs_key_path already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- gcs_bucket column
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 'gcs_bucket';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `servers` ADD COLUMN `gcs_bucket` varchar(255) NOT NULL DEFAULT '''' AFTER `gcs_key_path`',
    'SELECT "Column gcs_bucket already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
