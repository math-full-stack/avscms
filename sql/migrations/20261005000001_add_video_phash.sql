-- Migration: impressao digital de conteudo (pHash) + indice de source_url
-- Fully idempotent: cada ALTER so acontece se a coluna/indice ainda nao existe.
--
-- phash: 64 bits em hex (16 chars) calculados pelo VideoDuplicate a partir do
-- arquivo local (8 frames 16x16 cinza -> media -> DCT -> mediana). E o que
-- pega o mesmo arquivo vindo de mirror/outra fonte, que o dedup por URL nao
-- pega. Vazio = video ainda nao foi fingerprintado.
--
-- src_url: as checagens de duplicata por URL (DedupManager, grabber_cron)
-- comparavam source_url sem indice nenhum - full table scan da video a cada
-- video descoberto.
--
-- Nao ha UNIQUE em phash de proposito: dois videos iguais podem coexistir
-- (o admin decide o que fazer com o segundo) e o auto-bloqueio acontece na
-- aplicacao, depois de comparar a distancia de Hamming.

SET @dbname = DATABASE();

-- coluna phash
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'video' AND COLUMN_NAME = 'phash';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `video` ADD COLUMN `phash` char(16) NOT NULL DEFAULT '''' AFTER `source_url`',
    'SELECT "Column phash already exists" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- indice phash + duration (candidato do VideoDuplicate::findDuplicate)
SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'video' AND INDEX_NAME = 'phash_dur';

SET @sql2 = IF(@idx_exists = 0,
    'ALTER TABLE `video` ADD KEY `phash_dur` (`phash`, `duration`)',
    'SELECT "Index phash_dur already exists" AS info'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- indice de source_url (dedup por URL parou de ser full scan)
SELECT COUNT(*) INTO @idx_exists2 FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'video' AND INDEX_NAME = 'src_url';

SET @sql3 = IF(@idx_exists2 = 0,
    'ALTER TABLE `video` ADD KEY `src_url` (`source_url`(191))',
    'SELECT "Index src_url already exists" AS info'
);
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;