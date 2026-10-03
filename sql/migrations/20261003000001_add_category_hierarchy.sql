-- Migration: Add category hierarchy to channel table
-- Execute: mysql -u root -p avs < sql/migrations/20261003000001_add_category_hierarchy.sql

SET NAMES utf8;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Add hierarchy columns to channel table
ALTER TABLE `channel`
    ADD COLUMN `parent_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT '0' AFTER `slug`,
    ADD COLUMN `sort_order` INT(11) NOT NULL DEFAULT '0' AFTER `parent_id`,
    ADD COLUMN `is_featured` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' AFTER `sort_order`,
    ADD INDEX `idx_parent_id` (`parent_id`),
    ADD INDEX `idx_sort_order` (`sort_order`),
    ADD INDEX `idx_is_featured` (`is_featured`);

-- 2. Insert parent categories (top-level)
INSERT INTO `channel` (`name`, `slug`, `parent_id`, `sort_order`, `is_featured`) VALUES
-- Parents (sort_order defines menu order)
('Amador', 'amador', 0, 10, 1),
('Região', 'regiao', 0, 20, 1),
('Perfil', 'perfil', 0, 30, 1),
('Cenário', 'cenario', 0, 40, 1),
('Prática', 'pratica', 0, 50, 1),
('Formato', 'formato', 0, 60, 0),
('Qualidade', 'qualidade', 0, 70, 0);

-- 3. Insert child categories for 'Amador' (parent_id = LAST_INSERT_ID() of 'Amador')
-- We'll use a variable approach - first get the parent IDs
-- Since we can't use variables easily in a single script, we'll insert with known IDs
-- Assuming auto-increment starts from current max CHID + 1

-- Get parent IDs (run this logic in application or use subqueries)
-- For migration, we insert children with subqueries to find parent CHID

-- Children of 'Amador'
INSERT INTO `channel` (`name`, `slug`, `parent_id`, `sort_order`, `is_featured`) VALUES
('Caseiro', 'caseiro', (SELECT CHID FROM channel WHERE slug = 'amador' LIMIT 1), 10, 1),
('Celular', 'celular', (SELECT CHID FROM channel WHERE slug = 'amador' LIMIT 1), 20, 1),
('Webcam', 'webcam', (SELECT CHID FROM channel WHERE slug = 'amador' LIMIT 1), 30, 1),
('Vazou / Caiu na Net', 'vazou-caiu-na-net', (SELECT CHID FROM channel WHERE slug = 'amador' LIMIT 1), 40, 1),
('OnlyFans / Privacy', 'onlyfans-privacy', (SELECT CHID FROM channel WHERE slug = 'amador' LIMIT 1), 50, 1),
('Amador Real', 'amador-real', (SELECT CHID FROM channel WHERE slug = 'amador' LIMIT 1), 60, 0);

-- Children of 'Região'
INSERT INTO `channel` (`name`, `slug`, `parent_id`, `sort_order`, `is_featured`) VALUES
('Sudeste', 'sudeste', (SELECT CHID FROM channel WHERE slug = 'regiao' LIMIT 1), 10, 1),
('Sul', 'sul', (SELECT CHID FROM channel WHERE slug = 'regiao' LIMIT 1), 20, 1),
('Nordeste', 'nordeste', (SELECT CHID FROM channel WHERE slug = 'regiao' LIMIT 1), 30, 1),
('Norte', 'norte', (SELECT CHID FROM channel WHERE slug = 'regiao' LIMIT 1), 40, 1),
('Centro-Oeste', 'centro-oeste', (SELECT CHID FROM channel WHERE slug = 'regiao' LIMIT 1), 50, 1);

-- Children of 'Perfil'
INSERT INTO `channel` (`name`, `slug`, `parent_id`, `sort_order`, `is_featured`) VALUES
('Casal Amador', 'casal-amador', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 10, 1),
('Novinha +18', 'novinha-18', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 20, 1),
('MILF / Coroas', 'milf-coroas', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 30, 1),
('BBW / Gordinhas', 'bbw-gordinhas', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 40, 1),
('Negras / Mulatas', 'negras-mulatas', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 50, 1),
('Trans / Travestis Amador', 'trans-travestis-amador', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 60, 1),
('Gay Amador', 'gay-amador', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 70, 1),
('Lésbicas Amador', 'lesbicas-amador', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 80, 1),
('Loiras', 'loiras', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 90, 0),
('Morenas', 'morenas', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 100, 0),
('Ruivas', 'ruivas', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 110, 0),
('Fitness / Saradas', 'fitness-saradas', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 120, 0),
('Tatuadas', 'tatuadas', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 130, 0),
('Peitudas', 'peitudas', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 140, 0),
('Bundudas', 'bundudas', (SELECT CHID FROM channel WHERE slug = 'perfil' LIMIT 1), 150, 0);

-- Children of 'Cenário'
INSERT INTO `channel` (`name`, `slug`, `parent_id`, `sort_order`, `is_featured`) VALUES
('Motel / Hotel', 'motel-hotel', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 10, 1),
('Carro', 'carro', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 20, 1),
('Público / Ao Ar Livre', 'publico-ao-ar-livre', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 30, 1),
('Banheiro / Chuveiro', 'banheiro-chuveiro', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 40, 1),
('Festa / Balada', 'festa-balada', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 50, 1),
('Trabalho / Escritório', 'trabalho-escritorio', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 60, 1),
('Academia / Vestiário', 'academia-vestiario', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 70, 1),
('Faculdade / Escola', 'faculdade-escola', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 80, 1),
('Quarto / Cama', 'quarto-cama', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 90, 0),
('Cozinha', 'cozinha', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 100, 0),
('Praia', 'praia', (SELECT CHID FROM channel WHERE slug = 'cenario' LIMIT 1), 110, 0);

-- Children of 'Prática'
INSERT INTO `channel` (`name`, `slug`, `parent_id`, `sort_order`, `is_featured`) VALUES
('Anal Amador', 'anal-amador', (SELECT CHID FROM channel WHERE slug = 'pratica' LIMIT 1), 10, 1),
('Oral / Boquete', 'oral-boquete', (SELECT CHID FROM channel WHERE slug = 'pratica' LIMIT 1), 20, 1),
('Gozada / Facial', 'gozada-facial', (SELECT CHID FROM channel WHERE slug = 'pratica' LIMIT 1), 30, 1),
('Creampie / Gozada Dentro', 'creampie-gozada-dentro', (SELECT CHID FROM channel WHERE slug = 'pratica' LIMIT 1), 40, 1),
('Squirting', 'squirting', (SELECT CHID FROM channel WHERE slug = 'pratica' LIMIT 1), 50, 1),
('Ménage / Suruba', 'menage-suruba', (SELECT CHID FROM channel WHERE slug = 'pratica' LIMIT 1), 60, 1),
('DP / Dupla Penetração', 'dp-dupla-penetracao', (SELECT CHID FROM channel WHERE slug = 'pratica' LIMIT 1), 70, 1),
('Pegging Amador', 'pegging-amador', (SELECT CHID FROM channel WHERE slug = 'pratica' LIMIT 1), 80, 0),
('Footjob / Pés', 'footjob-pes', (SELECT CHID FROM channel WHERE slug = 'pratica' LIMIT 1), 90, 0);

-- Children of 'Formato'
INSERT INTO `channel` (`name`, `slug`, `parent_id`, `sort_order`, `is_featured`) VALUES
('Curto / Quickie (<5min)', 'curto-quickie', (SELECT CHID FROM channel WHERE slug = 'formato' LIMIT 1), 10, 0),
('Médio (5-15min)', 'medio-5-15min', (SELECT CHID FROM channel WHERE slug = 'formato' LIMIT 1), 20, 0),
('Longo (>15min)', 'longo-15min', (SELECT CHID FROM channel WHERE slug = 'formato' LIMIT 1), 30, 0),
('Vertical / Reels / Shorts', 'vertical-reels-shorts', (SELECT CHID FROM channel WHERE slug = 'formato' LIMIT 1), 40, 1),
('Compilação', 'compilacao', (SELECT CHID FROM channel WHERE slug = 'formato' LIMIT 1), 50, 0),
('Full Movie', 'full-movie', (SELECT CHID FROM channel WHERE slug = 'formato' LIMIT 1), 60, 0);

-- Children of 'Qualidade'
INSERT INTO `channel` (`name`, `slug`, `parent_id`, `sort_order`, `is_featured`) VALUES
('HD / 4K', 'hd-4k', (SELECT CHID FROM channel WHERE slug = 'qualidade' LIMIT 1), 10, 0),
('Produção Nacional', 'producao-nacional', (SELECT CHID FROM channel WHERE slug = 'qualidade' LIMIT 1), 20, 0),
('Webcam', 'webcam', (SELECT CHID FROM channel WHERE slug = 'qualidade' LIMIT 1), 30, 0);

-- 4. Migrate existing categories to new hierarchy (map old flat categories to new children)
-- This maps the old categories_pornozinho.sql categories to the new structure
-- Run after verifying the new parent IDs are correct

-- Map: Brasileiras -> Perfil (already covered by subcategories)
-- Map: Caiu na Net, Flagras, Vazou -> Amador > Vazou / Caiu na Net
-- Map: Anal, Oral, Gozada, Creampie, Squirting, DP, Gangbang, Orgias -> Prática (already covered)
-- Map: Lésbicas -> Perfil > Lésbicas Amador
-- Map: Trans / Travestis -> Perfil > Trans / Travestis Amador
-- Map: Caseiro, POV, Hardcore, Softcore, Fetish, BDSM, Roleplay, Voyeur, Exibicionismo, Suruba, Menage, Swing -> distributed
-- Map: Praia, Carro, Motel/Hotel, Banheiro, Cozinha, Quarto, Escritório, Academia, Festa/Balada, Escola/Faculdade, Trabalho, Ao Ar Livre -> Cenário (already covered)
-- Map: Funk, Carnaval, Junina, Praia Brasileira, Favela, Interior, Universitárias, Funcionárias, Empregadas, Vizinhas, Primas, Cunhadas, Madrastas, Enteadas, Sogra -> could be tags or Cenário children

-- 5. Update total_videos counters for new parent categories (run after video migration)
-- UPDATE channel SET total_videos = (SELECT COUNT(*) FROM video WHERE channel = channel.CHID AND active = '1') WHERE parent_id = 0;

SET FOREIGN_KEY_CHECKS = 1;