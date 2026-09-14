-- 16/09/2026 — Fontes de anúncio (injeção automática em todos os grupos).
-- Uma fonte = um network/provider com um snippet de embed (JSSDK/iframe).
-- Ativa, a fonte passa a ser servida em TODOS os grupos de anúncio, mesclada
-- com os anúncios manuais pela coluna `share` (% das impressões do grupo).
-- Reaplicável (CREATE TABLE IF NOT EXISTS). Nada destrutivo.

CREATE TABLE IF NOT EXISTS `adv_source` (
    `id`           int(11) unsigned NOT NULL AUTO_INCREMENT,
    `name`         varchar(80)  NOT NULL DEFAULT '',
    `provider`     varchar(40)  NOT NULL DEFAULT 'custom',
    `html`         mediumtext   NULL,
    `share`        tinyint(3)   NOT NULL DEFAULT '50',
    `active`       tinyint(1)   NOT NULL DEFAULT '0',
    `impressions`  int(11) unsigned NOT NULL DEFAULT 0,
    `created_at`   int(11) unsigned NOT NULL DEFAULT 0,
    `updated_at`   int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;