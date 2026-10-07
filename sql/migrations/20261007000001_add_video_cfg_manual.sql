-- Migration: override manual de processamento por video (video.cfg_manual).
--
-- O edit do siteadmin agora grava watermark_cfg/cut/cut_out direto no video.
-- Sem esta coluna nao ha como distinguir "config escolhida pelo admin" de
-- "snapshot vindo da grabber_sources": wm_refresh_video_snapshot() (chamado em
-- todo reprocess via grabber_worker.php) e o UPDATE de reuso do grabber_cron
-- regravariam os valores da fonte por cima da edicao manual.
--
-- cfg_manual = 1 -> o video e dono da config: refresh da fonte ignorado e
--                   wm_video_config() nao cai no fallback da fonte (permite
--                   desligar o watermark so neste video, enabled=0).
-- cfg_manual = 0 -> comportamento atual (snapshot / fallback da fonte).
--
-- Idempotente (seguro de re-executar manualmente).

SET @dbname = DATABASE();

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'video' AND COLUMN_NAME = 'cfg_manual';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `video` ADD COLUMN `cfg_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cut_out`',
    'SELECT "Column cfg_manual already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
