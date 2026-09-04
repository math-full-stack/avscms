-- Migration: Secure streaming support
--   * servers.gcs_signed_ttl - TTL (seconds) of V4 signed URLs for GCS servers
--   * player.engine          - player engine per profile: 'videojs' (default) or 'mediabunny'
-- Idempotent: checks column existence before ALTER (same pattern as 20260801000004)

SET @dbname = DATABASE();

-- servers.gcs_signed_ttl column
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'servers' AND COLUMN_NAME = 'gcs_signed_ttl';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `servers` ADD COLUMN `gcs_signed_ttl` int(11) NOT NULL DEFAULT 21600 AFTER `gcs_bucket`',
    'SELECT "Column gcs_signed_ttl already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- player.engine column
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'player' AND COLUMN_NAME = 'engine';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `player` ADD COLUMN `engine` enum(''videojs'',''mediabunny'') NOT NULL DEFAULT ''videojs'' AFTER `profile`',
    'SELECT "Column engine already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;