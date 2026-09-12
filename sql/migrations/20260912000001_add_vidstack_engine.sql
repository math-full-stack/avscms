-- Migration: Vidstack player engine
--   * player.engine - add 'vidstack' to the engine enum (used by VidstackPlayer web components)
-- Idempotent: only modifies the enum when 'vidstack' is missing (same pattern as 20260801000005)

SET @dbname = DATABASE();

SELECT COLUMN_TYPE INTO @engine_type
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'player' AND COLUMN_NAME = 'engine';

SET @sql = IF(@engine_type IS NOT NULL AND @engine_type NOT LIKE '%vidstack%',
    'ALTER TABLE `player` MODIFY COLUMN `engine` enum(''videojs'',''mediabunny'',''vidstack'') NOT NULL DEFAULT ''videojs''',
    'SELECT "engine already supports vidstack" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;