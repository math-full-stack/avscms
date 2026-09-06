<?php
/**
 * Reconstroi a tabela `tags` a partir das referências reais de conteúdo.
 *
 * O propósito é eliminar tags órfãs (que não estão mais em uso) e recalcular
 * os contadores com base no que está realmente nos conteúdos:
 *   - video.keyword  (separado por vírgula)
 *   - albums.tags    (separado por espaço)
 *   - game.tags      (separado por espaço)
 *
 * Fluxo:
 *   1. Apaga todos os registros da tabela `tags`.
 *   2. Varre cada conteúdo e re-adiciona cada tag (counter = uso real).
 *   3. Relata quantas tags únicas permaneceram e quantos conteúdos foram lidos.
 *
 * Uso:
 *   php scripts/cleanup_tags.php            # executa (constrói a partir do zero)
 *   php scripts/cleanup_tags.php --dry-run  # apenas mostra o que seria feito
 *
 * Seguro de rodar via cron periodicamente:
 *   0 * * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/avscms/scripts/cleanup_tags.php --cron >> /path/to/log 2>&1
 */

define('_VALID', 1);
define('_ENTER', true);
define('_CLI', true);

$basedir = dirname(dirname(__FILE__));
require $basedir . '/include/config.php';
require $basedir . '/include/function_global.php';

$args   = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$dryRun = in_array('--dry-run', $args, true);
$cron   = in_array('--cron', $args, true);

// Single-instance guard: prediction/overlap de duas execuções (web+cron,
// cron duas vezes no mesmo tick) nunca pode reconstruir a tabela ao mesmo tempo.
if ( $cron ) {
    $lockFile = $config['LOG_DIR'] . '/cleanup_tags.lock';
    $lockH = @fopen($lockFile, 'c');
    if ($lockH && !flock($lockH, LOCK_EX | LOCK_NB)) {
        echo "[" . date('Y-m-d H:i:s') . "] Outra instância de cleanup_tags já está rodando - ignorando.\n";
        exit(0);
    }
}

echo "Modo: " . ($dryRun ? "dry-run (nada será alterado)" : "execução (reconstrói a tabela)") . "\n";

// ---------------------------------------------------------------------------
// 1) Coleta de pares (tipo, tags_raw) existentes no banco
// ---------------------------------------------------------------------------
$items = array();

$tables = array();
$rs     = $conn->execute("SHOW TABLES");
foreach ( $rs->getrows() as $row ) {
    $tables[] = array_shift($row);
}

if ( in_array('video', $tables) ) {
    $sql = "SELECT keyword FROM video WHERE keyword IS NOT NULL AND keyword != ''";
    $rs  = $conn->execute($sql);
    if ( $conn->Affected_Rows() > 0 ) {
        foreach ( $rs->getrows() as $row ) {
            $items[] = array('video', $row['keyword']);
        }
    }
} else {
    echo "Tabela video inexistente.\n";
}


if ( in_array('albums', $tables) ) {
    $sql = "SELECT tags FROM albums WHERE tags IS NOT NULL AND tags != ''";
    $rs  = $conn->execute($sql);
    if ( $conn->Affected_Rows() > 0 ) {
        foreach ( $rs->getrows() as $row ) {
            $items[] = array('album', $row['tags']);
        }
    }
} else {
    echo "Tabela albums inexistente.\n";
}

if ( in_array('game', $tables) ) {
    $sql = "SELECT tags FROM game WHERE tags IS NOT NULL AND tags != ''";
    $rs  = $conn->execute($sql);
    if ( $conn->Affected_Rows() > 0 ) {
        foreach ( $rs->getrows() as $row ) {
            $items[] = array('game', $row['tags']);
        }
    }
} else {
    echo "Tabela game inexistente ou sem tags.\n";
}

$totalItems = count($items);
echo "\nTotal de conteúdos com tags: " . $totalItems . "\n";

// ---------------------------------------------------------------------------
// 2) Em modo execução: apaga todas as tags e reconstrói
// ---------------------------------------------------------------------------
if ( !$dryRun ) {
    $conn->execute("DELETE FROM tags");

    $added = 0;
    foreach ( $items as $item ) {
        list($type, $raw) = $item;
        $comma = ( $type === 'video' ) ? $raw : tags_to_comma($raw);
        add_tags($comma);
        ++$added;
    }
    echo "Tags re-adicionadas a partir de " . $added . " conteúdos.\n";
} else {
    echo "Dry-run: mostraria a reconstrução a partir de " . $totalItems . " conteúdos.\n";
}

// ---------------------------------------------------------------------------
// 3) Relatório final
// ---------------------------------------------------------------------------
$sql = "SELECT COUNT(*) AS total, COALESCE(SUM(counter), 0) AS total_count FROM tags";
$rs  = $conn->execute($sql);
$row = $rs->fields;
echo "\nEstado da tabela tags: " . $row['total'] . " tags únicas, " . $row['total_count'] . " usos totais.\n";
?>