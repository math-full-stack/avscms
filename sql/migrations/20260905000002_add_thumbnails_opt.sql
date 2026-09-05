-- Migration: multiple covers per video (rotation in cards)
--
-- Adds `thumbnails_opt` to the video table: a comma-separated list of frame
-- indices (1..thumbs) chosen in the admin "Advanced Thumbnails" modal.
-- These frames rotate as the video cover across grid/listings, while the
-- existing `thumb` column stays as the main/fallback cover.
--
-- Empty value (default) = keep legacy behavior (only `thumb` is used).

ALTER TABLE `video` ADD COLUMN `thumbnails_opt` VARCHAR(100) NOT NULL DEFAULT '' AFTER `thumb`;