-- Tags curadas para Pornozinho - Conteúdo Adulto Brasileiro (+18)
-- Tabela: tags
-- Execute: mysql -u root -p avs < sql/tags_pornozinho.sql
--
-- Regras do vocabulário (ver plano categorias+tags):
--   1. As tags NÃO repetem o nome/slug de categorias (ex.: sem tag "anal" já que
--      existe a categoria Anal). Complementam com granularidade.
--   2. Formato: minúsculas, sem pontuação, multi-palavras com espaço.
--   3. counter inicial = 1 apenas para aparecer na nuvem (tags.php exige >= 1).
--      O valor real deve vir da recontagem sobre video.keyword (query comentada
--      no fim). Nomes próprios/atrizes são adicionados manualmente pelo admin.

SET NAMES utf8;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `tags` (`tag`, `counter`) VALUES
-- Corpo / Físico (detalhes - categorias já cobrem o grupo maior)
('cinturinha', 1),
('tanquinho', 1),
('cabelo longo', 1),
('cabelo cacheado', 1),
('sardas', 1),
('olhos azuis', 1),
('olhos verdes', 1),
('pernas grossas', 1),
('silicone', 1),
('untada', 1),
('peito natural', 1),

-- Ato / Prática (subtipos não cobertos pelas categorias)
('espanhola', 1),
('deepthroat', 1),
('punheta', 1),
('masturbacao', 1),
('vibrador', 1),
('plug anal', 1),
('consolo', 1),
('algemas', 1),
('beijo grego', 1),
('fio terra', 1),
('primeira vez', 1),
('massagem', 1),
('cosplay', 1),
('brinquedos', 1),

-- Contexto / Situação
('camera escondida', 1),
('surpresa', 1),
('traicao', 1),
('cornos', 1),
('escondido', 1),
('sextape', 1),
('vinganca', 1),

-- Origem / Formato (a categoria "Vazou" cobre o geral)
('story', 1),
('prive', 1),
('celular', 1),
('vertical', 1),
('ao vivo', 1),
('leak', 1),

-- Relações / Personas (categorias de parentesco ficam no channel)
('casal', 1),
('amigo', 1),
('vizinho', 1),
('entregador', 1),
('patrao', 1),
('colega de trabalho', 1);

SET FOREIGN_KEY_CHECKS = 1;

-- Recontar counters a partir dos vídeos ativos (rodar após popular, sob demanda):
-- UPDATE tags SET counter = (
--   SELECT COUNT(*) FROM video
--   WHERE active = '1'
--     AND keyword REGEXP CONCAT('(^|, )', REPLACE(tag, ' ', ''), '(,|$)')
-- );