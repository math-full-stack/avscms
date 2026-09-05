-- Migration: Player start-muted + quick controls card
--   * player.start_muted   - start video muted (1/0)
--   * player.quick_controls - show the bottom-right play/speed/+5s card (1/0)
-- Idempotent: checks column existence before ALTER (same pattern as other migrations)

SET @dbname = DATABASE();

-- player.start_muted column
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'player' AND COLUMN_NAME = 'start_muted';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `player` ADD COLUMN `start_muted` enum(''0'',''1'') NOT NULL DEFAULT ''1'' AFTER `autoplay`',
    'SELECT "Column start_muted already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- player.quick_controls column
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'player' AND COLUMN_NAME = 'quick_controls';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `player` ADD COLUMN `quick_controls` enum(''0'',''1'') NOT NULL DEFAULT ''1'' AFTER `start_muted`',
    'SELECT "Column quick_controls already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
