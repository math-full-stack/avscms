<?php
define('_VALID', true);
require 'include/config.php';
require 'include/function_global.php';
require 'include/function_smarty.php';
require 'include/function_thumbs.php';
require 'classes/pagination.class.php';
require 'include/function_user.php';

if ( $config['video_view'] == 'registered' ) {
    require 'classes/auth.class.php';
    Auth::check_();
}

$vid = get_request_arg('video');
if ( !$vid ) {
    VRedirect::go($config['BASE_URL']. '/notfound/video_missing');
}

$active     = ( $config['approve'] == '1' ) ? " AND v.active = '1'" : NULL;
$sql        = "SELECT v.*, u.username, u.photo, u.gender, u.fname
               FROM video AS v, signup AS u WHERE v.VID = " .$vid. " AND v.UID = u.UID" .$active. " LIMIT 1";
$rs         = $conn->execute($sql);
if ( $conn->Affected_Rows() != 1 ) {
    VRedirect::go($config['BASE_URL']. '/notfound/video_missing');
}

$video_width  = $rs->fields['width_sd'];
$video_height = $rs->fields['height_sd'];
$video_root   = '';

$vertical = false;
if ($video_width < $video_height || intval($video_width) < 100 || intval($video_height) < 100) {
	$vertical = true;
}

$video_width = 640;
$video_height = 360;

$player_width = 640;
$embed_width = 640;
$embed_auto_height = round($embed_width * ($video_height/$video_width));

$video              = $rs->getrows();
$video              = $video['0'];

if ($video['embed_code'] == '') {
	require_once $config['BASE_DIR']. '/include/function_video.php';

	//---- Player engine (drives how the playback sources are built)
	$sql    = "SELECT * from player WHERE profile = 'Main' LIMIT 1";
	$rs     = $conn->execute($sql);
	$player = $rs->getrows();
	$player = $player['0'];

	$secret = '';
	if ($player['engine'] == 'mediabunny') {
		// Media Bunny: plain (signed/expiring) URLs — the security comes from
		// the V4 signature + TTL, not from client-side obfuscation.
		$sources = get_video_sources($video);
	} else {
		// Video.js: keep the legacy AES obfuscation layer (decrypt.min.js)
		$length = 8;
		$mykey = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
		$iv = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 16);
		$secret = $mykey.".".$iv;
		$sources = get_video_sources($video, $mykey, $iv);
	}

	$video['files']      = $sources['files'];
	$video['iphone_url'] = $sources['iphone_url'];
	$video['hd_url']     = $sources['hd_url'];

	// video_root kept for template compatibility (FTP/local servers)
	$video_root = '';
	if ($sources['server']) {
		$video_root = rtrim($sources['server']['video_url'], '/');
	}
	if ($video_root == '') {
		$video_root = $config['BASE_URL']."/media/videos";
	}

	//---- VJS
	if ($player['timeline_preview'] == 1) {
		require_once $config['BASE_DIR']. '/include/function_server.php';

		// Vídeos GCS: o sprite.jpg é gerado e enviado ao bucket pelo pipeline de
		// conversão (e pelo backfill gcs_sync_thumbs.php --prune) e servido de lá
		// — o tmb/{VID} local pode nem existir, então não regenerar aqui.
		if (!video_is_on_gcs($vid)) {
			require_once 'classes/sprite.class.php';
			$sprite = new images_to_sprite(get_thumb_dir($vid),get_thumb_dir($vid).'/sprite',$config['img_max_width'],$config['img_max_height']);
			$thumb_dir = get_thumb_dir($vid);
			if ($sprite->sprite_is_stale() && is_dir($thumb_dir) && count(glob($thumb_dir.'/*.jpg')) > 0) {
				$sprite->create_sprite();
				// Mantém o sprite do bucket em dia (thumbs de vídeos GCS são servidos do bucket)
				sync_video_sprite($vid, true);
			}
		}
		$player['sprite'] = get_thumb_url($vid).'/sprite.jpg';
	}
	$smarty->assign('player', $player);

	require_once 'classes/Mobile_Detect.php';

	$detect = new Mobile_Detect;
	if ( $detect->isMobile() ) {
		$device = 'm';
	} else {
		$device = 'd';
	}

	$sql = "SELECT channel FROM video WHERE VID = '". $vid ."' LIMIT 1";
	$rs = $conn->execute($sql);
	$category = $rs->fields['channel'];

	$sql = "SELECT id FROM adv_pause WHERE categories LIKE '%-".$category."-%' AND device LIKE '%".$device."%' AND status = '1' ORDER BY rand() LIMIT 1";
	$rs  = $conn->execute($sql);

	if ( $conn->Affected_Rows() != 1 ) {
		$smarty->assign('aid', false);
	} else {
		$ad  = $rs->getrows();
		$ad = $ad['0'];		
		$smarty->assign('aid', $ad['id']);	
	}
	
	$sql = "SELECT * FROM adv_vast_vpaid WHERE categories LIKE '%-".$category."-%' AND device LIKE '%".$device."%' AND status = '1' ORDER BY rand() LIMIT 1";
	$rs  = $conn->execute($sql);

	if ( $conn->Affected_Rows() != 1 ) {
		$smarty->assign('vast_vpaid', false);
	} else {
		$vast_vpaid  = $rs->getrows();
		$vast_vpaid = $vast_vpaid['0'];		
		$smarty->assign('vast_vpaid', $vast_vpaid);	
	}		
	//---- VJS END
}


