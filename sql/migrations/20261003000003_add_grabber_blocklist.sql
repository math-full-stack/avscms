-- 03/10/2026 — Lista de bloqueio global do Mass Video Grabber.
-- Videos rejeitados/analisados e descartados ficam aqui e NUNCA voltam:
-- o scan os trata como "já conhecidos" (sem inserir), a listagem do Discover
-- os filtra e o JobManager recusa fila para eles — cruzando fontes, não só a
-- fonte onde foram vistos.
-- Normalização da URL: lowercase + trim + strip de '/' final, igual nos dois
-- lados (PHP strtolower(rtrim(trim($u),'/')) <-> SQL TRIM(TRAILING '/' FROM LOWER(TRIM(col)))),
-- por isso o hash md5 é comparável direto.
-- Reaplicável (CREATE TABLE IF NOT EXISTS). Nada destrutivo.

CREATE TABLE IF NOT EXISTS `grabber_blocklist` (
    `id`            int(11) unsigned NOT NULL AUTO_INCREMENT,
    `url`           varchar(500) NOT NULL DEFAULT '',
    `url_hash`      char(32)     NOT NULL DEFAULT '',
    `external_id`   varchar(255) NOT NULL DEFAULT '',
    `title`         varchar(500) NOT NULL DEFAULT '',
    `reason`        varchar(60)  NOT NULL DEFAULT '',
    `source_id`     int(11) unsigned NOT NULL DEFAULT 0,
    `discovered_id` int(11) unsigned NOT NULL DEFAULT 0,
    `created_at`    int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_blocklist_url_hash` (`url_hash`),
    KEY `idx_blocklist_external` (`external_id`(191)),
    KEY `idx_blocklist_source` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
