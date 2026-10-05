<?php
defined('_VALID') or die('Restricted Access!');

if ($config['video_module'] == '0') {
    die(json_encode(array('status' => 0, 'msg' => 'Video module disabled', 'html' => '', 'has_more' => false)));
}

require_once $config['BASE_DIR'] . '/include/function_video.php';
require_once $config['BASE_DIR'] . '/include/function_thumbs.php';
require_once $config['BASE_DIR'] . '/include/function_smarty.php';
require_once $config['BASE_DIR'] . '/include/smarty/libs/Smarty.class.php';
require_once $config['BASE_DIR'] . '/include/config.language.php';
require $config['BASE_DIR'] . '/include/smarty.php';

$page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
$per_page = max(6, intval($config['items_per_front_page']));
$offset = ($page - 1) * $per_page;

$sql_add = NULL;
if ($config['show_private_videos'] == '0') {
    $sql_add = " AND v.type = 'public'";
}
$sql_add .= " AND v.active = '1'";

// Excluir shorts (portrait + duração < 100s) do feed da home
$sql_add .= " AND NOT (v.duration > 0 AND v.duration < 100 AND v.orientation = 'portrait')";

// Excluir VIDs da seção "Novos" (últimos 7 dias) para evitar repetição
$window_hours = 168;
$novos_vids_sql = " AND v.VID NOT IN (
    SELECT VID FROM video
    WHERE active = '1'" . ($config['show_private_videos'] == '0' ? " AND type = 'public'" : "") . "
    AND NOT (duration > 0 AND duration < 100 AND orientation = 'portrait')
    AND addtime >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $window_hours HOUR))
)";

// Feed único da home: MESMA query e ordenação (score híbrido recência+audiência)
// do index.php — manter em sincronia.
$sql = "SELECT v.VID, v.title, v.duration, v.addtime, v.thumb, v.thumbs, v.thumbnails_opt, v.vthumbs, v.viewnumber, v.rate, v.likes, v.dislikes, v.type, v.hd, v.keyword, v.UID, v.orientation, v.featured, u.username
        FROM video AS v, signup AS u
        WHERE v.UID = u.UID" . $sql_add . $novos_vids_sql . "
        ORDER BY (v.viewnumber / POW(TIMESTAMPDIFF(HOUR, FROM_UNIXTIME(CAST(v.addtime AS UNSIGNED)), NOW()) + 2, 1.5)) DESC, v.addtime DESC, v.VID DESC
        LIMIT " . $offset . ", " . $per_page;
$rs = $conn->execute($sql);
$videos = $rs ? $rs->getrows() : array();

video_apply_cover_rotation($videos);
foreach ( $videos as $k => $v ) {
    $videos[$k]['keywords'] = array_values(array_filter(array_map('trim', explode(',', $v['keyword']))));
}

// Renderiza o MESMO grid da home em uma única fonte de verdade (feed_grid.tpl):
// linhas de 4 cards e faixa de anúncio (index_feed) a cada 3 linhas, sempre
// como IRMÃ da linha — nunca dentro dela. `grid_offset` é a posição global do
// 1º card desta página: mantém as linhas fechando alinhadas com a 1ª página
// (renderizada pelo index.php com o mesmo items_per_front_page).
$band = isset($_REQUEST['band']) ? intval($_REQUEST['band']) : 1;
$smarty->assign('videos', $videos);
$smarty->assign('group', 'index_feed');
$smarty->assign('grid_offset', $offset);
$smarty->assign('grid_band', $band);
$html = count($videos) ? $smarty->fetch('feed_grid.tpl') : '';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array(
    'status' => 1,
    'page' => $page,
    'count' => count($videos),
    'html' => $html,
    'has_more' => count($videos) == $per_page
));
die();