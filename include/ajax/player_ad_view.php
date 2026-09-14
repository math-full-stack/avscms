<?php
defined('_VALID') or die('Restricted Access!');

require_once $config['BASE_DIR'] . '/include/function_player_ads.php';

// Metrica de exibicao: o player reporta quando o anuncio realmente comeca a
// aparecer (preroll/midroll/pause/postroll/overlay) — nunca chamado no load da
// pagina de video, entao nao infla com bounce.
$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
$ok = player_ads_track($id, 'view');

header('Content-Type: application/json; charset=utf-8');
die(json_encode(array('status' => $ok ? 1 : 0)));