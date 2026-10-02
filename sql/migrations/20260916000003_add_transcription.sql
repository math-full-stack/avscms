-- 16/09/2026 — Transcrição automática (faster-whisper) + legendas.
--   transcription_jobs : fila/estado dos jobs de transcrição (início manual no
--                        siteadmin; quem processa é decidido por
--                        transcribe_executor x worker_role — fail-closed).
--   subtitles          : legendas geradas (SRT/VTT/TXT) + timestamps por palavra
--                        (words_json) e diarização (speakers_json; hoje fixa
--                        "Palestrante 1").
-- Reaplicável (CREATE TABLE IF NOT EXISTS). Nada destrutivo.

CREATE TABLE IF NOT EXISTS `transcription_jobs` (
    `id`          int(11) unsigned NOT NULL AUTO_INCREMENT,
    `VID`         int(11) unsigned NOT NULL DEFAULT 0,
    `status`      varchar(20)  NOT NULL DEFAULT 'pending',
    `source`      varchar(20)  NOT NULL DEFAULT 'manual',
    `model`       varchar(32)  NOT NULL DEFAULT 'small',
    `language`    varchar(16)  NOT NULL DEFAULT 'auto',
    `attempts`    tinyint(3) unsigned NOT NULL DEFAULT 0,
    `error`       text         NULL,
    `progress`    decimal(5,2) NOT NULL DEFAULT 0.00,
    `created_at`  int(11) unsigned NOT NULL DEFAULT 0,
    `started_at`  int(11) unsigned NOT NULL DEFAULT 0,
    `finished_at` int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_vid` (`VID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `subtitles` (
    `id`            int(11) unsigned NOT NULL AUTO_INCREMENT,
    `VID`           int(11) unsigned NOT NULL DEFAULT 0,
    `lang`          varchar(16) NOT NULL DEFAULT 'auto',
    `provider`      varchar(32) NOT NULL DEFAULT 'faster-whisper',
    `srt`           mediumtext  NULL,
    `vtt`           mediumtext  NULL,
    `txt`           mediumtext  NULL,
    `words_json`    mediumtext  NULL,
    `speakers_json` mediumtext  NULL,
    `enabled`       tinyint(1)  NOT NULL DEFAULT 1,
    `created_at`    int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_vid_lang` (`VID`, `lang`),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;