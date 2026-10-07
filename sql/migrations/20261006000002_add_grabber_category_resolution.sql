-- 06/10/2026 — Mass grabber: resolução de CATEGORIA como já existe para tags.
--
-- Hoje todo vídeo novo nasce em `grabber_sources.category_id` e, quando essa
-- fonte está zerada, cai em CHID 1 = "Amador" (categoria PAI) — o pai enchia e
-- a árvore de folhas era inútil. A categoria do site-fonte era descartada
-- (BuceteiroGrabber devolve 'category' sem consumidor) ou virava TAG.
--
-- O pipeline passa a capturar a categoria externa e resolve-a em cascata
-- (CategoryResolver): mapping manual -> auto-match por nome/slug -> categoria
-- fixa da fonte -> fallback da fonte. Nenhum passo pode devolver um CHID com
-- parent_id = 0 (guarda no resolver, não aqui).
--
--   grabber_discovered_videos.external_category : o que o scraper/discovery viu
--   grabber_sources.fallback_category_id       : folha de reserva da fonte
--   grabber_category_mappings                  : external_category -> CHID
--
-- Idempotente: colunas só entram se não existirem; tabela só é criada se
-- não existir (mesmo padrão de 20261005000002 / 20261003000003). Nada
-- destrutivo.

-- 1. Coluna na descoberta ------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'grabber_discovered_videos'
    AND COLUMN_NAME = 'external_category'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `grabber_discovered_videos` ADD COLUMN `external_category` varchar(255) NOT NULL DEFAULT '''' AFTER `tags`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Folha de reserva por fonte -----------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'grabber_sources'
    AND COLUMN_NAME = 'fallback_category_id'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `grabber_sources` ADD COLUMN `fallback_category_id` int(11) unsigned NOT NULL DEFAULT ''0'' AFTER `category_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Mapping externo -> CHID --------------------------------------------
CREATE TABLE IF NOT EXISTS `grabber_category_mappings` (
  `id`               int(11) unsigned NOT NULL AUTO_INCREMENT,
  `source_id`        int(11) unsigned NOT NULL DEFAULT 0,
  `external_category` varchar(255) NOT NULL DEFAULT '',
  `chid`             int(11) unsigned NOT NULL DEFAULT 0,
  `enabled`          tinyint(1) NOT NULL DEFAULT 1,
  `created_at`       int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_source_external` (`source_id`, `external_category`),
  KEY `idx_catmap_source` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
