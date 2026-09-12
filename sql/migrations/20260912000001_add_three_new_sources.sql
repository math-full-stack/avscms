-- Migration: Add PornoMineiro, NaoConto, PornoBrasil sources to the mass grabber
-- Idempotent: only inserts if sources do not exist yet

INSERT INTO `grabber_sources` (`name`, `slug`, `domain`, `provider`, `enabled`, `automatic_enabled`, `discovery_enabled`, `discovery_url`, `quality`, `max_per_run`, `max_pages`, `schedule_type`, `schedule_value`, `delay_seconds`, `last_error`, `created_at`, `updated_at`)
SELECT 'PornoMineiro', 'pornomineiro', 'pornomineiro.com', 'PornoMineiro', 1, 0, 1, 'https://www.pornomineiro.com', 'best', 5, 3, 'daily', '06:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM `grabber_sources` WHERE `provider` = 'PornoMineiro' LIMIT 1
);

INSERT INTO `grabber_sources` (`name`, `slug`, `domain`, `provider`, `enabled`, `automatic_enabled`, `discovery_enabled`, `discovery_url`, `quality`, `max_per_run`, `max_pages`, `schedule_type`, `schedule_value`, `delay_seconds`, `last_error`, `created_at`, `updated_at`)
SELECT 'NaoConto', 'naoconto', 'naoconto.com', 'NaoConto', 1, 0, 1, 'https://www.naoconto.com', 'best', 5, 3, 'daily', '06:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM `grabber_sources` WHERE `provider` = 'NaoConto' LIMIT 1
);

INSERT INTO `grabber_sources` (`name`, `slug`, `domain`, `provider`, `enabled`, `automatic_enabled`, `discovery_enabled`, `discovery_url`, `quality`, `max_per_run`, `max_pages`, `schedule_type`, `schedule_value`, `delay_seconds`, `last_error`, `created_at`, `updated_at`)
SELECT 'PornoBrasil', 'pornobrasil', 'pornobrasil.com', 'PornoBrasil', 1, 0, 1, 'https://pornobrasil.com', 'best', 5, 3, 'daily', '06:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM `grabber_sources` WHERE `provider` = 'PornoBrasil' LIMIT 1
);
