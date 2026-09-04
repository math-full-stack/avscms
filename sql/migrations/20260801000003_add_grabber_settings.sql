-- Migration: grabber_settings table
--
-- SCHEMA-ONLY migration. Default rows for grabber_settings live only in
-- sql/install_mass_grabber.sql (fresh/manual install). Deploys run these
-- migrations against the production DB automatically, so no data may be
-- inserted or modified here.
-- Idempotent: CREATE TABLE IF NOT EXISTS

CREATE TABLE IF NOT EXISTS `grabber_settings` (
  `setting_key` varchar(100) NOT NULL DEFAULT '',
  `setting_value` text NOT NULL DEFAULT '',
  `updated_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`setting_key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
