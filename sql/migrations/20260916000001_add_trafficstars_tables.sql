-- 16/09/2026 — Infra do integração TrafficStars (lado publisher).
-- Tabelas espelho da API para automatizar os grupos de anúncios internos:
--   ts_spot_map    : liga um adv_group (slot interno) ao spot da TrafficStars (ad externo)
--   ts_stats_daily : espelho diário do relatório /publisher/custom/report/by-day
-- Reaplicável (CREATE TABLE IF NOT EXISTS). Nada destrutivo.

CREATE TABLE IF NOT EXISTS `ts_spot_map` (
    `id`          int(11) unsigned NOT NULL AUTO_INCREMENT,
    `advgrp_id`   int(11) unsigned NOT NULL DEFAULT 0,
    `ts_spot_id`  int(11) unsigned NOT NULL DEFAULT 0,
    `spot_name`   varchar(255) NOT NULL DEFAULT '',
    `codename`    varchar(64)  NOT NULL DEFAULT '',
    `active`      tinyint(1)    NOT NULL DEFAULT '1',
    `created_at`  int(11) unsigned NOT NULL DEFAULT 0,
    `updated_at`  int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_advgrp` (`advgrp_id`),
    KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `ts_stats_daily` (
    `id`           int(11) unsigned NOT NULL AUTO_INCREMENT,
    `ts_spot_id`   int(11) unsigned NOT NULL DEFAULT 0,
    `day`          date NOT NULL DEFAULT '1970-01-01',
    `impressions`  int(11) unsigned NOT NULL DEFAULT 0,
    `clicks`       int(11) unsigned NOT NULL DEFAULT 0,
    `leads`        int(11) unsigned NOT NULL DEFAULT 0,
    `amount`       decimal(12,6) NOT NULL DEFAULT 0.000000,
    `ctr`          decimal(8,6)  NOT NULL DEFAULT 0.000000,
    `ecpm`         decimal(12,6) NOT NULL DEFAULT 0.000000,
    `ecpc`         decimal(12,6) NOT NULL DEFAULT 0.000000,
    `ecpa`         decimal(12,6) NOT NULL DEFAULT 0.000000,
    `synced_at`    int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_spot_day` (`ts_spot_id`, `day`),
    KEY `idx_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;