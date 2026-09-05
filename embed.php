<?php
define('_VALID', true);
require 'include/config.php';
require 'include/function_global.php';
require 'include/function_smarty.php';
require 'include/function_thumbs.php';


$vkey = get_request_arg('embed', 'STRING');
if ( !$vkey ) {
	$smarty->assign('video', $video);
	$smarty->display('embed.tpl');
	exit;
}

$video_root = '';

$sql        = "SELECT * FROM video WHERE vkey = '" .$vkey. "' AND active = '1' LIMIT 1";
$rs         = $conn->execute($sql);

if ( $conn->Affected_Rows() != 1 ) {
	$smarty->display('embed.tpl');
	exit;
}

$video              = $rs->getrows();
$video              = $video['0'];
$vid 				= $video['VID'];

if ($video['embed_code'] == '') {
	require_once $config['BASE_DIR']. '/include/function_video.php';

	//---- Player engine (drives how the playback sources are built)
	$sql    = "SELECT * from player WHERE profile = 'Embed' LIMIT 1";
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
		require_once 'classes/sprite.class.php';	
		$sprite = new images_to_sprite(get_thumb_dir($vid),get_thumb_dir($vid).'/sprite',$config['img_max_width'],$config['img_max_height']);
		if ($sprite->sprite_is_stale()) {
			$sprite->create_sprite();
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

$sql        = "UPDATE video SET viewnumber = viewnumber+1, viewtime='" .date('Y-m-d H:i:s'). "' WHERE VID = " .$video['VID']. " LIMIT 1";
$conn->execute($sql);

$smarty->assign('video', $video);
$smarty->assign('video_root', $video_root);
$smarty->assign('vitem', $secret);
$smarty->display('embed.tpl');
?>