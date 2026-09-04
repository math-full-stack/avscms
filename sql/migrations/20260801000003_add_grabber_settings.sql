-- Migration: Add grabber_settings table (redundant with install_mass_grabber but safe)
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE

CREATE TABLE IF NOT EXISTS `grabber_settings` (
  `setting_key` varchar(100) NOT NULL DEFAULT '',
  `setting_value` text NOT NULL DEFAULT '',
  `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`setting_key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT IGNORE INTO `grabber_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('realtime_enabled', '0', UNIX_TIMESTAMP());
