<?php
/**
 * Category seed data for adulto.cloud
 * Hierarchical structure: Parent -> Children
 * Use with: include/seed_categories.php or admin import
 */

return [
    // PARENTS (top-level categories)
    'parents' => [
        'amador' => [
            'name' => 'Amador',
            'slug' => 'amador',
            'sort_order' => 10,
            'is_featured' => true,
            'children' => [
                'caseiro' => ['name' => 'Caseiro', 'slug' => 'caseiro', 'sort_order' => 10, 'is_featured' => true],
                'celular' => ['name' => 'Celular', 'slug' => 'celular', 'sort_order' => 20, 'is_featured' => true],
                'webcam' => ['name' => 'Webcam', 'slug' => 'webcam', 'sort_order' => 30, 'is_featured' => true],
                'vazou-caiu-na-net' => ['name' => 'Vazou / Caiu na Net', 'slug' => 'vazou-caiu-na-net', 'sort_order' => 40, 'is_featured' => true],
                'onlyfans-privacy' => ['name' => 'OnlyFans / Privacy', 'slug' => 'onlyfans-privacy', 'sort_order' => 50, 'is_featured' => true],
                'amador-real' => ['name' => 'Amador Real', 'slug' => 'amador-real', 'sort_order' => 60, 'is_featured' => false],
            ]
        ],
        'regiao' => [
            'name' => 'Região',
            'slug' => 'regiao',
            'sort_order' => 20,
            'is_featured' => true,
            'children' => [
                'sudeste' => ['name' => 'Sudeste', 'slug' => 'sudeste', 'sort_order' => 10, 'is_featured' => true],
                'sul' => ['name' => 'Sul', 'slug' => 'sul', 'sort_order' => 20, 'is_featured' => true],
                'nordeste' => ['name' => 'Nordeste', 'slug' => 'nordeste', 'sort_order' => 30, 'is_featured' => true],
                'norte' => ['name' => 'Norte', 'slug' => 'norte', 'sort_order' => 40, 'is_featured' => true],
                'centro-oeste' => ['name' => 'Centro-Oeste', 'slug' => 'centro-oeste', 'sort_order' => 50, 'is_featured' => true],
            ]
        ],
        'perfil' => [
            'name' => 'Perfil',
            'slug' => 'perfil',
            'sort_order' => 30,
            'is_featured' => true,
            'children' => [
                'casal-amador' => ['name' => 'Casal Amador', 'slug' => 'casal-amador', 'sort_order' => 10, 'is_featured' => true],
                'novinha-18' => ['name' => 'Novinha +18', 'slug' => 'novinha-18', 'sort_order' => 20, 'is_featured' => true],
                'milf-coroas' => ['name' => 'MILF / Coroas', 'slug' => 'milf-coroas', 'sort_order' => 30, 'is_featured' => true],
                'bbw-gordinhas' => ['name' => 'BBW / Gordinhas', 'slug' => 'bbw-gordinhas', 'sort_order' => 40, 'is_featured' => true],
                'negras-mulatas' => ['name' => 'Negras / Mulatas', 'slug' => 'negras-mulatas', 'sort_order' => 50, 'is_featured' => true],
                'trans-travestis-amador' => ['name' => 'Trans / Travestis Amador', 'slug' => 'trans-travestis-amador', 'sort_order' => 60, 'is_featured' => true],
                'gay-amador' => ['name' => 'Gay Amador', 'slug' => 'gay-amador', 'sort_order' => 70, 'is_featured' => true],
                'lesbicas-amador' => ['name' => 'Lésbicas Amador', 'slug' => 'lesbicas-amador', 'sort_order' => 80, 'is_featured' => true],
                'loiras' => ['name' => 'Loiras', 'slug' => 'loiras', 'sort_order' => 90, 'is_featured' => false],
                'morenas' => ['name' => 'Morenas', 'slug' => 'morenas', 'sort_order' => 100, 'is_featured' => false],
                'ruivas' => ['name' => 'Ruivas', 'slug' => 'ruivas', 'sort_order' => 110, 'is_featured' => false],
                'fitness-saradas' => ['name' => 'Fitness / Saradas', 'slug' => 'fitness-saradas', 'sort_order' => 120, 'is_featured' => false],
                'tatuadas' => ['name' => 'Tatuadas', 'slug' => 'tatuadas', 'sort_order' => 130, 'is_featured' => false],
                'peitudas' => ['name' => 'Peitudas', 'slug' => 'peitudas', 'sort_order' => 140, 'is_featured' => false],
                'bundudas' => ['name' => 'Bundudas', 'slug' => 'bundudas', 'sort_order' => 150, 'is_featured' => false],
            ]
        ],
        'cenario' => [
            'name' => 'Cenário',
            'slug' => 'cenario',
            'sort_order' => 40,
            'is_featured' => true,
            'children' => [
                'motel-hotel' => ['name' => 'Motel / Hotel', 'slug' => 'motel-hotel', 'sort_order' => 10, 'is_featured' => true],
                'carro' => ['name' => 'Carro', 'slug' => 'carro', 'sort_order' => 20, 'is_featured' => true],
                'publico-ao-ar-livre' => ['name' => 'Público / Ao Ar Livre', 'slug' => 'publico-ao-ar-livre', 'sort_order' => 30, 'is_featured' => true],
                'banheiro-chuveiro' => ['name' => 'Banheiro / Chuveiro', 'slug' => 'banheiro-chuveiro', 'sort_order' => 40, 'is_featured' => true],
                'festa-balada' => ['name' => 'Festa / Balada', 'slug' => 'festa-balada', 'sort_order' => 50, 'is_featured' => true],
                'trabalho-escritorio' => ['name' => 'Trabalho / Escritório', 'slug' => 'trabalho-escritorio', 'sort_order' => 60, 'is_featured' => true],
                'academia-vestiario' => ['name' => 'Academia / Vestiário', 'slug' => 'academia-vestiario', 'sort_order' => 70, 'is_featured' => true],
                'faculdade-escola' => ['name' => 'Faculdade / Escola', 'slug' => 'faculdade-escola', 'sort_order' => 80, 'is_featured' => true],
                'quarto-cama' => ['name' => 'Quarto / Cama', 'slug' => 'quarto-cama', 'sort_order' => 90, 'is_featured' => false],
                'cozinha' => ['name' => 'Cozinha', 'slug' => 'cozinha', 'sort_order' => 100, 'is_featured' => false],
                'praia' => ['name' => 'Praia', 'slug' => 'praia', 'sort_order' => 110, 'is_featured' => false],
            ]
        ],
        'pratica' => [
            'name' => 'Prática',
            'slug' => 'pratica',
            'sort_order' => 50,
            'is_featured' => true,
            'children' => [
                'anal-amador' => ['name' => 'Anal Amador', 'slug' => 'anal-amador', 'sort_order' => 10, 'is_featured' => true],
                'oral-boquete' => ['name' => 'Oral / Boquete', 'slug' => 'oral-boquete', 'sort_order' => 20, 'is_featured' => true],
                'gozada-facial' => ['name' => 'Gozada / Facial', 'slug' => 'gozada-facial', 'sort_order' => 30, 'is_featured' => true],
                'creampie-gozada-dentro' => ['name' => 'Creampie / Gozada Dentro', 'slug' => 'creampie-gozada-dentro', 'sort_order' => 40, 'is_featured' => true],
                'squirting' => ['name' => 'Squirting', 'slug' => 'squirting', 'sort_order' => 50, 'is_featured' => true],
                'menage-suruba' => ['name' => 'Ménage / Suruba', 'slug' => 'menage-suruba', 'sort_order' => 60, 'is_featured' => true],
                'dp-dupla-penetracao' => ['name' => 'DP / Dupla Penetração', 'slug' => 'dp-dupla-penetracao', 'sort_order' => 70, 'is_featured' => true],
                'pegging-amador' => ['name' => 'Pegging Amador', 'slug' => 'pegging-amador', 'sort_order' => 80, 'is_featured' => false],
                'footjob-pes' => ['name' => 'Footjob / Pés', 'slug' => 'footjob-pes', 'sort_order' => 90, 'is_featured' => false],
            ]
        ],
        'formato' => [
            'name' => 'Formato',
            'slug' => 'formato',
            'sort_order' => 60,
            'is_featured' => false,
            'children' => [
                'curto-quickie' => ['name' => 'Curto / Quickie (<5min)', 'slug' => 'curto-quickie', 'sort_order' => 10, 'is_featured' => false],
                'medio-5-15min' => ['name' => 'Médio (5-15min)', 'slug' => 'medio-5-15min', 'sort_order' => 20, 'is_featured' => false],
                'longo-15min' => ['name' => 'Longo (>15min)', 'slug' => 'longo-15min', 'sort_order' => 30, 'is_featured' => false],
                'vertical-reels-shorts' => ['name' => 'Vertical / Reels / Shorts', 'slug' => 'vertical-reels-shorts', 'sort_order' => 40, 'is_featured' => true],
                'compilacao' => ['name' => 'Compilação', 'slug' => 'compilacao', 'sort_order' => 50, 'is_featured' => false],
                'full-movie' => ['name' => 'Full Movie', 'slug' => 'full-movie', 'sort_order' => 60, 'is_featured' => false],
            ]
        ],
        'qualidade' => [
            'name' => 'Qualidade',
            'slug' => 'qualidade',
            'sort_order' => 70,
            'is_featured' => false,
            'children' => [
                'hd-4k' => ['name' => 'HD / 4K', 'slug' => 'hd-4k', 'sort_order' => 10, 'is_featured' => false],
                'producao-nacional' => ['name' => 'Produção Nacional', 'slug' => 'producao-nacional', 'sort_order' => 20, 'is_featured' => false],
                'webcam' => ['name' => 'Webcam', 'slug' => 'webcam', 'sort_order' => 30, 'is_featured' => false],
            ]
        ],
    ],

    // Flat list for easy iteration (parent + all children)
    'flat' => [
        // Parents
        ['slug' => 'amador', 'name' => 'Amador', 'parent_id' => 0, 'sort_order' => 10, 'is_featured' => 1],
        ['slug' => 'regiao', 'name' => 'Região', 'parent_id' => 0, 'sort_order' => 20, 'is_featured' => 1],
        ['slug' => 'perfil', 'name' => 'Perfil', 'parent_id' => 0, 'sort_order' => 30, 'is_featured' => 1],
        ['slug' => 'cenario', 'name' => 'Cenário', 'parent_id' => 0, 'sort_order' => 40, 'is_featured' => 1],
        ['slug' => 'pratica', 'name' => 'Prática', 'parent_id' => 0, 'sort_order' => 50, 'is_featured' => 1],
        ['slug' => 'formato', 'name' => 'Formato', 'parent_id' => 0, 'sort_order' => 60, 'is_featured' => 0],
        ['slug' => 'qualidade', 'name' => 'Qualidade', 'parent_id' => 0, 'sort_order' => 70, 'is_featured' => 0],
    ],

    // Legacy mapping: old category slug -> new child slug
    // Use to migrate existing video.channel values
    'legacy_map' => [
        'amador' => 'amador/caseiro',
        'brasileiras' => 'perfil', // parent, will need tag refinement
        'caiu-na-net' => 'amador/vazou-caiu-na-net',
        'flagras' => 'amador/vazou-caiu-na-net',
        'vazou' => 'amador/vazou-caiu-na-net',
        'loiras' => 'perfil/loiras',
        'morenas' => 'perfil/morenas',
        'ruivas' => 'perfil/ruivas',
        'negras' => 'perfil/negras-mulatas',
        'mulatas' => 'perfil/negras-mulatas',
        'asiaticas' => 'perfil', // no direct child, use tags
        'magrinhas' => 'perfil', // no direct child
        'gordinhas' => 'perfil/bbw-gordinhas',
        'gordas-bbw' => 'perfil/bbw-gordinhas',
        'peitudas' => 'perfil/peitudas',
        'bundudas' => 'perfil/bundudas',
        'fitness-saradas' => 'perfil/fitness-saradas',
        'loira-de-silicone' => 'perfil/loiras', // approximate
        'baixinhas' => 'perfil', // no direct child
        'altas-amazona' => 'perfil', // no direct child
        'novinhas-18' => 'perfil/novinha-18',
        'milf-coroas' => 'perfil/milf-coroas',
        'emo-gotica' => 'perfil/tatuadas', // approximate
        'tatuadas' => 'perfil/tatuadas',
        'de-oculos' => 'perfil', // no direct child
        'platinadas' => 'perfil/loiras',
        'cabelo-colorido' => 'perfil', // no direct child
        'pes-sapatinho' => 'pratica/footjob-pes',
        'anal' => 'pratica/anal-amador',
        'oral-boquete' => 'pratica/oral-boquete',
        'gozada-facial' => 'pratica/gozada-facial',
        'creampie' => 'pratica/creampie-gozada-dentro',
        'squirting' => 'pratica/squirting',
        'dp-dupla-penetracao' => 'pratica/dp-dupla-penetracao',
        'gangbang' => 'pratica/menage-suruba',
        'orgias' => 'pratica/menage-suruba',
        'lesbicas' => 'perfil/lesbicas-amador',
        'trans-travestis' => 'perfil/trans-travestis-amador',
        'caseiro' => 'amador/caseiro',
        'pov' => 'amador/caseiro', // POV is usually caseiro
        'hardcore' => 'pratica/anal-amador', // approximate
        'softcore' => 'amador/caseiro',
        'fetish' => 'pratica/footjob-pes', // approximate
        'bdsm' => 'pratica/pegging-amador', // approximate
        'roleplay' => 'cenario/faculdade-escola', // approximate
        'voyeur' => 'amador/vazou-caiu-na-net',
        'exibicionismo' => 'amador/webcam',
        'suruba' => 'pratica/menage-suruba',
        'menage' => 'pratica/menage-suruba',
        'swing' => 'pratica/menage-suruba',
        'praia' => 'cenario/praia',
        'carro' => 'cenario/carro',
        'motel-hotel' => 'cenario/motel-hotel',
        'banheiro' => 'cenario/banheiro-chuveiro',
        'cozinha' => 'cenario/cozinha',
        'quarto' => 'cenario/quarto-cama',
        'escritorio' => 'cenario/trabalho-escritorio',
        'academia' => 'cenario/academia-vestiario',
        'festa-balada' => 'cenario/festa-balada',
        'escola-faculdade' => 'cenario/faculdade-escola',
        'trabalho' => 'cenario/trabalho-escritorio',
        'ao-ar-livre' => 'cenario/publico-ao-ar-livre',
        'funk-baile-funk' => 'cenario/festa-balada', // approximate
        'carnaval' => 'cenario/festa-balada',
        'junina-sao-joao' => 'cenario/festa-balada',
        'praia-brasileira' => 'cenario/praia',
        'favela-comunidade' => 'cenario/publico-ao-ar-livre',
        'interior-roca' => 'cenario/publico-ao-ar-livre',
        'universitarias' => 'cenario/faculdade-escola',
        'funcionarias' => 'cenario/trabalho-escritorio',
        'empregadas' => 'cenario/trabalho-escritorio',
        'vizinhas' => 'cenario/quarto-cama',
        'primas' => 'perfil', // taboo, consider tags
        'cunhadas' => 'perfil',
        'madrastas' => 'perfil/milf-coroas',
        'enteadas' => 'perfil/novinha-18',
        'sogra' => 'perfil/milf-coroas',
        'curto-quickie' => 'formato/curto-quickie',
        'longa-duracao' => 'formato/longo-15min',
        'compilacao' => 'formato/compilacao',
        'full-movie' => 'formato/full-movie',
        'clipes' => 'formato/curto-quickie',
        'hd-4k' => 'qualidade/hd-4k',
        'amador-real' => 'amador/amador-real',
        'producao-nacional' => 'qualidade/producao-nacional',
        'webcam' => 'amador/webcam',
        'onlyfans-privacy' => 'amador/onlyfans-privacy',
        'telegram-grupos' => 'amador/vazou-caiu-na-net',
    ],
];

// Helper: get all category slugs (for validation)
function getAllCategorySlugs(array $seed): array
{
    $slugs = [];
    foreach ($seed['parents'] as $parent) {
        $slugs[] = $parent['slug'];
        foreach ($parent['children'] as $child) {
            $slugs[] = $parent['slug'] . '/' . $child['slug'];
        }
    }
    return $slugs;
}

// Helper: get child categories for a parent
function getChildrenForParent(array $seed, string $parentSlug): array
{
    return $seed['parents'][$parentSlug]['children'] ?? [];
}

// Helper: find parent slug for a child slug
function findParentForChild(array $seed, string $childSlug): ?string
{
    foreach ($seed['parents'] as $parentSlug => $parent) {
        if (isset($parent['children'][$childSlug])) {
            return $parentSlug;
        }
    }
    return null;
}