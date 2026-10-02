-- Migration: multiple covers per video (rotation in cards)
--
-- Adds `thumbnails_opt` to the video table: a comma-separated list of frame
-- indices (1..thumbs) chosen in the admin "Advanced Thumbnails" modal.
-- These frames rotate as the video cover across grid/listings, while the
-- existing `thumb` column stays as the main/fallback cover.
--
-- Empty value (default) = keep legacy behavior (only `thumb` is used).

-- Idempotente: a avs.sql base ja cria a coluna, entao rodar de novo nao pode
-- falhar com "Duplicate column name" (abortava o import inteiro de um banco novo).

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'video' AND COLUMN_NAME = 'thumbnails_opt'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `video` ADD COLUMN `thumbnails_opt` VARCHAR(100) NOT NULL DEFAULT '''' AFTER `thumb`',
  'SELECT "Column thumbnails_opt already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;