<?php
define('_VALID', true);
require 'include/config.php';
require 'include/function_global.php';
require 'include/function_smarty.php';
require_once $config['BASE_DIR'] . '/include/function_thumbs.php';
require_once $config['BASE_DIR'] . '/include/function_video.php';
require_once $config['BASE_DIR'] . '/include/function_server.php';

if ($config['video_module'] == '0') {
    VRedirect::go($config['BASE_URL'] . '/notfound/video_module_disabled');
}

// Suporte a deep linking: /shorts?v=123 ou /shorts/123
$initial_vid = 0;
if (isset($_GET['v']) && intval($_GET['v']) > 0) {
    $initial_vid = intval($_GET['v']);
} else {
    $arg = get_request_arg('shorts', 'INTEGER');
    if ($arg && intval($arg) > 0) {
        $initial_vid = intval($arg);
    }
}

$tab = isset($_GET['tab']) ? strtolower(trim($_GET['tab'])) : 'foryou';
if (!in_array($tab, array('foryou', 'trending', 'recent'))) {
    $tab = 'foryou';
}

$uid = isset($_SESSION['uid']) ? intval($_SESSION['uid']) : 0;
$active_cond = ($config['approve'] == '1') ? " AND v.active = '1'" : "";

// Função para formatar contadores de shorts
function shorts_fmt_num($num) {
    $num = intval($num);
    if ($num >= 1000000) return round($num / 1000000, 1) . 'M';
    if ($num >= 1000) return round($num / 1000, 1) . 'K';
    return (string)$num;
}

// Função para formatar duração
function shorts_fmt_duration($sec) {
    $sec = intval($sec);
    return sprintf('%d:%02d', floor($sec / 60), $sec % 60);
}

// Obter resolução padrão definida no painel admin (tabela player)
$default_res = 'high';
$sql_p = "SELECT resolution FROM player WHERE profile = 'Main' LIMIT 1";
$rs_p = $conn->execute($sql_p);
if ($rs_p && !$rs_p->EOF && !empty($rs_p->fields['resolution'])) {
    $default_res = strtolower(trim($rs_p->fields['resolution']));
}

// Função para selecionar URL pela qualidade definida no admin
function shorts_select_url($sources, $default_res = 'high') {
    if (empty($sources['files'])) {
        if (!empty($sources['iphone_url']) && ($default_res === 'low' || empty($sources['hd_url']))) {
            return $sources['iphone_url'];
        }
        if (!empty($sources['hd_url'])) {
            return $sources['hd_url'];
        }
        return !empty($sources['iphone_url']) ? $sources['iphone_url'] : '';
    }

    $files = $sources['files'];
    usort($files, function ($a, $b) {
        return intval($a['height']) - intval($b['height']);
    });

    if ($default_res === 'low') {
        return $files[0]['url'];
    }
    if ($default_res === 'high') {
        return $files[count($files) - 1]['url'];
    }

    $target = intval(preg_replace('/[^\d]/', '', $default_res));
    if ($target > 0) {
        $best = $files[0];
        $min_diff = abs(intval($files[0]['height']) - $target);
        foreach ($files as $f) {
            $diff = abs(intval($f['height']) - $target);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $best = $f;
            }
        }
        return $best['url'];
    }

    return $files[count($files) - 1]['url'];
}

// Shorts: só os VERTICAIS (o feed é vertical) e com até 1:40 (100s).
// A referência é `video.orientation` (enum portrait/landscape/square).
$shorts_cond = " AND v.duration > 0 AND v.duration < 100 AND v.orientation = 'portrait'";

// Carregar lote inicial de vídeos server-side (até 6 vídeos) para FCP imediato
$initial_videos = array();
$exclude_vids = array();

// 1. Se houver initial_vid, buscá-lo prioritariamente
if ($initial_vid > 0) {
    $sql_init = "SELECT v.*, u.username, u.photo, u.gender, u.fname 
                 FROM video AS v, signup AS u 
                 WHERE v.VID = " . $initial_vid . " AND v.UID = u.UID" . $active_cond . $shorts_cond . " LIMIT 1";
    $rs_init = $conn->execute($sql_init);
    if ($rs_init && !$rs_init->EOF) {
        $row = $rs_init->fields;
        $sources = get_video_sources($row);
        $vurl = shorts_select_url($sources, $default_res);

        if (!empty($vurl)) {
            // Capa: respeita as capas escolhidas pelo admin em thumbnails_opt
            // (varia a cada request), com fallback para `thumb`.
            $thumb_num = video_rotate_cover($row);
            $gender = isset($row['gender']) ? $row['gender'] : 'm';
            $photo = (empty($row['photo'])) ? 'nopic-' . $gender . '.gif' : $row['photo'];
            
            $sql_c = "SELECT COUNT(CID) AS total FROM video_comments WHERE VID = " . $initial_vid . " AND status = '1'";
            $rs_c = $conn->execute($sql_c);
            $cc = ($rs_c && !$rs_c->EOF) ? intval($rs_c->fields['total']) : 0;

            $initial_videos[] = array(
                'vid' => $initial_vid,
                'title' => (string)$row['title'],
                'description' => isset($row['description']) ? (string)$row['description'] : '',
                'duration_formatted' => shorts_fmt_duration($row['duration']),
                'views_formatted' => shorts_fmt_num($row['viewnumber']),
                'likes' => intval($row['likes']),
                'likes_formatted' => shorts_fmt_num($row['likes']),
                'comments' => $cc,
                'comments_formatted' => shorts_fmt_num($cc),
                'creator' => array(
                    'uid' => intval($row['UID']),
                    'username' => (string)$row['username'],
                    'avatar_url' => $config['BASE_URL'] . '/media/users/' . $photo,
                    'channel_url' => $config['BASE_URL'] . '/user/' . urlencode($row['username']),
                    'is_subscribed' => false
                ),
                'poster_url' => get_video_thumb_src($initial_vid, $thumb_num),
                'video_url' => $vurl,
                'is_vertical' => (isset($row['orientation']) && $row['orientation'] === 'portrait'),
                'aspect' => video_aspect_ratio($row),
                'is_liked' => false,
                'is_fav' => false,
                'share_url' => $config['BASE_URL'] . '/shorts?v=' . $initial_vid
            );
            $exclude_vids[$initial_vid] = $initial_vid;
        }
    }
}

