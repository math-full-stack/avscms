-- Migration: Add Pornolandia source to the mass grabber
-- Idempotent: only inserts if a Pornolandia source does not exist yet

INSERT INTO `grabber_sources` (`name`, `slug`, `domain`, `provider`, `enabled`, `automatic_enabled`, `discovery_enabled`, `discovery_url`, `quality`, `max_per_run`, `max_pages`, `schedule_type`, `schedule_value`, `delay_seconds`, `last_error`, `created_at`, `updated_at`)
SELECT 'Pornolandia', 'pornolandia', 'pornolandia.xxx', 'Pornolandia', 1, 0, 1, 'https://www.pornolandia.xxx/videos?page=1', 'best', 5, 3, 'daily', '06:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM `grabber_sources` WHERE `provider` = 'Pornolandia' OR `slug` = 'pornolandia' LIMIT 1
);