<?php
/**
 * Reaplica a limpeza de metadados (MetadataMerger) nos vídeos que o Mass
 * Grabber já importou antes dela existir.
 *
 * Contexto: antes do MetadataMerger o import gravava `video.keyword` e
 * `video.description` exatamente como vieram do re-fetch - sem lowercase, sem
 * dedup e com a descrição repetindo o título (78% das linhas no banco).
 * Reaplicar a mesma regra deixa o acervo antigo no mesmo padrão do novo, para
 * que a busca por tag (REGEXP delimitado por ", ") e a nuvem não convivam com
 * duas grafias da mesma tag.
 *
 * Escopo deliberado: só linhas com `source_url` preenchido (vídeos vindos do
 * grabber). Não toca em upload/edição manual, onde a descrição é do autor.
 *
 * Uso:
 *   php scripts/grabber_metadata_backfill.php --dry-run   # só mostra o diff
 *   php scripts/grabber_metadata_backfill.php --apply     # grava (com backup)
 *
 * Rollback:
 *   UPDATE video v JOIN grabber_metadata_backup b ON b.VID = v.VID
 *     SET v.keyword = b.keyword, v.description = b.description;
 *   DROP TABLE grabber_metadata_backup;
 */

define('_VALID', 1);
define('_CLI', true);
define('_ENTER', true);
define('_CONSOLE', true);

$basedir = dirname(dirname(__FILE__));
require_once $basedir . '/include/config.php';
require_once $basedir . '/classes/grabbers/mass/MetadataMerger.php';

$args   = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$apply  = in_array('--apply', $args, true);
$backupTable = 'grabber_metadata_backup';

@set_time_limit(0);

echo "Modo: " . ($apply ? "apply (grava no banco)" : "dry-run (nada será alterado)") . "\n";

$rs = $conn->execute("SELECT VID, title, keyword, description FROM video
                      WHERE source_url <> '' AND (keyword <> '' OR description <> '')
                      ORDER BY VID ASC");
if (!$rs) {
    echo "Erro na consulta: " . $conn->ErrorMsg() . "\n";
    exit(1);
}

$rows = $rs->getrows();
echo "Vídeos do grabber com metadados: " . count($rows) . "\n\n";

$changedKw = 0;
$changedDesc = 0;
$shortened = 0;
$plan = array();

foreach ($rows as $row) {
    $vid  = intval($row['VID']);
    $oldKw = (string) $row['keyword'];
    $oldDs = (string) $row['description'];

    $tagInfo = MetadataMerger::mergeTags($oldKw, '');
    $newKw   = $tagInfo['text'];

    $descInfo = MetadataMerger::sanitizeDescription($oldDs, $row['title']);
    $newDs    = $descInfo['text'];

    $kwChanged  = ($newKw !== $oldKw);
    $dsChanged  = ($newDs !== $oldDs);
    if ($descInfo['trimmed']) $shortened++;

    if ($kwChanged) $changedKw++;
    if ($dsChanged) $changedDesc++;

    if (!$kwChanged && !$dsChanged) continue;

    $plan[] = array(
        'VID'         => $vid,
        'title'       => $row['title'],
        'kw_old'      => $oldKw,
        'kw_new'      => $newKw,
        'ds_old'      => $oldDs,
        'ds_new'      => $newDs,
        'ds_trimmed'  => $descInfo['trimmed'] ? 1 : 0,
        'ds_dropped'  => $descInfo['dropped'],
        'kw_changed'  => $kwChanged ? 1 : 0,
        'ds_changed'  => $dsChanged ? 1 : 0,
    );

    echo "VID $vid  " . mb_substr($row['title'], 0, 60) . "\n";
    if ($kwChanged) {
        echo "  keyword: [" . $oldKw . "]\n        -> [" . $newKw . "]\n";
    }
    if ($dsChanged) {
        echo "  desc   : [" . mb_substr($oldDs, 0, 110) . (mb_strlen($oldDs) > 110 ? '...' : '') . "]\n";
        echo "        -> [" . mb_substr($newDs, 0, 110) . (mb_strlen($newDs) > 110 ? '...' : '') . "]\n";
    }
}

echo "\n--------------------------------------------------\n";
echo "keyword alterado: $changedKw | descrição alterada: $changedDesc";
echo " | descrição truncada: $shortened\n";
echo "linhas no plano: " . count($plan) . "\n";

if (!$apply) {
    echo "\nDry-run. Nada foi gravado. Use --apply para gravar.\n";
    exit(0);
}

if (!$plan) {
    echo "\nNada a fazer.\n";
    exit(0);
}

// ---- backup antes de escrever -------------------------------------------
$conn->Execute("CREATE TABLE IF NOT EXISTS " . $backupTable . " (
    VID bigint(20) NOT NULL,
    keyword text NOT NULL,
    description text NOT NULL,
    backed_up_at int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (VID)
) ENGINE=MyISAM DEFAULT CHARSET=utf8");

$conn->Execute("DELETE FROM " . $backupTable);
foreach ($plan as $p) {
    $conn->Execute("INSERT INTO " . $backupTable . " (VID, keyword, description, backed_up_at)
                    VALUES (" . intval($p['VID']) . ", " . $conn->qStr($p['kw_old']) . ", "
                    . $conn->qStr($p['ds_old']) . ", " . time() . ")");
}

$applied = 0;
foreach ($plan as $p) {
    $sets = array();
    if ($p['kw_changed']) $sets[] = "keyword = " . $conn->qStr($p['kw_new']);
    if ($p['ds_changed']) $sets[] = "description = " . $conn->qStr($p['ds_new']);
    if (!$sets) continue;
    $conn->Execute("UPDATE video SET " . implode(', ', $sets) . " WHERE VID = " . intval($p['VID']) . " LIMIT 1");
    $applied++;
}

echo "\nBackup em `" . $backupTable . "` (" . count($plan) . " linhas).\n";
echo "Gravadas: $applied\n";
echo "Rode agora: php scripts/cleanup_tags.php --apply  (rebuild da tabela `tags`)\n";
