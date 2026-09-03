-- ============================================================
-- Mass Video Grabber - Uninstall SQL for AVS 8.2
-- WARNING: This will remove all Mass Grabber data.
-- Videos already imported into AVS will NOT be affected.
-- ============================================================

DROP TABLE IF EXISTS `grabber_tag_mappings`;
DROP TABLE IF EXISTS `grabber_logs`;
DROP TABLE IF EXISTS `grabber_runs`;
DROP TABLE IF EXISTS `grabber_jobs`;
DROP TABLE IF EXISTS `grabber_discovered_videos`;
DROP TABLE IF EXISTS `grabber_sources`;
