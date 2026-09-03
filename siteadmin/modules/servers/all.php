<?php
defined('_VALID') or die('Restricted Access!');
Auth::checkAdmin();

require_once $config['BASE_DIR'] . '/classes/pagination.class.php';
require_once $config['BASE_DIR'] . '/classes/vpagination.class.php';

// Tratar exclusão via GET se houver
if (isset($_GET['a']) && $_GET['a'] == 'delete') {
    $sid = isset($_GET['SID']) ? intval($_GET['SID']) : 0;
    if ($sid > 0) {
        $sql = "DELETE FROM servers WHERE server_id = " . $sid . " LIMIT 1";
        $conn->execute($sql);
        $messages[] = 'Servidor ID <b>' . $sid . '</b> excluído com sucesso!';
    }
}

// Contagem total de servidores
$sql         = "SELECT COUNT(*) AS total_servers FROM servers";
$rs          = $conn->execute($sql);
$total_servers = intval($rs->fields['total_servers']);

$pagination = new VPagination();
$page       = (isset($_GET['page']) && is_numeric($_GET['page'])) ? intval($_GET['page']) : 1;
$limit      = (isset($_GET['limit']) && is_numeric($_GET['limit'])) ? intval($_GET['limit']) : 20;
$paging     = $pagination->getPagination($total_servers, $limit, $page, '&m=all');

$offset     = ($page - 1) * $limit;
$sql        = "SELECT * FROM servers ORDER BY server_id ASC LIMIT " . $offset . ", " . $limit;
$rs         = $conn->execute($sql);
$servers    = $rs->getrows();

// Obter contagem de vídeos associados a cada servidor
if ($servers) {
    foreach ($servers as $k => $srv) {
        $video_url = $srv['video_url'];
        if (!empty($video_url)) {
            $sql_count = "SELECT COUNT(*) AS total FROM video WHERE server = " . $conn->qStr($video_url);
            $rs_count  = $conn->execute($sql_count);
            $servers[$k]['total_videos'] = intval($rs_count->fields['total']);
        } else {
            $servers[$k]['total_videos'] = 0;
        }
    }
}

$smarty->assign('servers', $servers);
$smarty->assign('total_servers', $total_servers);
$smarty->assign('paging', $paging);
$smarty->assign('page', $page);
?>
