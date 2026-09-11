USE `avs`;

-- ============================================================
-- Mass Video Grabber - Install SQL for AVS 8.2
-- Prefix: grabber_
-- Engine: InnoDB (Updated to InnoDB as MyISAM is disabled in the server configuration)
-- ============================================================

-- -----------------------------------------------------------
-- 1. grabber_sources - Source configuration
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `avs`.`grabber_sources` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `slug` varchar(255) NOT NULL DEFAULT '',
  `domain` varchar(255) NOT NULL DEFAULT '',
  `provider` varchar(100) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `automatic_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `discovery_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `discovery_url` text NOT NULL,
  `category_id` int unsigned NOT NULL DEFAULT 0,
  `quality` varchar(20) NOT NULL DEFAULT 'best',
  `max_per_run` int unsigned NOT NULL DEFAULT 5,
  `max_pages` int unsigned NOT NULL DEFAULT 3,
  `schedule_type` varchar(20) NOT NULL DEFAULT 'daily',
  `schedule_value` varchar(100) NOT NULL DEFAULT '02:00',
  `next_run_at` int unsigned NOT NULL DEFAULT 0,
  `last_run_at` int unsigned NOT NULL DEFAULT 0,
  `last_success_at` int unsigned NOT NULL DEFAULT 0,
  `last_error` text NOT NULL,
  `error_count` int unsigned NOT NULL DEFAULT 0,
  `requests_per_minute` int unsigned NOT NULL DEFAULT 30,
  `concurrency` int unsigned NOT NULL DEFAULT 2,
  `delay_seconds` int unsigned NOT NULL DEFAULT 1,
  `health_status` varchar(20) NOT NULL DEFAULT 'HEALTHY',
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_sources_enabled` (`enabled`),
  KEY `idx_sources_next_run` (`next_run_at`),
  KEY `idx_sources_provider` (`provider`),
  KEY `idx_sources_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- -----------------------------------------------------------
