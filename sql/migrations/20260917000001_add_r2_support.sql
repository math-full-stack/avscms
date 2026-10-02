-- Migration: Add Cloudflare R2 (S3-compatible) support to servers table
--
-- Adiciona a fonte 'r2' ao enum server_type e as colunas de credenciais S3.
-- Idempotente: checa a existência antes de cada ALTER (o enum só é trocado
-- quando ainda não inclui 'r2').

SET @dbname = DATABASE();

-- server_type enum: ftp | gcs | r2
SELECT COUNT(*) INTO @enum_has_r2 FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 'server_type'
  AND COLUMN_TYPE LIKE '%r2%';

SET @sql = IF(@enum_has_r2 = 0,
    'ALTER TABLE `servers` MODIFY COLUMN `server_type` enum(''ftp'',''gcs'',''r2'') NOT NULL DEFAULT ''ftp''',
    'SELECT "Column server_type already accepts r2" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- s3_endpoint
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 's3_endpoint';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `servers` ADD COLUMN `s3_endpoint` varchar(255) NOT NULL DEFAULT '''' AFTER `gcs_signed_ttl`',
    'SELECT "Column s3_endpoint already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- s3_bucket
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 's3_bucket';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `servers` ADD COLUMN `s3_bucket` varchar(255) NOT NULL DEFAULT '''' AFTER `s3_endpoint`',
    'SELECT "Column s3_bucket already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- s3_access_key
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 's3_access_key';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `servers` ADD COLUMN `s3_access_key` varchar(255) NOT NULL DEFAULT '''' AFTER `s3_bucket`',
    'SELECT "Column s3_access_key already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- s3_secret_key
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 's3_secret_key';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `servers` ADD COLUMN `s3_secret_key` varchar(255) NOT NULL DEFAULT '''' AFTER `s3_access_key`',
    'SELECT "Column s3_secret_key already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- s3_region
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 's3_region';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `servers` ADD COLUMN `s3_region` varchar(64) NOT NULL DEFAULT ''auto'' AFTER `s3_secret_key`',
    'SELECT "Column s3_region already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
