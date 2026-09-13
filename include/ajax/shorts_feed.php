<?php
defined('_VALID') or die('Restricted Access!');

if ($config['video_module'] == '0') {
    die(json_encode(array('status' => 0, 'msg' => 'Video module disabled', 'videos' => array())));
}

require_once $config['BASE_DIR'] . '/classes/filter.class.php';
require_once $config['BASE_DIR'] . '/include/adodb/adodb.inc.php';
require_once $config['BASE_DIR'] . '/include/compat/json.php';
require_once $config['BASE_DIR'] . '/include/dbconn.php';
require_once $config['BASE_DIR'] . '/include/function_video.php';
require_once $config['BASE_DIR'] . '/include/function_thumbs.php';
require_once $config['BASE_DIR'] . '/include/function_server.php';
// video_rotate_cover()/video_apply_cover_rotation() — capas escolhidas pelo
// admin em thumbnails_opt. O arquivo tem guard de carregamento único.
require_once $config['BASE_DIR'] . '/include/function_global.php';

$filter = new VFilter();
$tab = isset($_REQUEST['tab']) ? strtolower($filter->get('tab', 'STRING')) : 'foryou';
$page = isset($_REQUEST['page']) ? max(1, $filter->get('page', 'INTEGER')) : 1;
$limit = isset($_REQUEST['limit']) ? min(12, max(3, $filter->get('limit', 'INTEGER'))) : 6;
$initial_vid = isset($_REQUEST['initial_vid']) ? $filter->get('initial_vid', 'INTEGER') : 0;
$uid = isset($_SESSION['uid']) ? intval($_SESSION['uid']) : 0;

// Tratar lista de vídeos a excluir (para não repetir vídeos já assistidos na mesma sessão)
$exclude_vids = array();
if (!empty($_REQUEST['exclude'])) {
    if (is_array($_REQUEST['exclude'])) {
        foreach ($_REQUEST['exclude'] as $evid) {
            $evid = intval($evid);
            if ($evid > 0) {
                $exclude_vids[$evid] = $evid;
            }
        }
    } elseif (is_string($_REQUEST['exclude'])) {
        $parts = explode(',', $_REQUEST['exclude']);
        foreach ($parts as $evid) {
            $evid = intval(trim($evid));
            if ($evid > 0) {
                $exclude_vids[$evid] = $evid;
            }
        }
    }
}

$active_cond = ($config['approve'] == '1') ? " AND v.active = '1'" : "";
$offset = ($page - 1) * $limit;

// Função auxiliar para formatação humanizada de contadores (ex: 1.2K, 3.5M)
function format_shorts_number($num) {
    $num = intval($num);
    if ($num >= 1000000) {
        return round($num / 1000000, 1) . 'M';
    } elseif ($num >= 1000) {
        return round($num / 1000, 1) . 'K';
    }
    return (string)$num;
}

// Função auxiliar para formatar duração (ex: 0:45 ou 2:15)
function format_shorts_duration($sec) {
    $sec = intval($sec);
    $m = floor($sec / 60);
    $s = $sec % 60;
    return sprintf('%d:%02d', $m, $s);
}

// Obter resolução padrão definida no painel admin (tabela player)
$default_res = 'high';
$sql_p = "SELECT resolution FROM player WHERE profile = 'Main' LIMIT 1";
$rs_p = $conn->execute($sql_p);
if ($rs_p && !$rs_p->EOF && !empty($rs_p->fields['resolution'])) {
    $default_res = strtolower(trim($rs_p->fields['resolution']));
}

