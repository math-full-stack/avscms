<?php
/**
 * TrafficStars publisher stats sync — cron.
 *
 * Usage:  * * * * * php /path/to/avs/scripts/ts_sync.php [days]
 *         php scripts/ts_sync.php        # sincroniza os últimos 2 dias
 *         php scripts/ts_sync.php 7      # últimos 7 dias (ex: primeiro seed)
 *
 * Lê os spots ativos de ts_spot_map e faz upsert da receita diária na
 * ts_stats_daily (relatório /v1.1/publisher/custom/report/by-day). A regra
 * "grupo interno -> spot TrafficStars" mora em ts_spot_map (ligada no
 * siteadmin); este script só espelha os números.
 *
 * Fail-closed: sem TS_API_KEY no .env, avisa e sai (0) — não derruba cron.
 * Token OAuth em cache (tmp/ts_token.json, chmod 600), renovado sozinho.
 * Idempotente: o upsert usa a UNIQUE (ts_spot_id, day).
 */

define('_VALID', 1);
define('_CLI', true);
define('_ENTER', true);
define('_CONSOLE', true); // CLI: skip web sessions

$basedir = dirname(dirname(__FILE__));
require_once $basedir . '/include/config.php';
require_once $basedir . '/classes/ts_api.class.php';

$now       = time();
$days      = ( isset($argv[1]) && intval($argv[1]) > 0 ) ? min(intval($argv[1]), 180) : 2;
$apiKey    = getenv('TS_API_KEY');

echo "[".date('Y-m-d H:i:s')."] TrafficStars sync: últimos $days dia(s)\n";

if ( !$apiKey ) {
    echo "[".date('Y-m-d H:i:s')."] TS_API_KEY não configurada no .env. Nada a fazer.\n";
    exit(0);
}

$api = new TsApi($apiKey, $config['TMP_DIR'] . '/ts_token.json');

// 1. Spots ativos mapeados
$sql = "SELECT advgrp_id, ts_spot_id, spot_name FROM ts_spot_map WHERE active = '1'";
$rs  = $conn->execute($sql);
$spots = $rs ? $rs->getrows() : array();

if ( empty($spots) ) {
    echo "[".date('Y-m-d H:i:s')."] Nenhum spot mapeado em ts_spot_map. Ligue os grupos no siteadmin (Settings -> TrafficStars).\n";
    exit(0);
}

$dateFrom = date('Y-m-d', $now - (($days - 1) * 86400));
$dateTo   = date('Y-m-d', $now);

$synced = 0;
$totalAmount = 0.0;

foreach ( $spots as $spot ) {
    $spotId = intval($spot['ts_spot_id']);
    if ( !$spotId ) continue;

    try {
        $rows = $api->getSpotDaily($spotId, $dateFrom, $dateTo);
    } catch ( TsApiException $e ) {
        echo "[".date('Y-m-d H:i:s')."] spot #$spotId ({$spot['spot_name']}) skippado: ".$e->getMessage()."\n";
        continue;
    }

    foreach ( (array) $rows as $row ) {
        if ( empty($row['day']) ) continue;

        $day        = substr($row['day'], 0, 10);
        $impressions = intval($row['impressions'] ?? 0);
        $clicks      = intval($row['clicks'] ?? 0);
        $leads       = intval($row['leads'] ?? 0);
        $amount      = floatval($row['amount'] ?? 0);
        $ctr         = isset($row['ctr'])  ? round(floatval($row['ctr']),  6) : 0;
        $ecpm        = isset($row['ecpm']) ? round(floatval($row['ecpm']), 6) : 0;
        $ecpc        = isset($row['ecpc']) ? round(floatval($row['ecpc']), 6) : 0;
        $ecpa        = isset($row['ecpa']) ? round(floatval($row['ecpa']), 6) : 0;

        $sql = "INSERT INTO ts_stats_daily
                    (ts_spot_id, day, impressions, clicks, leads, amount, ctr, ecpm, ecpc, ecpa, synced_at)
                VALUES
                    (".intval($spotId).", ".$conn->qStr($day).", ".intval($impressions).",
                     ".intval($clicks).", ".intval($leads).", ".$amount." ,
                     ".$ctr.", ".$ecpm.", ".$ecpc.", ".$ecpa.", ".$now.")
                ON DUPLICATE KEY UPDATE
                    impressions = VALUES(impressions),
                    clicks      = VALUES(clicks),
                    leads       = VALUES(leads),
                    amount      = VALUES(amount),
                    ctr         = VALUES(ctr),
                    ecpm        = VALUES(ecpm),
                    ecpc        = VALUES(ecpc),
                    ecpa        = VALUES(ecpa),
                    synced_at   = VALUES(synced_at)";
        $conn->execute($sql);
        $totalAmount += $amount;
        $synced++;
    }
}

echo "[".date('Y-m-d H:i:s')."] Concluído: $synced linha(s) de stats, receita acumulada na janela: \$".number_format($totalAmount, 2, ',', '.')."\n";