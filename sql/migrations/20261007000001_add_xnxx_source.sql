INSERT INTO `grabber_sources`
(`name`, `slug`, `domain`, `provider`, `enabled`, `automatic_enabled`, `discovery_enabled`, `discovery_url`,
 `category_id`, `quality`, `content_type`, `max_per_run`, `max_pages`, `schedule_type`, `schedule_value`,
 `next_run_at`, `last_run_at`, `last_success_at`, `last_error`, `error_count`,
 `requests_per_minute`, `concurrency`, `delay_seconds`, `health_status`, `created_at`, `updated_at`)
SELECT
  'XNXX',
  'xnxx',
  'xnxx.com',
  'Xnxx',
  1,
  0,
  1,
  'https://www.xnxx.com/hits',
  NULL,
  'best',
  'video',
  5,
  3,
  'daily',
  '06:00',
  UNIX_TIMESTAMP(),
  0,
  0,
  '',
  0,
  1,
  1,
  0,
  'OK',
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `grabber_sources`
  WHERE `provider` = 'Xnxx'
  LIMIT 1
);