// 2. Buscar restante do lote inicial
switch ($tab) {
    case 'trending':
        $order_by = "ORDER BY v.viewnumber DESC, v.likes DESC, v.VID DESC";
        break;
    case 'recent':
        $order_by = "ORDER BY v.addtime DESC, v.VID DESC";
        break;
    case 'foryou':
    default:
        // Recência + audiência no MESMO score (estilo Hacker News): as views
        // ganham peso que decai com a idade, então um vídeo novo com poucas
        // views ainda bate um antigo muito visto. `addtime` é um timestamp
        // unix guardado em varchar.
        $order_by = "ORDER BY (v.viewnumber / POW(TIMESTAMPDIFF(HOUR, FROM_UNIXTIME(CAST(v.addtime AS UNSIGNED)), NOW()) + 2, 1.5)) DESC, v.addtime DESC, v.VID DESC";
        break;
}

$limit = 6;
$needed = $limit - count($initial_videos);
$not_in_sql = (!empty($exclude_vids)) ? " AND v.VID NOT IN (" . implode(',', $exclude_vids) . ")" : "";

if ($needed > 0) {
    $sql = "SELECT v.*, u.username, u.photo, u.gender, u.fname 
            FROM video AS v, signup AS u 
            WHERE v.UID = u.UID" . $active_cond . $shorts_cond . $not_in_sql . " 
            " . $order_by . " 
            LIMIT " . intval($needed);
    $rs = $conn->execute($sql);
    if ($rs) {
        while (!$rs->EOF) {
            $row = $rs->fields;
            $vid = intval($row['VID']);
            $sources = get_video_sources($row);
            $vurl = shorts_select_url($sources, $default_res);

            if (!empty($vurl)) {
                // Capa rotativa entre as escolhidas pelo admin (thumbnails_opt).
                $thumb_num = video_rotate_cover($row);
                $gender = isset($row['gender']) ? $row['gender'] : 'm';
                $photo = (empty($row['photo'])) ? 'nopic-' . $gender . '.gif' : $row['photo'];

                $sql_c = "SELECT COUNT(CID) AS total FROM video_comments WHERE VID = " . $vid . " AND status = '1'";
                $rs_c = $conn->execute($sql_c);
                $cc = ($rs_c && !$rs_c->EOF) ? intval($rs_c->fields['total']) : 0;

                $initial_videos[] = array(
                    'vid' => $vid,
                    'title' => (string)$row['title'],
                    'description' => isset($row['description']) ? (string)$row['description'] : '',
                    'duration_formatted' => shorts_fmt_duration($row['duration']),
                    'views_formatted' => shorts_fmt_num($row['viewnumber']),
                    'likes' => intval($row['likes']),
                    'likes_formatted' => shorts_fmt_num($row['likes']),
                    'comments' => $cc,
                    'comments_formatted' => shorts_fmt_num($cc),
                    'creator' => array(
                        'uid' => intval($row['UID']),
                        'username' => (string)$row['username'],
                        'avatar_url' => $config['BASE_URL'] . '/media/users/' . $photo,
                        'channel_url' => $config['BASE_URL'] . '/user/' . urlencode($row['username']),
                        'is_subscribed' => false
                    ),
                    'poster_url' => get_video_thumb_src($vid, $thumb_num),
                    'video_url' => $vurl,
                    'is_vertical' => (isset($row['orientation']) && $row['orientation'] === 'portrait'),
                    'aspect' => video_aspect_ratio($row),
                    'is_liked' => false,
                    'is_fav' => false,
                    'share_url' => $config['BASE_URL'] . '/shorts?v=' . $vid
                );
                $exclude_vids[$vid] = $vid;
            }
            $rs->MoveNext();
        }
    }
}

$smarty->assign('shorts_page', true);
$smarty->assign('self_title', 'Shorts — ' . $config['site_name']);
$smarty->assign('menu', 'shorts');
$smarty->assign('active_tab', $tab);
$smarty->assign('initial_vid', $initial_vid);
$smarty->assign('initial_videos', $initial_videos);
$smarty->assign('initial_videos_json', json_encode($initial_videos));
$smarty->assign('exclude_vids_json', json_encode(array_values($exclude_vids)));

$smarty->display('header.tpl');
$smarty->display('shorts.tpl');
$smarty->display('footer.tpl');
?>