$guest_limit	    = false;

$video['keyword']   = explode(',', $video['keyword']);
foreach ($video['keyword'] as $key => $tag) {
	$video['keyword'][$key] = trim($tag);
}
$uid                = ( isset($_SESSION['uid']) ) ? intval($_SESSION['uid']) : NULL;
$is_friend          = true;
if ( $video['type'] == 'private' && $uid != $video['UID'] ) {
    $sql = "SELECT FID FROM friends
            WHERE ((UID = " .intval($video['UID']). " AND FID = " .$uid. ")
            OR (UID = " .$uid. " AND FID = " .intval($video['UID']). "))
            AND status = 'Confirmed'
            LIMIT 1";
    $conn->execute($sql);
    if ( $conn->Affected_Rows() == 0 ) {
        $is_friend = false;
    }
}

$sql        = "UPDATE video SET viewnumber = viewnumber+1, viewtime='" .date('Y-m-d H:i:s'). "' WHERE VID = " .$vid. " LIMIT 1";
$conn->execute($sql);
$sql        = "UPDATE signup SET video_viewed = video_viewed+1 WHERE UID = " .intval($video['UID']). " LIMIT 1";
$conn->execute($sql);
if ( isset($_SESSION['uid']) ) {
    $sql    = "UPDATE signup SET watched_video = watched_video+1 WHERE UID = " .$uid. " LIMIT 1";
    $conn->execute($sql);
    $sql    = "SELECT UID FROM playlist WHERE UID = " .$uid. " AND VID = " .$vid. " LIMIT 1";
    $conn->execute($sql);
    if ( $conn->Affected_Rows() == 0 ) {
        $sql    = "INSERT INTO playlist SET UID = '" .$uid. "' , VID = '" .$vid. "'";
        $conn->execute($sql);
    }
}

$sql_add        = NULL;
if ( $video['keyword'] ) {
    $sql_add   .= " OR (";
    $sql_or     = NULL;    
    foreach ( $video['keyword'] as $keyword ) {
        $sql_add .= $sql_or. " keyword LIKE '%" .trim($conn->qStr($keyword), "'"). "%'";
        $sql_or   = " OR ";
    }
    $sql_add   .= ")";
}


$sql_at		= NULL;
$sql_delim	= ' WHERE';
if ( $config['show_private_videos'] == '0' ) {
    $sql_at    .= $sql_delim. " type = 'public'";
    $sql_delim	= ' AND';
}

if ( $config['approve'] == '1' ) {
    $sql_at    .= $sql_delim. " active = '1'";
	$sql_delim  = ' AND';
}
$sql_at	       .= $sql_delim;

