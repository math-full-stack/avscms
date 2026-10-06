-- 05/10/2026 — Mass grabber: fonte TikTok + tipo de conteúdo por fonte.
--
-- `grabber_sources.content_type` diz o que a fonte produz:
--   'video'  (default) = vídeo normal, entra nos feeds de vídeo
--   'shorts'           = vertical curto, entra no feed de shorts
-- No TikTok TODO vídeo é vertical e curto, então a fonte nasce com 'shorts':
-- o import marca orientation='portrait' e o provider só aceita a janela de
-- shorts (duração < 100s), que é o critério do feed (shorts.php /
-- include/ajax/shorts_feed.php).
--
-- Idempotente: coluna só entra se não existir; fonte só entra se o provider
-- 'TikTok' ainda não tiver sido cadastrado.
--
-- A fonte nasce com discovery_url VAZIA de propósito: o TikTok é por conta
-- (@handle), então o dono preenche o perfil em Admin > Mass Grabber > Sources
-- (ex.: https://www.tiktok.com/@minha_conta). Sem URL, o agendador ignora a
-- fonte e o scan manual avisa que falta configurar.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'grabber_sources'
    AND COLUMN_NAME = 'content_type'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `grabber_sources` ADD COLUMN `content_type` varchar(20) NOT NULL DEFAULT ''video'' AFTER `quality`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `grabber_sources` (`name`, `slug`, `domain`, `provider`, `enabled`, `automatic_enabled`, `discovery_enabled`, `discovery_url`, `quality`, `content_type`, `max_per_run`, `max_pages`, `schedule_type`, `schedule_value`, `delay_seconds`, `last_error`, `created_at`, `updated_at`)
SELECT 'TikTok', 'tiktok', 'tiktok.com', 'TikTok', 1, 0, 1, '', 'best', 'shorts', 10, 5, 'daily', '06:00', 1, '', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM `grabber_sources` WHERE `provider` = 'TikTok' LIMIT 1
);
