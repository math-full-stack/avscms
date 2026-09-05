-- Migration: High-quality H.264 encoding ladder
--
-- Moves every ladder step to a higher-quality CRF / preset combination so
-- converted files (non-copy-only sources) keep more detail and less
-- banding, matching the higher thumbnail / preview quality settings.
--
-- The copy-only fast path is unaffected: files already in H.264/MP4 are
-- remuxed, never re-encoded.
--
-- Runs once per environment (tracked in db_migrations); UPDATEs by label are
-- safe even if an install customized its ladder.

UPDATE `encoding` SET `crf` = 22, `preset` = 'medium' WHERE `label` = '240p';
UPDATE `encoding` SET `crf` = 21, `preset` = 'medium' WHERE `label` = '360p';
UPDATE `encoding` SET `crf` = 20, `preset` = 'medium' WHERE `label` = '480p';
UPDATE `encoding` SET `crf` = 19, `preset` = 'medium' WHERE `label` = '720p';
UPDATE `encoding` SET `crf` = 18, `preset` = 'medium' WHERE `label` = '1080p';
