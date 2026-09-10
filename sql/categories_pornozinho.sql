-- Categorias para Pornozinho - Conteúdo Adulto Brasileiro (+18)
-- Tabela: channel (categorias de vídeo)
-- Execute: mysql -u root -p avs < sql/categories_pornozinho.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Limpar categorias existentes (opcional - comente se quiser manter)
-- TRUNCATE TABLE `channel`;

INSERT INTO `channel` (`name`, `slug`) VALUES
-- Categorias Principais
('Amador', 'amador'),
('Brasileiras', 'brasileiras'),
('Novinhas +18', 'novinhas-18'),
('Caiu na Net', 'caiu-na-net'),
('Flagras', 'flagras'),
('Vazou', 'vazou'),

-- Por Tipo de Conteúdo
('Anal', 'anal'),
('Oral / Boquete', 'oral-boquete'),
('Gozada / Facial', 'gozada-facial'),
('Creampie', 'creampie'),
('Squirting', 'squirting'),
('DP / Dupla Penetração', 'dp-dupla-penetracao'),
('Gangbang', 'gangbang'),
('Orgias', 'orgias'),
('Lesbicas', 'lesbicas'),
('Trans / Travestis', 'trans-travestis'),

-- Por Estilo / Nicho
('Caseiro', 'caseiro'),
('POV', 'pov'),
('Hardcore', 'hardcore'),
('Softcore', 'softcore'),
('Fetish', 'fetish'),
('BDSM', 'bdsm'),
('Roleplay', 'roleplay'),
('Voyeur', 'voyeur'),
('Exibicionismo', 'exibicionismo'),
('Suruba', 'suruba'),
('Menage', 'menage'),
('Swing', 'swing'),

-- Por Perfil / Atriz
('Morenas', 'morenas'),
('Loiras', 'loiras'),
('Ruivas', 'ruivas'),
('Negras', 'negras'),
('Mulatas', 'mulatas'),
('Gordas / BBW', 'gordas-bbw'),
('Magras', 'magras'),
('Peitudas', 'peitudas'),
('Bundudas', 'bundudas'),
('Fitness / Saradas', 'fitness-saradas'),
('MILF / Coroas', 'milf-coroas'),
('Gordinhas', 'gordinhas'),

-- Por Local / Cenário
('Praia', 'praia'),
('Carro', 'carro'),
('Motel / Hotel', 'motel-hotel'),
('Banheiro', 'banheiro'),
('Cozinha', 'cozinha'),
('Quarto', 'quarto'),
('Escritório', 'escritorio'),
('Academia', 'academia'),
('Festa / Balada', 'festa-balada'),
('Escola / Faculdade', 'escola-faculdade'),
('Trabalho', 'trabalho'),
('Ao Ar Livre', 'ao-ar-livre'),

-- Categorias Brasileiras Específicas
('Funk / Baile Funk', 'funk-baile-funk'),
('Carnaval', 'carnaval'),
('Junina / São João', 'junina-sao-joao'),
('Praia Brasileira', 'praia-brasileira'),
('Favela / Comunidade', 'favelas-comunidade'),
('Interior / Roça', 'interior-roca'),
('Universitárias', 'universitarias'),
('Funcionárias', 'funcionarias'),
('Empregadas', 'empregadas'),
('Vizinhas', 'vizinhas'),
('Primas', 'primas'),
('Cunhadas', 'cunhadas'),
('Madrastas', 'madrastas'),
('Enteadas', 'enteadas'),

-- Por Duração / Formato
('Curto / Quickie', 'curto-quickie'),
('Longa Duração', 'longa-duracao'),
('Compilação', 'compilacao'),
('Full Movie', 'full-movie'),
('Clipes', 'clipes'),

-- Qualidade / Produção
('HD / 4K', 'hd-4k'),
('Amador Real', 'amador-real'),
('Produção Nacional', 'producao-nacional'),
('Webcam', 'webcam'),
('OnlyFans / Privacy', 'onlyfans-privacy'),
('Telegram / Grupos', 'telegram-grupos');

SET FOREIGN_KEY_CHECKS = 1;