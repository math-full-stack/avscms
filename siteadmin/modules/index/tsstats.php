<?php
defined('_VALID') or die('Restricted Access!');

Auth::checkAdmin();
require_once $config['BASE_DIR']. '/classes/ts_api.class.php';

// ---------------------------------------------------------------------------
// Ações
// ---------------------------------------------------------------------------
if ( isset($_POST['ts_link']) ) {
    $advgrp_id  = isset($_POST['advgrp_id'])  ? intval($_POST['advgrp_id'])  : 0;
    $ts_spot_id = isset($_POST['ts_spot_id']) ? intval($_POST['ts_spot_id']) : 0;
    $spot_name  = isset($_POST['spot_name'])  ? trim($_POST['spot_name'])    : '';
    $active     = ( isset($_POST['active']) && $_POST['active'] == '1' ) ? '1' : '0';

    if ( !$advgrp_id ) {
        $errors[] = 'Selecione um grupo de anúncio (slot interno).';
    } elseif ( !$ts_spot_id ) {
        $errors[] = 'Informe o ID do spot na TrafficStars.';
    } elseif ( $spot_name == '' ) {
        $errors[] = 'Informe um nome para o spot (ex.: index_feed).';
    }

    if ( !$errors ) {
        $sql = "INSERT INTO ts_spot_map (advgrp_id, ts_spot_id, spot_name, active, created_at, updated_at)
                VALUES (".$advgrp_id.", ".$ts_spot_id.", ".$conn->qStr($spot_name).", '".$active."', ".time().", ".time().")
                ON DUPLICATE KEY UPDATE
                    ts_spot_id = VALUES(ts_spot_id),
                    spot_name  = VALUES(spot_name),
                    active     = VALUES(active),
                    updated_at = VALUES(updated_at)";
        $conn->execute($sql);
        $messages[] = 'Spot vinculado ao grupo '.$advgrp_id.'!';
    }
}

if ( isset($_GET['a']) && $_GET['a'] == 'delete' && isset($_GET['ID']) && is_numeric($_GET['ID']) ) {
    $sql = "DELETE FROM ts_spot_map WHERE id = ".intval($_GET['ID'])." LIMIT 1";
    $conn->execute($sql);
    $messages[] = 'Vínculo removido!';
}

if ( isset($_GET['a']) && ( $_GET['a'] == 'activate' || $_GET['a'] == 'suspend' ) && isset($_GET['ID']) && is_numeric($_GET['ID']) ) {
    $status = ( $_GET['a'] == 'activate' ) ? '1' : '0';
    $sql = "UPDATE ts_spot_map SET active = '".$status."', updated_at = ".time()." WHERE id = ".intval($_GET['ID'])." LIMIT 1";
    $conn->execute($sql);
    $messages[] = 'Spot '.( $status == '1' ? 'ativado' : 'suspenso' ).'!';
}

// ---------------------------------------------------------------------------
// Dados da tela
// ---------------------------------------------------------------------------

// Grupos (slots internos) disponíveis para vincular
$sql = "SELECT advgrp_id, advgrp_name, adv_width, adv_height FROM adv_group ORDER BY advgrp_name ASC";
$rs = $conn->execute($sql);
$groups = $rs ? $rs->getrows() : array();

// Vínculos + stats resumo (hoje e últimos 30 dias)
$sql = "SELECT m.id, m.advgrp_id, m.ts_spot_id, m.spot_name, m.active,
               g.advgrp_name AS group_name,
               d.impressions AS today_impressions, d.clicks AS today_clicks,
               d.leads AS today_leads, d.amount AS today_amount
        FROM ts_spot_map AS m
        LEFT JOIN adv_group AS g ON g.advgrp_id = m.advgrp_id
        LEFT JOIN ts_stats_daily AS d ON d.ts_spot_id = m.ts_spot_id
            AND d.day = DATE_FORMAT(NOW(), '%Y-%m-%d')
        ORDER BY g.advgrp_name ASC";
$rs = $conn->execute($sql);
$links = $rs ? $rs->getrows() : array();

// Totais por vínculo nos últimos 30 dias
$sql = "SELECT ts_spot_id, SUM(impressions) AS p_impressions, SUM(clicks) AS p_clicks,
               SUM(leads) AS p_leads, ROUND(SUM(amount), 2) AS p_amount
        FROM ts_stats_daily
        WHERE day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY ts_spot_id";
$rs = $conn->execute($sql);
$period = array();
if ( $rs ) {
    foreach ( $rs->getrows() as $r ) {
        $period[intval($r['ts_spot_id'])] = $r;
    }
}

// Saldo do publisher (com chave configurada; sem chave fica o alerta)
$ts_balance = NULL;
if ( getenv('TS_API_KEY') ) {
    try {
        $api = new TsApi(getenv('TS_API_KEY'), $config['TMP_DIR']. '/ts_token.json');
        $bal = $api->getBalance();
        $ts_balance = round(floatval($bal['balance']), 2);
    } catch ( Exception $e ) {
        $ts_balance = NULL;
    }
}

$smarty->assign('ts_groups', $groups);
$smarty->assign('ts_links', $links);
$smarty->assign('ts_period', $period);
$smarty->assign('ts_balance', $ts_balance);
$smarty->assign('ts_key_configured', (bool) getenv('TS_API_KEY'));
?>