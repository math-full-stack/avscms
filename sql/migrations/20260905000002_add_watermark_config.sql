-- Watermark support (video conversion).
-- grabber_sources.watermark_config : per-source profile (JSON) edited in the
--   Mass Video Grabber -> Sources modal. Applied to every format of every
--   video grabbed from that source.
-- video.watermark_cfg : per-video snapshot taken at grab time, so later source
--   edits never re-watermark already-converted videos (no backfill).
-- Both blocks are idempotent (safe to re-run manually).

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'grabber_sources' AND column_name = 'watermark_config');
SET @sql := IF(@col = 0, 'ALTER TABLE `grabber_sources` ADD COLUMN `watermark_config` TEXT NOT NULL DEFAULT '''' AFTER `delay_seconds`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'video' AND column_name = 'watermark_cfg');
SET @sql := IF(@col = 0, 'ALTER TABLE `video` ADD COLUMN `watermark_cfg` TEXT NOT NULL DEFAULT '''' AFTER `source_url`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;