-- 2. grabber_discovered_videos - Discovery results
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `avs`.`grabber_discovered_videos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_id` int unsigned NOT NULL DEFAULT 0,
  `external_id` varchar(255) NOT NULL DEFAULT '',
  `source_url` text NOT NULL,
  `canonical_url` text NOT NULL,
  `title` varchar(500) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `tags` text NOT NULL,
  `duration` int unsigned NOT NULL DEFAULT 0,
  `thumbnail_url` text NOT NULL,
  `metadata_json` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'NEW',
  `first_seen_at` int unsigned NOT NULL DEFAULT 0,
  `last_seen_at` int unsigned NOT NULL DEFAULT 0,
  `imported_at` int unsigned NOT NULL DEFAULT 0,
  `video_id` int unsigned NOT NULL DEFAULT 0,
  `run_id` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_discovered_source_ext` (`source_id`, `external_id`(191)),
  KEY `idx_discovered_source` (`source_id`),
  KEY `idx_discovered_status` (`status`),
  KEY `idx_discovered_video` (`video_id`),
  KEY `idx_discovered_canonical` (`canonical_url`(191)),
  KEY `idx_discovered_run` (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- -----------------------------------------------------------
-- 3. grabber_jobs - Grab queue
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `avs`.`grabber_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_id` int unsigned NOT NULL DEFAULT 0,
  `discovered_video_id` int unsigned NOT NULL DEFAULT 0,
  `job_type` varchar(20) NOT NULL DEFAULT 'GRAB',
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `priority` int unsigned NOT NULL DEFAULT 10,
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `max_attempts` int unsigned NOT NULL DEFAULT 3,
  `scheduled_at` int unsigned NOT NULL DEFAULT 0,
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `video_id` int unsigned NOT NULL DEFAULT 0,
  `error_code` varchar(50) NOT NULL DEFAULT '',
  `error_message` text NOT NULL,
  `worker_pid` int unsigned NOT NULL DEFAULT 0,
  `run_id` int unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_jobs_status` (`status`),
  KEY `idx_jobs_scheduled` (`scheduled_at`),
  KEY `idx_jobs_source` (`source_id`),
  KEY `idx_jobs_discovered` (`discovered_video_id`),
  KEY `idx_jobs_run` (`run_id`),
  KEY `idx_jobs_pending` (`status`, `priority`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- -----------------------------------------------------------
-- 4. grabber_runs - Execution history
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `avs`.`grabber_runs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_id` int unsigned NOT NULL DEFAULT 0,
  `run_type` varchar(20) NOT NULL DEFAULT 'MANUAL',
  `status` varchar(20) NOT NULL DEFAULT 'RUNNING',
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `found_count` int unsigned NOT NULL DEFAULT 0,
  `new_count` int unsigned NOT NULL DEFAULT 0,
  `existing_count` int unsigned NOT NULL DEFAULT 0,
  `queued_count` int unsigned NOT NULL DEFAULT 0,
  `imported_count` int unsigned NOT NULL DEFAULT 0,
  `failed_count` int unsigned NOT NULL DEFAULT 0,
  `error_message` text NOT NULL,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_runs_source` (`source_id`),
  KEY `idx_runs_started` (`started_at`),
  KEY `idx_runs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- -----------------------------------------------------------
-- 5. grabber_logs - Centralized logging
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `avs`.`grabber_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int unsigned NOT NULL DEFAULT 0,
  `job_id` int unsigned NOT NULL DEFAULT 0,
  `source_id` int unsigned NOT NULL DEFAULT 0,
  `level` varchar(10) NOT NULL DEFAULT 'INFO',
  `event` varchar(100) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `context` text NOT NULL,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_logs_run` (`run_id`),
  KEY `idx_logs_job` (`job_id`),
  KEY `idx_logs_source` (`source_id`),
  KEY `idx_logs_level` (`level`),
  KEY `idx_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- -----------------------------------------------------------
-- 6. grabber_tag_mappings - Tag normalization (optional MVP)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `avs`.`grabber_tag_mappings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_id` int unsigned NOT NULL DEFAULT 0,
  `external_tag` varchar(255) NOT NULL DEFAULT '',
  `avs_tag` varchar(255) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_tagmap_source` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- -----------------------------------------------------------
-- 7. grabber_settings - Global settings (key/value)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `avs`.`grabber_settings` (
  `setting_key` varchar(100) NOT NULL DEFAULT '',
  `setting_value` text NOT NULL,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

INSERT INTO `avs`.`grabber_settings` (`setting_key`, `setting_value`, `updated_at`)
VALUES ('realtime_enabled', '0', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = VALUES(`updated_at`);

-- ============================================================
-- Default Sources (all supported providers pre-configured)
-- Admin should edit discovery_url for each before enabling
-- ============================================================

INSERT INTO `avs`.`grabber_sources` (`name`, `slug`, `domain`, `provider`, `enabled`, `automatic_enabled`, `discovery_enabled`, `discovery_url`, `quality`, `max_per_run`, `max_pages`, `schedule_type`, `schedule_value`, `delay_seconds`, `last_error`, `created_at`, `updated_at`)
VALUES
('YouTube', 'youtube', 'youtube.com', 'YouTube', 1, 0, 1, 'https://www.youtube.com/@PewDiePie/videos', 'best', 5, 3, 'daily', '02:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('XFree', 'xfree', 'xfree.com', 'XFree', 1, 0, 1, 'https://www.xfree.com/USERNAME', 'best', 5, 3, 'daily', '02:30', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('XVideos', 'xvideos', 'xvideos.com', 'XVideos', 1, 0, 1, 'https://www.xvideos.com/new/1', 'best', 5, 3, 'daily', '03:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('XHamster', 'xhamster', 'xhamster.com', 'XHamster', 1, 0, 1, 'https://xhamster.com/newest/1', 'best', 5, 3, 'daily', '03:30', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('PornHub', 'pornhub', 'pornhub.com', 'PornHub', 1, 0, 1, 'https://www.pornhub.com/videos?o=cm', 'best', 5, 3, 'daily', '04:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('Vimeo', 'vimeo', 'vimeo.com', 'Vimeo', 1, 0, 1, 'https://vimeo.com/channels/popular', 'best', 5, 3, 'daily', '04:30', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('DailyMotion', 'dailymotion', 'dailymotion.com', 'DailyMotion', 1, 0, 1, 'https://www.dailymotion.com/popular/videos', 'best', 5, 3, 'daily', '05:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('SonovinhasBR', 'sonovinhasbr', 'sonovinhasbr.com', 'SonovinhasBR', 1, 0, 1, 'https://www.sonovinhasbr.com/category/novinhas-gostosas/', 'best', 5, 3, 'daily', '05:30', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('Pornolandia', 'pornolandia', 'pornolandia.xxx', 'Pornolandia', 1, 0, 1, 'https://www.pornolandia.xxx/videos?page=1', 'best', 5, 3, 'daily', '06:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);