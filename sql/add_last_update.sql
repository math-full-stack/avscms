-- Adiciona coluna last_update para rastrear último acesso ao vídeo
-- Usada para destravar vídeos presos nos estados 2 (baixando) ou 3 (na fila)

ALTER TABLE `video` ADD COLUMN `last_update` INT(11) NOT NULL DEFAULT 0 AFTER `active`;

-- Index para performance na limpeza de vídeos presos
ALTER TABLE `video` ADD KEY `active_lastupdate` (`active`, `last_update`);