// Função para selecionar URL pela qualidade definida no admin
function select_video_url_by_quality($sources, $default_res = 'high') {
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

// Função para montar item de vídeo formatado
function build_short_item($row, $conn, $config, $uid, $default_res = 'high') {
    $vid = intval($row['VID']);
    $sources = get_video_sources($row);
    
    // Escolher a stream de vídeo de acordo com a qualidade configurada no admin
    $video_url = select_video_url_by_quality($sources, $default_res);

    if (empty($video_url)) {
        return null;
    }

    // Identificar orientação
    $orientation = isset($row['orientation']) ? (string)$row['orientation'] : 'landscape';
    $is_vertical = ($orientation === 'portrait');
    if (!$is_vertical && isset($row['width_sd']) && isset($row['height_sd'])) {
        if (intval($row['width_sd']) > 0 && intval($row['height_sd']) > intval($row['width_sd'])) {
            $is_vertical = true;
            $orientation = 'portrait';
        }
    }

    // Thumbnail / Capa: usa as capas escolhidas pelo admin em thumbnails_opt
    // (varia a cada request), com fallback para `thumb`.
    $thumb_num = video_rotate_cover($row);
    $poster_url = get_video_thumb_src($vid, $thumb_num);

    // Foto do criador
    $gender = isset($row['gender']) ? $row['gender'] : 'm';
    $photo = (empty($row['photo'])) ? 'nopic-' . $gender . '.gif' : $row['photo'];
    $avatar_url = $config['BASE_URL'] . '/media/users/' . $photo;

    // Contagem de comentários
    $sql_c = "SELECT COUNT(CID) AS total FROM video_comments WHERE VID = " . $vid . " AND status = '1'";
    $rs_c = $conn->execute($sql_c);
    $comment_count = ($rs_c && !$rs_c->EOF) ? intval($rs_c->fields['total']) : 0;

    // Estado do usuário atual (se logado)
    $is_liked = false;
    $is_fav = false;
    $is_subscribed = false;
    if ($uid > 0) {
        $sql_l = "SELECT VID FROM video_rating_id WHERE VID = " . $vid . " AND UID = " . $uid . " LIMIT 1";
        $rs_l = $conn->execute($sql_l);
        if ($rs_l && $rs_l->RecordCount() > 0) {
            $is_liked = true;
        }

        $sql_f = "SELECT VID FROM favourite WHERE VID = " . $vid . " AND UID = " . $uid . " LIMIT 1";
        $rs_f = $conn->execute($sql_f);
        if ($rs_f && $rs_f->RecordCount() > 0) {
            $is_fav = true;
        }

        $creator_uid = intval($row['UID']);
        if ($creator_uid !== $uid) {
            $sql_s = "SELECT UID FROM video_subscribe WHERE UID = " . $creator_uid . " AND SUID = " . $uid . " LIMIT 1";
            $rs_s = $conn->execute($sql_s);
            if ($rs_s && $rs_s->RecordCount() > 0) {
                $is_subscribed = true;
            }
        }
    }

    // Tratar tags / palavras-chave
    $tags = array();
    if (!empty($row['keyword'])) {
        $raw_tags = is_array($row['keyword']) ? $row['keyword'] : explode(' ', (string)$row['keyword']);
        $i = 0;
        foreach ($raw_tags as $t) {
            $t = trim($t);
            if ($t !== '' && strlen($t) > 1) {
                $tags[] = $t;
                $i++;
                if ($i >= 5) break;
            }
        }
    }

    $likes = intval($row['likes']);
    $views = intval($row['viewnumber']);

    return array(
        'vid' => $vid,
        'title' => (string)$row['title'],
        'description' => isset($row['description']) ? (string)$row['description'] : '',
        'duration' => intval($row['duration']),
        'duration_formatted' => format_shorts_duration($row['duration']),
        'views' => $views,
        'views_formatted' => format_shorts_number($views),
        'likes' => $likes,
        'likes_formatted' => format_shorts_number($likes),
        'rate' => intval($row['rate']),
        'comments' => $comment_count,
        'comments_formatted' => format_shorts_number($comment_count),
        'creator' => array(
            'uid' => intval($row['UID']),
            'username' => (string)$row['username'],
            'avatar_url' => $avatar_url,
            'channel_url' => $config['BASE_URL'] . '/user/' . urlencode($row['username']),
            'is_subscribed' => $is_subscribed
        ),
        'poster_url' => $poster_url,
        'video_url' => $video_url,
        'orientation' => $orientation,
        'is_vertical' => $is_vertical,
        // Aspecto real (width/height do banco) para o box do player no feed.
        'aspect' => video_aspect_ratio($row),
        'is_liked' => $is_liked,
        'is_fav' => $is_fav,
        'tags' => $tags,
        'share_url' => $config['BASE_URL'] . '/shorts?v=' . $vid,
        'standard_url' => $config['BASE_URL'] . '/video/' . $vid . '/' . (function_exists('clean_title') ? clean_title($row['title']) : '')
    );
}

// Shorts: só os VERTICAIS (o feed é vertical) e com menos de 1 minuto.
// A referência é `video.orientation` (enum portrait/landscape/square).
$shorts_cond = " AND v.duration > 0 AND v.duration < 60 AND v.orientation = 'portrait'";

$videos_out = array();

// Se for a primeira página e houver initial_vid requisitado, buscá-lo prioritariamente
if ($page === 1 && $initial_vid > 0 && !isset($exclude_vids[$initial_vid])) {
    $sql_init = "SELECT v.*, u.username, u.photo, u.gender, u.fname 
                 FROM video AS v, signup AS u 
                 WHERE v.VID = " . $initial_vid . " AND v.UID = u.UID" . $active_cond . $shorts_cond . " LIMIT 1";
    $rs_init = $conn->execute($sql_init);
    if ($rs_init && !$rs_init->EOF) {
        $item = build_short_item($rs_init->fields, $conn, $config, $uid, $default_res);
        if ($item) {
            $videos_out[] = $item;
            $exclude_vids[$initial_vid] = $initial_vid;
        }
    }
}

// Montar cláusula de exclusão
$not_in_sql = "";
if (!empty($exclude_vids)) {
    $not_in_sql = " AND v.VID NOT IN (" . implode(',', array_map('intval', $exclude_vids)) . ")";
}

// Ordem de acordo com a aba
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
        // unix guardado em varchar. Mesma ordenação de shorts.php.
        $order_by = "ORDER BY (v.viewnumber / POW(TIMESTAMPDIFF(HOUR, FROM_UNIXTIME(CAST(v.addtime AS UNSIGNED)), NOW()) + 2, 1.5)) DESC, v.addtime DESC, v.VID DESC";
        break;
}