$sql            = "SELECT COUNT(VID) AS total_videos FROM video" .$sql_at. " active = '1' AND channel = '" .$video['channel']. "' AND VID != " .$vid. "
                   AND ( title LIKE '%" .trim($conn->qStr($video['title']), "'"). "%' " .$sql_add. ")";
$rsc            = $conn->execute($sql);
$total_related  = $rsc->fields['total_videos'];
if ( $total_related > 32 ) {
    $total_related = 32;
}
$pagination     = new Pagination(8, 'p_related_videos_' .$video['VID']. '_');
$limit          = $pagination->getLimit($total_related);
$sql            = "SELECT v.VID, v.title, v.duration, v.addtime, v.rate, v.likes, v.dislikes, v.viewnumber, v.type, v.thumb, v.thumbs, v.vthumbs, v.hd, v.keyword, u.username FROM video AS v, signup AS u
                   WHERE v.UID = u.UID AND v.active = '1' AND v.channel = '" .$video['channel']. "' AND v.VID != " .$vid. "
                   AND ( v.title LIKE '%" .trim($conn->qStr($video['title']), "'"). "%' " .$sql_add. ")
                   ORDER BY v.addtime DESC LIMIT " .$limit;
$rs             = $conn->execute($sql);
$videos         = $rs->getrows();

// Normaliza keywords para arrays (mesmo formato da home)
foreach ( $videos as $k => $v ) {
    $videos[$k]['keywords'] = array_values(array_filter(array_map('trim', explode(',', $v['keyword']))));
}

$page_link      = $pagination->getPagination('video');

$sql            = "SELECT COUNT(CID) AS total_comments FROM video_comments WHERE VID = " .$vid. " AND status = '1'";
$rsc            = $conn->execute($sql);
$total_comments = $rsc->fields['total_comments'];
$pagination     = new Pagination(10);
$limit          = $pagination->getLimit($total_comments);
$sql            = "SELECT c.*, s.username, s.photo, s.gender
                   FROM video_comments AS c, signup AS s 
                   WHERE c.VID = " .$vid. " AND c.status = '1' AND c.UID = s.UID 
                   ORDER BY c.addtime DESC LIMIT " .$limit;
$rs             = $conn->execute($sql);
$comments       = $rs->getrows();
$page_link_c    = $pagination->getPagination('video', 'p_video_comments_' .$video['VID']. '_');
$page_link_cb   = $pagination->getPagination('video', 'pp_video_comments_' .$video['VID']. '_');
$start_num      = $pagination->getStartItem();
$end_num        = $pagination->getEndItem();

$self_title         = $video['title'] . $seo['video_title'];
$self_description   = $video['title'] . $seo['video_desc'];
$self_keywords      = implode(', ', $video['keyword']) . $seo['video_keywords'];


if (is_numeric($new_permisions['bandwidth']) && $new_permisions['bandwidth'] != '-1') {
	$user_limit_bandwidth = $new_permisions['bandwidth'];
	$remote_ip = ip2long($remote_ip);
	require $config['BASE_DIR']. '/classes/bandwidth.class.php';
	$guest_limit = VBandwidth::check($remote_ip, intval($video['space']));
}


if ($new_permisions['watch_normal_videos'] == 0) {
	// nu are voie sa vada filme normale
	if ($type_of_user == 'guest') {
		$_SESSION['error'] = 'You need to register in order to watch videos';
		VRedirect::go($config['BASE_URL']. '/signup');
	} elseif ($type_of_user == 'free') {
		VRedirect::go($config['BASE_URL']. '/notfound/free_watch_permission');
	} elseif ($type_of_user == 'premium') {
		VRedirect::go($config['BASE_URL']. '/notfound/premium_watch_permission');
	}

}


$video['total_subscribers'] = get_user_total_subscribers($video['UID']);	

// ---- Carousels estilo Netflix (seções abaixo do player) ----
$caro_select = "v.VID, v.title, v.duration, v.addtime, v.rate, v.likes, v.dislikes, v.viewnumber, v.type, v.thumb, v.thumbs, v.vthumbs, v.hd, u.username";
$caro_from   = " FROM video AS v, signup AS u WHERE v.UID = u.UID AND v.active = '1' AND v.type = 'public' AND v.VID != " .$vid. " ";

// Em alta (mais vistos)
$sql         = "SELECT " .$caro_select. $caro_from. " ORDER BY v.viewnumber DESC LIMIT 15";
$rs          = $conn->execute($sql);
$caro_trending = $rs->getrows();

// Novos vídeos
$sql         = "SELECT " .$caro_select. $caro_from. " ORDER BY v.addtime DESC LIMIT 15";
$rs          = $conn->execute($sql);
$caro_new    = $rs->getrows();

// Do mesmo creator
$sql         = "SELECT " .$caro_select. $caro_from. " AND v.UID = " .(int)$video['UID']. " ORDER BY v.addtime DESC LIMIT 15";
$rs          = $conn->execute($sql);
$caro_creator = $rs->getrows();

$smarty->assign('caro_trending', $caro_trending);
$smarty->assign('caro_new', $caro_new);
$smarty->assign('caro_creator', $caro_creator);

$smarty->assign('errors',$errors);
$smarty->assign('messages',$messages);
$smarty->assign('menu', 'videos');
$smarty->assign('submenu', '');
$smarty->assign('view', true);
$smarty->assign('player_width',$player_width);
$smarty->assign('video_width',$video_width);
$smarty->assign('video_height',$video_height);
$smarty->assign('embed_width',$embed_width);
$smarty->assign('embed_auto_height',$embed_auto_height);
$smarty->assign('vertical',$vertical);
$smarty->assign('video', $video);
$smarty->assign('vitem', $secret);
$smarty->assign('video_root', $video_root);
$smarty->assign('self_title', $self_title);
$smarty->assign('self_description', $self_description);
$smarty->assign('self_keywords', $self_keywords);
$smarty->assign('videos_total', $total_related);
$smarty->assign('videos', $videos);
$smarty->assign('page_link', $page_link);
$smarty->assign('comments_total', $total_comments);
$smarty->assign('comments', $comments);
$smarty->assign('page_link_comments', $page_link_c);
$smarty->assign('page_link_comments_bottom', $page_link_cb);
$smarty->assign('start_num', $start_num);
$smarty->assign('end_num', $end_num);
$smarty->assign('is_friend', $is_friend);
$smarty->assign('guest_limit', $guest_limit);
$smarty->assign('new_permisions', $new_permisions);
$smarty->loadFilter('output', 'trimwhitespace');
$smarty->display('header.tpl');
$smarty->display('video.tpl');
$smarty->display('footer.tpl');
?>
