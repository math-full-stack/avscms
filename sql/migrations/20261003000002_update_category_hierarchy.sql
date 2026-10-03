-- Migration: Update existing categories to new hierarchy
-- Execute: mysql -h 127.0.0.1 -P 3307 -u avs_app -pjKQcmDubSge1C0CkB83v avs < sql/migrations/20261003000002_update_category_hierarchy.sql

SET NAMES utf8;
SET FOREIGN_KEY_CHECKS = 0;

-- First, insert the parent categories if they don't exist
INSERT IGNORE INTO `channel` (`name`, `slug`, `parent_id`, `sort_order`, `is_featured`) VALUES
('Amador', 'amador', 0, 10, 1),
('Região', 'regiao', 0, 20, 1),
('Perfil', 'perfil', 0, 30, 1),
('Cenário', 'cenario', 0, 40, 1),
('Prática', 'pratica', 0, 50, 1),
('Formato', 'formato', 0, 60, 0),
('Qualidade', 'qualidade', 0, 70, 0);

-- Get parent IDs for reference
-- Amador: CHID where slug='amador' and parent_id=0
-- Região: CHID where slug='regiao' and parent_id=0
-- Perfil: CHID where slug='perfil' and parent_id=0
-- Cenário: CHID where slug='cenario' and parent_id=0
-- Prática: CHID where slug='pratica' and parent_id=0
-- Formato: CHID where slug='formato' and parent_id=0
-- Qualidade: CHID where slug='qualidade' and parent_id=0

-- Update existing categories to be children of the new parents
-- We'll use the legacy_map from category_seed.php to determine the new parent

-- Amador children
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'amador' AND `parent_id` = 0) AS t), `sort_order` = 10, `is_featured` = 1 WHERE `slug` = 'caseiro';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'amador' AND `parent_id` = 0) AS t), `sort_order` = 20, `is_featured` = 1 WHERE `slug` = 'celular';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'amador' AND `parent_id` = 0) AS t), `sort_order` = 30, `is_featured` = 1 WHERE `slug` = 'webcam';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'amador' AND `parent_id` = 0) AS t), `sort_order` = 40, `is_featured` = 1 WHERE `slug` = 'vazou-caiu-na-net' OR `slug` IN ('caiu-na-net', 'flagras', 'vazou');
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'amador' AND `parent_id` = 0) AS t), `sort_order` = 50, `is_featured` = 1 WHERE `slug` = 'onlyfans-privacy';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'amador' AND `parent_id` = 0) AS t), `sort_order` = 60, `is_featured` = 0 WHERE `slug` = 'amador-real';

-- Região children (these don't exist yet, need to be created or we can skip)

-- Perfil children
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 10, `is_featured` = 1 WHERE `slug` = 'casal-amador' OR `slug` = 'casal';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 20, `is_featured` = 1 WHERE `slug` = 'novinha-18' OR `slug` = 'novinhas-18';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 30, `is_featured` = 1 WHERE `slug` = 'milf-coroas' OR `slug` = 'milf-coroas';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 40, `is_featured` = 1 WHERE `slug` = 'bbw-gordinhas' OR `slug` IN ('gordinhas', 'gordas-bbw');
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 50, `is_featured` = 1 WHERE `slug` = 'negras-mulatas' OR `slug` IN ('negras', 'mulatas');
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 60, `is_featured` = 1 WHERE `slug` = 'trans-travestis-amador' OR `slug` = 'trans-travestis';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 70, `is_featured` = 1 WHERE `slug` = 'gay-amador';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 80, `is_featured` = 1 WHERE `slug` = 'lesbicas-amador' OR `slug` = 'lesbicas';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 90, `is_featured` = 0 WHERE `slug` = 'loiras';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 100, `is_featured` = 0 WHERE `slug` = 'morenas';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 110, `is_featured` = 0 WHERE `slug` = 'ruivas';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 120, `is_featured` = 0 WHERE `slug` = 'fitness-saradas';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 130, `is_featured` = 0 WHERE `slug` = 'tatuadas';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 140, `is_featured` = 0 WHERE `slug` = 'peitudas';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 150, `is_featured` = 0 WHERE `slug` = 'bundudas';

-- Cenário children
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 10, `is_featured` = 1 WHERE `slug` = 'motel-hotel';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 20, `is_featured` = 1 WHERE `slug` = 'carro';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 30, `is_featured` = 1 WHERE `slug` = 'publico-ao-ar-livre' OR `slug` = 'ao-ar-livre';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 40, `is_featured` = 1 WHERE `slug` = 'banheiro-chuveiro' OR `slug` = 'banheiro';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 50, `is_featured` = 1 WHERE `slug` = 'festa-balada';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 60, `is_featured` = 1 WHERE `slug` = 'trabalho-escritorio' OR `slug` = 'trabalho' OR `slug` = 'escritorio';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 70, `is_featured` = 1 WHERE `slug` = 'academia-vestiario' OR `slug` = 'academia';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 80, `is_featured` = 1 WHERE `slug` = 'faculdade-escola' OR `slug` = 'escola-faculdade';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 90, `is_featured` = 0 WHERE `slug` = 'quarto-cama' OR `slug` = 'quarto';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 100, `is_featured` = 0 WHERE `slug` = 'cozinha';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 110, `is_featured` = 0 WHERE `slug` = 'praia';

-- Prática children
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'pratica' AND `parent_id` = 0) AS t), `sort_order` = 10, `is_featured` = 1 WHERE `slug` = 'anal-amador' OR `slug` = 'anal';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'pratica' AND `parent_id` = 0) AS t), `sort_order` = 20, `is_featured` = 1 WHERE `slug` = 'oral-boquete';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'pratica' AND `parent_id` = 0) AS t), `sort_order` = 30, `is_featured` = 1 WHERE `slug` = 'gozada-facial';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'pratica' AND `parent_id` = 0) AS t), `sort_order` = 40, `is_featured` = 1 WHERE `slug` = 'creampie-gozada-dentro' OR `slug` = 'creampie';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'pratica' AND `parent_id` = 0) AS t), `sort_order` = 50, `is_featured` = 1 WHERE `slug` = 'squirting';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'pratica' AND `parent_id` = 0) AS t), `sort_order` = 60, `is_featured` = 1 WHERE `slug` = 'menage-suruba' OR `slug` IN ('suruba', 'menage', 'orgias', 'gangbang', 'swing');
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'pratica' AND `parent_id` = 0) AS t), `sort_order` = 70, `is_featured` = 1 WHERE `slug` = 'dp-dupla-penetracao';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'pratica' AND `parent_id` = 0) AS t), `sort_order` = 80, `is_featured` = 0 WHERE `slug` = 'pegging-amador';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'pratica' AND `parent_id` = 0) AS t), `sort_order` = 90, `is_featured` = 0 WHERE `slug` = 'footjob-pes' OR `slug` = 'pes-sapatinho';

