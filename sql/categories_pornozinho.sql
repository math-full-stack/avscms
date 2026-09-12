-- Categorias para Pornozinho - Conteúdo Adulto Brasileiro (+18)
-- Tabela: channel (categorias de vídeo)
-- Execute: mysql -u root -p avs < sql/categories_pornozinho.sql
--
-- ATENÇÃO: recriação completa. Descomente o TRUNCATE abaixo para apagar as
-- categorias atuais antes de inserir. Isso renumera os CHID (auto-increment) e
-- deixa vídeos já cadastrados órfãos (channel apontando para IDs antigos).
-- Use apenas se ainda não há vídeos atribuídos ou se aceita o impacto.

SET NAMES utf8;
SET FOREIGN_KEY_CHECKS = 0;

-- Limpar categorias existentes (destrutivo - descomente se quiser recriar de zero)
-- TRUNCATE TABLE `channel`;

INSERT INTO `channel` (`name`, `slug`) VALUES
-- Principais / Momento
('Amador', 'amador'),
('Brasileiras', 'brasileiras'),
('Caiu na Net', 'caiu-na-net'),
('Flagras', 'flagras'),
('Vazou', 'vazou'),

-- Perfil / Aparência
('Loiras', 'loiras'),
('Morenas', 'morenas'),
('Ruivas', 'ruivas'),
('Negras', 'negras'),
('Mulatas', 'mulatas'),
('Asiáticas', 'asiaticas'),
('Magrinhas', 'magrinhas'),
('Gordinhas', 'gordinhas'),
('Gordas / BBW', 'gordas-bbw'),
('Peitudas', 'peitudas'),
('Bundudas', 'bundudas'),
('Fitness / Saradas', 'fitness-saradas'),
('Loira de Silicone', 'loira-de-silicone'),
('Baixinhas', 'baixinhas'),
('Altas / Amazona', 'altas-amazona'),
('Novinhas +18', 'novinhas-18'),
('MILF / Coroas', 'milf-coroas'),
('Emo / Gótica', 'emo-gotica'),
('Tatuadas', 'tatuadas'),
('De Óculos', 'de-oculos'),
('Platinadas', 'platinadas'),
('Cabelo Colorido', 'cabelo-colorido'),
('Pés / Sapatinho', 'pes-sapatinho'),

-- Por Tipo de Conteúdo
('Anal', 'anal'),
('Oral / Boquete', 'oral-boquete'),
('Gozada / Facial', 'gozada-facial'),
('Creampie', 'creampie'),
('Squirting', 'squirting'),
('DP / Dupla Penetração', 'dp-dupla-penetracao'),
('Gangbang', 'gangbang'),
('Orgias', 'orgias'),
('Lésbicas', 'lesbicas'),
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

-- Por Cenário / Local
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

-- Brasileiras Específicas
('Funk / Baile Funk', 'funk-baile-funk'),
('Carnaval', 'carnaval'),
('Junina / São João', 'junina-sao-joao'),
('Praia Brasileira', 'praia-brasileira'),
('Favela / Comunidade', 'favela-comunidade'),
('Interior / Roça', 'interior-roca'),
('Universitárias', 'universitarias'),
('Funcionárias', 'funcionarias'),
('Empregadas', 'empregadas'),
('Vizinhas', 'vizinhas'),
('Primas', 'primas'),
('Cunhadas', 'cunhadas'),
('Madrastas', 'madrastas'),
('Enteadas', 'enteadas'),
('Sogra', 'sogra'),

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