$fetch_limit = $limit - count($videos_out);
if ($fetch_limit > 0) {
    $sql = "SELECT v.*, u.username, u.photo, u.gender, u.fname 
            FROM video AS v, signup AS u 
            WHERE v.UID = u.UID" . $active_cond . $shorts_cond . $not_in_sql . " 
            " . $order_by . " 
            LIMIT " . intval($fetch_limit);
            
    $rs = $conn->execute($sql);
    if ($rs) {
        while (!$rs->EOF) {
            $row = $rs->fields;
            $item = build_short_item($row, $conn, $config, $uid, $default_res);
            if ($item) {
                $videos_out[] = $item;
                $exclude_vids[$item['vid']] = $item['vid'];
            }
            $rs->MoveNext();
        }
    }
}

// Se a exclusão esgotou os vídeos (sessão muito longa), permitir reciclar sem exclusão
if (count($videos_out) < $limit && !empty($exclude_vids)) {
    $needed = $limit - count($videos_out);
    $current_batch_ids = array();
    foreach ($videos_out as $vo) {
        $current_batch_ids[] = $vo['vid'];
    }
    $curr_not_in = (!empty($current_batch_ids)) ? " AND v.VID NOT IN (" . implode(',', $current_batch_ids) . ")" : "";
    $sql_recycle = "SELECT v.*, u.username, u.photo, u.gender, u.fname 
                    FROM video AS v, signup AS u 
                    WHERE v.UID = u.UID" . $active_cond . $shorts_cond . $curr_not_in . " 
                    " . $order_by . " 
                    LIMIT " . intval($needed);
    $rs_rec = $conn->execute($sql_recycle);
    if ($rs_rec) {
        while (!$rs_rec->EOF) {
            $row = $rs_rec->fields;
            $item = build_short_item($row, $conn, $config, $uid, $default_res);
            if ($item) {
                $videos_out[] = $item;
            }
            $rs_rec->MoveNext();
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array(
    'status' => 1,
    'tab' => $tab,
    'page' => $page,
    'count' => count($videos_out),
    'videos' => $videos_out,
    'has_more' => (count($videos_out) >= 3)
));
die();
