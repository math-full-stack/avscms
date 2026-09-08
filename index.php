<?php
// Cloud Run: replicate .htaccess behavior (file/dir exists → serve, else → loader.php)
// Router state is kept in $_router_* variables on purpose: this file runs in
// global scope and un-prefixed variables ($file, $uri, $ext, $mimes) leak
// into every entrypoint/module required below (e.g. check.php does
// $file[]['path'] = ... and would fatal on a string). See AGENTS.md.
$_router_uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
if ($_router_uri !== '/' && $_router_uri !== '') {
    $_router_file = __DIR__ . $_router_uri;

    // Serve existing static files (CSS, JS, images, etc.)
    if (is_file($_router_file)) {
        $_router_ext = strtolower(pathinfo($_router_file, PATHINFO_EXTENSION));

        // PHP entrypoints: execute directly (siteadmin/login.php, videos.php, ...)
        // Guard: never require this file into itself - /index.php falls through
        // to the homepage code below (same exception .htaccess made for index).
        if (($_router_ext === 'php' || $_router_ext === 'phtml') && realpath($_router_file) !== __FILE__) {
            chdir(dirname($_router_file));
            require $_router_file;
            exit(0);
        }

        $_router_mimes = ['css'=>'text/css','js'=>'application/javascript','json'=>'application/json',
            'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
            'svg'=>'image/svg+xml','ico'=>'image/x-icon','webp'=>'image/webp',
            'woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','eot'=>'application/vnd.ms-fontobject',
            'mp4'=>'video/mp4','webm'=>'video/webm','txt'=>'text/plain','xml'=>'application/xml'];
        header('Content-Type: ' . ($_router_mimes[$_router_ext] ?? mime_content_type($_router_file) ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($_router_file));
        if (in_array($_router_ext, ['css','js','png','jpg','jpeg','gif','svg','ico','woff','woff2','ttf','eot','webp'])) {
            header('Cache-Control: public, max-age=2592000');
        }
        readfile($_router_file);
        exit(0);
    }

    // Directories: serve index.php inside them (replicates Apache MultiViews)
    if (is_dir($_router_file)) {
        $_router_index = rtrim($_router_file, '/') . '/index.php';
        if (is_file($_router_index)) {
            chdir(dirname($_router_index));
            require $_router_index;
            exit(0);
        }
    }
}
unset($_router_uri, $_router_file, $_router_ext, $_router_mimes, $_router_index);

define('_VALID', true);
require 'include/config.php';
require 'include/function_global.php';
require 'include/function_smarty.php';
require_once $config['BASE_DIR']. '/include/function_video.php';

$sql_add	= NULL;
$sql_delim	= ' AND';  // o 'WHERE v.UID = u.UID' já vem no FROM
if ( $config['show_private_videos'] == '0' ) {
    $sql_add   .= $sql_delim. " v.type = 'public'";
    $sql_delim	= ' AND';
}

$sql_add       .= $sql_delim. " v.active = '1'";  

$video_select   = "v.VID, v.title, v.duration, v.addtime, v.thumb, v.thumbs, v.thumbnails_opt, v.vthumbs, v.viewnumber, v.rate, v.likes, v.dislikes, v.type, v.hd, v.keyword, v.UID, v.orientation, u.username";
$video_from     = " FROM video AS v, signup AS u WHERE v.UID = u.UID" .$sql_add;

$sql            = "SELECT " .$video_select. $video_from. " ORDER BY v.viewtime DESC LIMIT " .$config['watched_per_page'];
$rs             = $conn->execute($sql);
$viewed_videos  = $rs->getrows();
$viewed_total   = count($viewed_videos);
$sql            = "SELECT " .$video_select. $video_from. " ORDER BY v.addtime DESC LIMIT " .$config['recent_per_page'];
$rs             = $conn->execute($sql);
$recent_videos  = $rs->getrows();

// Hero da home: destaques (mais vistos)
$hero_select = $video_select. ", v.server, v.formats, v.iphone, v.embed_code";
$sql         = "SELECT " .$hero_select. $video_from. " ORDER BY v.viewnumber DESC, v.viewtime DESC LIMIT 7";
$rs          = $conn->execute($sql);
$hero_videos = $rs->getrows();

// Mini-clip mudo no primeiro card do hero: fonte de playback (URL assinada)
foreach ( $hero_videos as $k => $v ) {
    $hero_videos[$k]['hero_src'] = '';
    if ( $v['embed_code'] != '' || empty($v['formats']) ) {
        continue;
    }
    $sources = get_video_sources($v);
    $best    = null;
    foreach ( $sources['files'] as $f ) {
        if ( $best === null || $f['height'] < $best['height'] ) {
            $best = $f;
        }
    }
    if ( $best === null && !empty($sources['iphone_url']) ) {
        $best = array('url' => $sources['iphone_url']);
    }
    if ( $best === null && !empty($sources['hd_url']) ) {
        $best = array('url' => $sources['hd_url']);
    }
    if ( $best !== null && !empty($best['url']) ) {
        $hero_videos[$k]['hero_src'] = $best['url'];
    }
}

// Rotação de capas (frames marcados em thumbnails_opt)
video_apply_cover_rotation($viewed_videos);
video_apply_cover_rotation($recent_videos);
video_apply_cover_rotation($hero_videos);

// Creators: usuários com mais vídeos
$sql            = "SELECT UID, username, photo, gender, total_videos FROM signup
                   WHERE account_status = 'Active' AND total_videos > '0'
                   ORDER BY total_videos DESC LIMIT 8";
$rs             = $conn->execute($sql);
$creators       = $rs->getrows();

// Carrossel aleatório por categoria (estilo Netflix)
$sql            = "SELECT CHID, name, slug FROM channel WHERE total_videos > 0 ORDER BY RAND() LIMIT 1";
$rs             = $conn->execute($sql);
$random_category = $rs->getrows();
$random_cat_videos = array();
if ($random_category) {
    $cat_id = intval($random_category[0]['CHID']);
    $sql    = "SELECT " .$video_select. $video_from. " AND v.channel = " .$cat_id. " ORDER BY v.addtime DESC LIMIT 30";
    $rs     = $conn->execute($sql);
    $random_cat_videos = $rs->getrows();
    video_apply_cover_rotation($random_cat_videos);
    foreach ( $random_cat_videos as $k => $v ) {
        $random_cat_videos[$k]['keywords'] = array_values(array_filter(array_map('trim', explode(',', $v['keyword']))));
    }
}
$smarty->assign('random_category', $random_category[0] ?? null);
$smarty->assign('random_cat_videos', $random_cat_videos);

// Normaliza keywords para arrays (mesmo formato da página do vídeo)
foreach ( $viewed_videos as $k => $v ) {
    $viewed_videos[$k]['keywords'] = array_values(array_filter(array_map('trim', explode(',', $v['keyword']))));
}
foreach ( $recent_videos as $k => $v ) {
    $recent_videos[$k]['keywords'] = array_values(array_filter(array_map('trim', explode(',', $v['keyword']))));
}
foreach ( $hero_videos as $k => $v ) {
    $hero_videos[$k]['keywords'] = array_values(array_filter(array_map('trim', explode(',', $v['keyword']))));
}

$smarty->assign('errors',$errors);
$smarty->assign('messages',$messages);
$smarty->assign('menu', 'home');
$smarty->assign('index', true);
$smarty->assign('viewed_total', $viewed_total);
$smarty->assign('viewed_videos', $viewed_videos);
$smarty->assign('recent_videos', $recent_videos);
$smarty->assign('hero_videos', $hero_videos);
$smarty->assign('creators', $creators);
$smarty->assign('self_title', $seo['index_title']);
$smarty->assign('self_description', $seo['index_desc']);
$smarty->assign('self_keywords', $seo['index_keywords']);
$smarty->loadFilter('output', 'trimwhitespace');
$smarty->display('header.tpl');
$smarty->display('index.tpl');
$smarty->display('footer.tpl');
?>
