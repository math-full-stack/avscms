-- Trim (cut) support for mass-grabber videos.
-- grabber_sources.cut_in  : seconds to remove from the START of every video
--                           grabbed from that source (e.g. 4s intro ad).
-- grabber_sources.cut_out : seconds to remove from the END of every video
--                           grabbed from that source (e.g. outro).
-- At grab time the values are snapshotted into video.cut (existing AVS column,
-- already wired into conversion as -ss) and video.cut_out (new column).
-- Both blocks are idempotent (safe to re-run manually).

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'grabber_sources' AND column_name = 'cut_in');
SET @sql := IF(@col = 0, 'ALTER TABLE `grabber_sources` ADD COLUMN `cut_in` INT NOT NULL DEFAULT 0 AFTER `watermark_config`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'grabber_sources' AND column_name = 'cut_out');
SET @sql := IF(@col = 0, 'ALTER TABLE `grabber_sources` ADD COLUMN `cut_out` INT NOT NULL DEFAULT 0 AFTER `cut_in`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'video' AND column_name = 'cut_out');
SET @sql := IF(@col = 0, 'ALTER TABLE `video` ADD COLUMN `cut_out` INT NOT NULL DEFAULT 0 AFTER `cut`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;