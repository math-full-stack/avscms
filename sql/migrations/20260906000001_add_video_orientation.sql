-- Video orientation (portrait/landscape) saved at conversion time.
-- video.orientation : 'portrait'  = retrato/vertical (w<h no display, ex. 416x720
--                                  ou anamórfico 720x720 com DAR 15:26)
--                     'landscape' = paisagem/horizontal (padrão)
--                     'square'    = 1:1 real
-- postConversion() (function_conversion*.php) grava a cada conversão; o
-- backfill abaixo classifica o acervo existente a partir de
-- width_sd/height_sd/aspect_sd (mesmas colunas que o player usa).
-- Blocos idempotentes (safe to re-run manualmente).

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'video' AND column_name = 'orientation');
SET @sql := IF(@col = 0, 'ALTER TABLE `video` ADD COLUMN `orientation` ENUM(''landscape'',''portrait'',''square'') NOT NULL DEFAULT ''landscape'' AFTER `height_sd`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill do acervo existente (recomputa os mesmos valores; re-run é no-op).
UPDATE `video` SET `orientation` = CASE
    WHEN `width_sd` < `height_sd` THEN 'portrait'
    WHEN `width_sd` > `height_sd` THEN 'landscape'
    WHEN (SUBSTRING_INDEX(`aspect_sd`, ':', 1) + 0) < (SUBSTRING_INDEX(`aspect_sd`, ':', -1) + 0) THEN 'portrait'
    WHEN (SUBSTRING_INDEX(`aspect_sd`, ':', 1) + 0) > (SUBSTRING_INDEX(`aspect_sd`, ':', -1) + 0) THEN 'landscape'
    WHEN `width_sd` > 0 THEN 'square'
    ELSE 'landscape'
END;
