<?php
// Cloud Run: replicate .htaccess behavior (file/dir exists → serve, else → loader.php)
$uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
if ($uri !== '/' && $uri !== '') {
    $file = __DIR__ . $uri;

    // Serve existing static files (CSS, JS, images, etc.)
    if (is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // PHP files in subdirectories: execute directly (siteadmin/login.php, etc.)
        if ($ext === 'php' && dirname($file) !== __DIR__) {
            require $file;
            exit(0);
        }

        if ($ext !== 'php' && $ext !== 'phtml') {
            $mimes = ['css'=>'text/css','js'=>'application/javascript','json'=>'application/json',
                'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
                'svg'=>'image/svg+xml','ico'=>'image/x-icon','webp'=>'image/webp',
                'woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','eot'=>'application/vnd.ms-fontobject',
                'mp4'=>'video/mp4','webm'=>'video/webm','txt'=>'text/plain','xml'=>'application/xml'];
            header('Content-Type: ' . ($mimes[$ext] ?? mime_content_type($file) ?: 'application/octet-stream'));
            header('Content-Length: ' . filesize($file));
            if (in_array($ext, ['css','js','png','jpg','jpeg','gif','svg','ico','woff','woff2','ttf','eot','webp'])) {
                header('Cache-Control: public, max-age=2592000');
            }
            readfile($file);
            exit(0);
        }
    }

    // Directories: serve index.php inside them (replicates Apache MultiViews)
    if (is_dir($file)) {
        $index = rtrim($file, '/') . '/index.php';
        if (is_file($index)) {
            require $index;
            exit(0);
        }
    }
}

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