-- Formato children
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'formato' AND `parent_id` = 0) AS t), `sort_order` = 10, `is_featured` = 0 WHERE `slug` = 'curto-quickie' OR `slug` = 'clipes';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'formato' AND `parent_id` = 0) AS t), `sort_order` = 20, `is_featured` = 0 WHERE `slug` = 'medio-5-15min';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'formato' AND `parent_id` = 0) AS t), `sort_order` = 30, `is_featured` = 0 WHERE `slug` = 'longo-15min' OR `slug` = 'longa-duracao';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'formato' AND `parent_id` = 0) AS t), `sort_order` = 40, `is_featured` = 1 WHERE `slug` = 'vertical-reels-shorts';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'formato' AND `parent_id` = 0) AS t), `sort_order` = 50, `is_featured` = 0 WHERE `slug` = 'compilacao';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'formato' AND `parent_id` = 0) AS t), `sort_order` = 60, `is_featured` = 0 WHERE `slug` = 'full-movie';

-- Qualidade children
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'qualidade' AND `parent_id` = 0) AS t), `sort_order` = 10, `is_featured` = 0 WHERE `slug` = 'hd-4k';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'qualidade' AND `parent_id` = 0) AS t), `sort_order` = 20, `is_featured` = 0 WHERE `slug` = 'producao-nacional';
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'qualidade' AND `parent_id` = 0) AS t), `sort_order` = 30, `is_featured` = 0 WHERE `slug` = 'webcam';

-- Remaining categories that don't fit neatly - map to closest parent
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'amador' AND `parent_id` = 0) AS t), `sort_order` = 70, `is_featured` = 0 WHERE `slug` IN ('pov', 'hardcore', 'softcore', 'fetish', 'bdsm', 'roleplay', 'voyeur', 'exibicionismo');
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 160, `is_featured` = 0 WHERE `slug` IN ('asiaticas', 'magrinhas', 'loira-de-silicone', 'baixinhas', 'altas-amazona', 'emo-gotica', 'de-oculos', 'platinadas', 'cabelo-colorido');
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'cenario' AND `parent_id` = 0) AS t), `sort_order` = 120, `is_featured` = 0 WHERE `slug` IN ('funk-baile-funk', 'carnaval', 'junina-sao-joao', 'praia-brasileira', 'favela-comunidade', 'interior-roca', 'universitarias', 'funcionarias', 'empregadas', 'vizinhas');
UPDATE `channel` SET `parent_id` = (SELECT CHID FROM (SELECT CHID FROM `channel` WHERE `slug` = 'perfil' AND `parent_id` = 0) AS t), `sort_order` = 170, `is_featured` = 0 WHERE `slug` IN ('primas', 'cunhadas', 'madrastas', 'enteadas', 'sogra');

-- Categories that might be better as tags or need review:
-- 'brasileiras' - keep as parent but could be merged into Perfil

SET FOREIGN_KEY_CHECKS = 1;

-- Verify
SELECT CHID, name, slug, parent_id, sort_order, is_featured FROM channel ORDER BY parent_id, sort_order, name;