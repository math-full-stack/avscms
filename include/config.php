<?php
defined('_VALID') or die('Restricted Access!');

header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Load .env variables before anything reads getenv() / $_ENV.
require __DIR__ . '/dotenv.php';

require 'debug.php';
require 'config.paths.php';
require 'config.db.php';

// config.local.php is machine-specific (admin password, tool paths) and
// excluded from version control.  In Cloud Run (or any env that sets the
// DB env vars) we build the equivalent config at runtime so the container
// boots without the file.
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} elseif (isset($_ENV['DB_HOST']) || isset(getenv()['DB_HOST'])) {
    // Cloud Run / container environment — assemble config from env vars
    // with production-safe defaults matching the original config.local.php.
    $config['site_name']      = getenv('SITE_NAME')      ?: 'Pornozinho';
    $config['site_title']     = getenv('SITE_TITLE')     ?: 'Pornozinho';
    $config['admin_name']     = getenv('ADMIN_USER')     ?: 'admin';
    $config['admin_pass']     = getenv('ADMIN_PASS')     ?: 'admin';
    $config['noreply_email']  = getenv('NOREPLY_EMAIL')  ?: 'noreply@pornozinho.com';
    $config['admin_email']    = getenv('ADMIN_EMAIL')    ?: 'admin@pornozinho.com';
    $config['phppath']        = getenv('PHP_PATH')        ?: '/usr/bin/php';
    $config['ffmpeg']         = getenv('FFMPEG_PATH')     ?: '/usr/bin/ffmpeg';
    $config['ffprobe']        = getenv('FFPROBE_PATH')    ?: '/usr/bin/ffprobe';
    $config['thumbs_tool']    = 'ffmpeg';
    $config['processor']      = 'ffmpeg';
    $config['meta_description'] = getenv('META_DESC')    ?: 'Free Porn Videos';
    $config['meta_keywords']  = getenv('META_KEYWORDS')  ?: 'porn, sex, free videos, porn videos';
    $config['language']       = 'pt_BR';
    $config['multi_language'] = '0';
    $config['template']       = 'pornozinho';
    $config['template_admin'] = 'default';
    $config['worker_role']    = 'web';
    $config['conversion_q']   = '0';
    $config['q_limit']        = '4';
    $config['q_timeout']      = '6';
    $config['gcs_enabled']    = '1';
    $config['gcs_bucket']     = getenv('GCS_BUCKET') ?: 'pornozinho-cdn1';
    $config['gcs_streaming_url'] = getenv('GCS_STREAMING_URL') ?: 'https://storage.googleapis.com/pornozinho-cdn1';
    $config['gcs_key_path']   = '';
    $config['gcs_acl']        = 'publicRead';
    $config['gcs_cache_control'] = 'public, max-age=31536000';
    $config['seo_urls']       = '';
    $config['streaming_method'] = 'progressive_download';
    $config['videos_per_page'] = '32';
    $config['albums_per_page'] = '22';
    $config['users_per_page'] = '15';
    $config['blogs_per_page'] = '10';
    $config['watched_per_page'] = '18';
    $config['recent_per_page'] = '30';
    $config['items_per_front_page'] = '24';
    $config['max_img_size']   = '200';
    $config['img_max_width']  = '1920';
    $config['img_max_height'] = '1080';
    $config['max_display_size'] = '400';
    $config['max_video_size'] = '300000000';
    $config['video_max_size'] = '20';
    $config['video_allowed_extensions'] = 'avi,mpg,mov,asf,mpeg,xvid,divx,3gp,mkv,3gpp,mp4,rmvb,rm,dat,wmv,flv,ogg,ogv,webm';
    $config['image_allowed_extensions'] = 'jpg,jpeg,png,gif,bmp';
    $config['image_max_size'] = '100000000000000';
    $config['emailsender']    = 'Pornozinho';
    $config['mailer']          = 'mail';
    $config['sendmail']        = '/usr/sbin/sendmail';
    $config['smtp']            = '';
    $config['smtp_auth']       = '0';
    $config['smtp_username']   = '';
    $config['smtp_password']   = '';
    $config['smtp_port']       = '';
    $config['smtp_prefix']     = '';
    $config['smtp_autotls']    = '1';
    $config['smtp_debug']      = '0';
    $config['approve']         = '0';
    $config['approve_photos']  = '0';
    $config['approve_blogs']   = '0';
    $config['downloads']       = '1';
    $config['del_original_video'] = '1';
    $config['pm_notify']       = '1';
    $config['user_registration'] = '1';
    $config['email_verification'] = '1';
    $config['video_view']      = 'all';
    $config['video_comments']  = '1';
    $config['private_msgs']    = 'all';
    $config['video_module']    = '1';
    $config['friends_module']  = '1';
    $config['groups_module']   = '1';
    $config['upload_module']   = '1';
    $config['channels_module'] = '1';
    $config['community_module'] = '1';
    $config['photo_module']    = '0';
    $config['blog_module']     = '0';
    $config['show_private_videos'] = '1';
    $config['show_private_albums'] = '1';
    $config['plugin_login']    = '1';
    $config['plugin_statistics'] = '1';
    $config['plugin_tag_cloud'] = '1';
    $config['plugin_ptag_cloud'] = '1';
    $config['plugin_gtag_cloud'] = '1';
    $config['plugin_vtag_cloud'] = '1';
    $config['lighttpd']        = '0';
    $config['splash']          = '0';
    $config['delete_album']    = '1';
    $config['delete_photos']   = '1';
    $config['delete_videos']   = '0';
    $config['use_feeds']       = '1';
    $config['guest_limit']     = '0';
    $config['guest_bandwidth'] = '50';
    $config['multiserver']     = '0';
    $config['multi_server']    = '1';
    $config['edit_videos']     = '1';
    $config['force_utf8']      = '1';
    $config['user_remember']   = '1';
    $config['submenu_tag_scroller'] = '1';
    $config['offline']         = '0';
    $config['video_rate']      = 'user';
    $config['photo_rate']      = 'user';
    $config['user_rate']       = 'user';
    $config['comment_rate']    = 'user';
    $config['ads']             = '1';
    $config['rating']          = 'user';
    $config['video_embed']     = '1';
    $config['photo_comments']  = '1';
    $config['blog_comments']   = '1';
    $config['wall_comments']   = '1';
    $config['notice_comments'] = '1';
    $config['captcha']         = '0';
    $config['captcha_level']   = 'easy';
    $config['index_title']     = 'Free Porn Videos';
    $config['grabber']         = '1';
    $config['grabber_auth_mode'] = '3';
    $config['grabber_player_client'] = 'mweb';
    $config['grabber_js_runtime'] = 'node';
    $config['grabber_cookies'] = '';
    $config['grabber_cookies_browser'] = '';
    $config['vbitrate']        = '500';
    $config['sbitrate']        = '22050';
    $config['vresize']         = '1';
    $config['vresize_x']       = '1280';
    $config['vresize_y']       = '720';
    $config['hd_convert']      = '1';
    $config['iphone_convert']  = '1';
    $config['flv_convert']     = '0';
    $config['log_conversion']  = '1';
    $config['mobile_view_limit'] = '';
    $config['mobile_template'] = '';
    $config['mobile_default_type'] = '';
    $config['max_thumb_folders'] = '32000';
    $config['fb_signin']       = '0';
    $config['fb_appid']        = '';
    $config['g_signin']        = '0';
    $config['g_cid']           = '';
    $config['recaptcha_site_key'] = '';
    $config['recaptcha_secret_key'] = '';
    $config['thumbnail_player_width'] = '1920';
    $config['thumbnail_player_height'] = '1080';
    $config['thumbnail_remove_bb'] = '1';
    $config['thumbnail_keep_ar'] = '1';
    $config['watermark_image'] = 'media/player/logo/logo.png';
    $config['neroaacenc']      = '';
    $config['mp4box']          = '';
    $config['mediainfo']       = '';
    $config['vthumbs']         = '1';
    $config['vthumb_width']    = '960';
    $config['vthumb_height']   = '540';
    $config['facebook_id']     = 'fb_avs';
    $config['insgram_id']      = '';
    $config['twitter_id']      = 'twitter_avs';
    $config['reddit_id']       = 'reddit_avs';
    $config['instagram_id']    = 'instagram_avs';
    $config['max_col']         = '5';
    $config['min_col']         = '2';
    $config['ftp_host']        = '';
    $config['ftp_username']    = '';
    $config['ftp_password']    = '';
    $config['ftp_root']        = '';
    // Guest/free/premium permissions
    $config['visitors_watch_normal_videos'] = '1';
    $config['visitors_watch_hd_videos'] = '1';
    $config['visitors_bandwidth_select'] = '-1';
    $config['visitors_bandwidth'] = '-1';
    $config['visitors_sd_downloads'] = '1';
    $config['visitors_hd_downloads'] = '1';
    $config['visitors_mobile_downloads'] = '1';
    $config['visitors_in_player_ads'] = '1';
    $config['free_watch_normal_videos'] = '1';
    $config['free_watch_hd_videos'] = '1';
    $config['free_bandwidth'] = '-1';
    $config['free_sd_downloads'] = '1';
    $config['free_hd_downloads'] = '1';
    $config['free_mobile_downloads'] = '1';
    $config['free_in_player_ads'] = '1';
    $config['free_write_in_blog'] = '1';
    $config['free_upload_video'] = '1';
    $config['premium_watch_normal_videos'] = '1';
    $config['premium_watch_hd_videos'] = '1';
    $config['premium_bandwidth'] = '-1';
    $config['premium_sd_downloads'] = '1';
    $config['premium_hd_downloads'] = '1';
    $config['premium_mobile_downloads'] = '1';
    $config['premium_in_player_ads'] = '1';
    $config['premium_write_in_blog'] = '1';
    $config['premium_upload_video'] = '1';
    $config['session_lifetime'] = '1440';
    $config['session_driver']  = 'database';
    $config['gzip_encoding']   = '1';
} else {
    die('Missing config.local.php and no DB env vars found.');
}
require 'config.seo.php';
require 'config.language.php';

// Google Cloud Storage config (optional - only if file exists)
if (file_exists(__DIR__ . '/config.gcs.php')) {
    require __DIR__ . '/config.gcs.php';
}

require $config['BASE_DIR']. '/classes/timer.class.php';
require $config['BASE_DIR']. '/classes/redirect.class.php';

if ($config['splash'] == '1' && !defined('_ENTER') && !defined('_ADMIN') && !defined('_MOBILE') && !defined('_CLI')) {
	if (!isset($_COOKIE['splash'])) {
		VRedirect::go($config['BASE_URL']. '/enter.php');
	}
}

require $config['BASE_DIR']. '/include/security.php';
require $config['BASE_DIR']. '/include/smarty/libs/Smarty.class.php';
require $config['BASE_DIR']. '/include/adodb/adodb.inc.php';
require $config['BASE_DIR']. '/include/dbconn.php';

if ( !defined('_CONSOLE') ) {
    require $config['BASE_DIR']. '/include/sessions.php';
}

disableRegisterGlobals();

require $config['BASE_DIR']. '/include/function_language.php';
if (!isset($_SESSION['language'])) {
	$_SESSION['language'] = $config['language'];
}

if ($config['multi_language'] && isset($_POST['language'])) {
	$language = trim($_POST['language']);
	if (isset($languages[$language])) {
		$_SESSION['language'] = $language;
	}
}
require $config['BASE_DIR']. '/language/'.$_SESSION['language'].'.lang.php';

require $config['BASE_DIR']. '/classes/remember.class.php';
if ( !defined('_CONSOLE') && $config['gzip_encoding'] == 1 ) {
	ob_start();
	ob_implicit_flush(0);
}

$err       	= array();
$errors    	= array();
$messages	= array();
$warnings  	= array();
$info      	= array();

if ( isset($_SESSION['message']) ) {
    $messages[] = $_SESSION['message'];
    unset($_SESSION['message']);
}

if ( isset($_SESSION['error']) ) {
    $errors[] = $_SESSION['error'];
    unset($_SESSION['error']);
}

$remote_ip = ( isset($_SERVER['REMOTE_ADDR']) && long2ip(ip2long($_SERVER['REMOTE_ADDR'])) ) ? $_SERVER['REMOTE_ADDR'] : NULL;
if ( isset($_SESSION['uid']) ) {
    $sid    = intval($_SESSION['uid']);
    if ( $remote_ip ) {
        $sql    = "UPDATE signup SET user_ip = " .$conn->qStr($remote_ip). " WHERE UID = " .$sid. " LIMIT 1";
        $conn->execute($sql);
    }
}

if ( $remote_ip ) {
	$sql = "SELECT ban_id FROM bans WHERE ban_ip = " .$conn->qStr($remote_ip). " LIMIT 1";
	$conn->execute($sql);
	if ( $conn->Affected_Rows() > 0 ) {
    	    VRedirect::go($config['BASE_URL']. '?msg=You are banned from this site!');
	}
}

if ( $config['user_remember'] == '1' ) {
    Remember::check();
}

require 'smarty.php';

// Host processing role (fail-closed). Only the host that explicitly declares
// worker_role = 'converter' (the local PC) consumes the shared conversion and
// grab queues and runs FFmpeg. Any other host - or one without the key -
// defaults to 'web' and NEVER spawns conversion/grab work: it only waits for
// the converter host. Set worker_role = 'converter' in config.local.php on
// the PC and 'web' on the VM (deploy.sh preserves each host's file).
if (!isset($config['worker_role']) || !in_array($config['worker_role'], array('converter', 'web'), true)) {
	$config['worker_role'] = 'web';
}

if($config['conversion_q'] == '1') {
	require_once $config['BASE_DIR'].'/include/function_queue.php'; 
	if (queue_should_process()) {
		check_q(); 
		pump_conversion_queue();
	}
}

// Real-time grab queue processing (when enabled) - converter host only
require_once $config['BASE_DIR'].'/include/function_grab_queue.php';
if ($config['worker_role'] === 'converter') {
	check_grab_queue();
}

if ( $config['submenu_tag_scroller'] == '1' ) {
    $tags       = array();
    $sql        = "SELECT keyword FROM video WHERE active = '1' ORDER BY viewnumber LIMIT 10";
    $rs         = $conn->execute($sql);
    $rows       = $rs->getrows();
    foreach ( $rows as $row ) {
        $tag_arr = explode(' ', $row['keyword']);
        foreach ( $tag_arr as $tag ) {
            if ( strlen($tag) > 3 && !in_array($tag, $tags) ) {
                $tags[] = $tag;
            }
        }
    }
    
    $smarty->assign('scroller_content', $tags);
}

if ( isset($_SESSION['uid']) ) {
	$sid    = intval($_SESSION['uid']);
    $sql            = "UPDATE users_online SET online = '" .time(). "' WHERE UID = " .$sid. " LIMIT 1";
    $conn->execute($sql);
    $sql            = "SELECT COUNT(UID) AS total_requests FROM friends WHERE UID = " .$sid. " AND status = 'Pending'";
    $rs             = $conn->execute($sql);
    $requests_count = $rs->fields['total_requests'];
    $sql            = "SELECT COUNT(mail_id) AS total_mails FROM mail
                       WHERE receiver = " .$conn->qStr($_SESSION['username']). " AND status = '1' AND readed = '0'";
    $rs             = $conn->execute($sql);
    $mails_count    = $rs->fields['total_mails'];
    $smarty->assign('requests_count', $requests_count);
    $smarty->assign('mails_count', $mails_count);
}

$user_permisions = array(
	'watch_normal_videos',
	'watch_hd_videos',
	'bandwidth',
	'sd_downloads',
	'hd_downloads',
	'mobile_downloads',
	'in_player_ads',
	'write_in_blog',
	'upload_video',
);
$new_permisions = array();
if (!isset($_SESSION['uid'])) {
	// user is guest
	$type_of_user = "guest";
	foreach ($user_permisions as $v) {
		if ($v != 'upload_video' && $v != 'write_in_blog')		
		$new_permisions[$v] = $config['visitors_'.$v];
	}	

} 
elseif (!isset($_SESSION['uid_premium']) && isset($_SESSION['uid'])) {
	// free user
	$type_of_user = "free";
	foreach ($user_permisions as $v) {
		$new_permisions[$v] = $config['free_'.$v];
	}	

} 
else {
	// premium user
	$type_of_user = "premium";
	foreach ($user_permisions as $v) {
		$new_permisions[$v] = $config['premium_'.$v];
	}	

}

if (defined('_ADMIN')) {

	$sub_menu = '';
	
	$plugin_files = scandir($config['BASE_DIR']. '/templates/backend/'.$config['template_admin'].'/leftmenu/plugins/');
	foreach ($plugin_files as $k => $v) {
		if ($v == '.' || $v == '..') {
			unset($plugin_files[$k]);
		}
	}
	$plugin_files = array_values($plugin_files);
	$smarty->assign('plugin_files', $plugin_files);

	//-Notifications

	$sql                       = "SELECT COUNT(*) AS total FROM video AS v, video_flags AS f WHERE v.VID = f.VID;";
	$rs                        = $conn->execute($sql);
	$notifications[0]['type1'] = 'Videos';
	$notifications[0]['type2'] = 'Flagged';
	$notifications[0]['total'] = $rs->fields['total'];
	$notifications[0]['link']  = "videos.php?m=flagged&all=1";

	$sql                       = "SELECT COUNT(*) AS total FROM photos AS p, photo_flags AS f WHERE p.PID = f.PID;";
	$rs                        = $conn->execute($sql);
	$notifications[1]['type1'] = 'Photos';
	$notifications[1]['type2'] = 'Flagged';
	$notifications[1]['total'] = $rs->fields['total'];
	$notifications[1]['link']  = "albums.php?m=flagged&all=1";

	$sql                       = "SELECT COUNT(*) AS total FROM signup AS u, users_flags AS f WHERE f.UID = u.UID;";
	$rs                        = $conn->execute($sql);
	$notifications[2]['type1'] = 'Users';
	$notifications[2]['type2'] = 'Flagged';
	$notifications[2]['total'] = $rs->fields['total'];
	$notifications[2]['link']  = "users.php?m=flagged&all=1";

	$sql                       = "SELECT COUNT(*) AS total FROM spam WHERE type = 'video'";
	$rs                        = $conn->execute($sql);
	$notifications[3]['type1'] = 'Videos';
	$notifications[3]['type2'] = 'Comment Spam';
	$notifications[3]['total'] = $rs->fields['total'];
	$notifications[3]['link']  = "videos.php?m=spam";

	$sql                       = "SELECT COUNT(*) AS total FROM spam WHERE type = 'photo'";
	$rs                        = $conn->execute($sql);
	$notifications[4]['type1'] = 'Photos';
	$notifications[4]['type2'] = 'Comment Spam';
	$notifications[4]['total'] = $rs->fields['total'];
	$notifications[4]['link']  = "albums.php?m=spam";

	$sql                       = "SELECT COUNT(*) AS total FROM spam WHERE type = 'wall'";
	$rs                        = $conn->execute($sql);
	$notifications[5]['type1'] = 'Users';
	$notifications[5]['type2'] = 'Comment Spam';
	$notifications[5]['total'] = $rs->fields['total'];
	$notifications[5]['link']  = "users.php?m=spam";

	$sql                       = "SELECT COUNT(*) AS total FROM spam WHERE type = 'notice'";
	$rs                        = $conn->execute($sql);
	$notifications[6]['type1'] = 'Notices';
	$notifications[6]['type2'] = 'Comment Spam';
	$notifications[6]['total'] = $rs->fields['total'];
	$notifications[6]['link']  = "notices.php?m=spam";

	$n_total = 0;
	foreach ($notifications as $notification) {
		if ($notification['total'] > 0) {
			$n_total++;
		}
	}

	$smarty->assign('notifications', $notifications);
	$smarty->assign('n_total', $n_total);	
	
} else {	
	$sql            = "SELECT VID, title, duration, addtime, thumb, thumbs, thumbnails_opt, vthumbs, viewnumber, rate, likes, dislikes, type, hd
					   FROM video WHERE featured='yes' ORDER BY RAND() DESC LIMIT 8";
	$rs             = $conn->execute($sql);
	$featured       = $rs->getrows();
	$smarty->assign('featured_videos_sm', $featured);

	$sql            = "SELECT * FROM channel ORDER BY total_videos DESC LIMIT 8";
	$rs             = $conn->execute($sql);
	$categories_sm  = $rs->getrows();
	foreach ($categories_sm as $k => $v) {
		$imgPath = $config['BASE_DIR'] . '/media/categories/video/' . intval($v['CHID']) . '.jpg';
		if (file_exists($imgPath) && is_file($imgPath)) {
			$categories_sm[$k]['cover_url'] = $config['BASE_URL'] . '/media/categories/video/' . intval($v['CHID']) . '.jpg';
		} else {
			$categories_sm[$k]['cover_url'] = $config['BASE_URL'] . '/media/categories/default.jpg';
		}
	}
	$smarty->assign('categories_sm', $categories_sm);
	
	$sql = "SELECT * FROM tags WHERE LENGTH(tag) > 2 AND counter >= 1 ORDER BY counter DESC LIMIT 48";
	$rs             = $conn->execute($sql);
	$tags_sm  = $rs->getrows();
	$smarty->assign('tags_sm', $tags_sm);	
	
	$sql            = "SELECT expression, total FROM suggestion WHERE 1 ORDER BY total DESC LIMIT 1000";
	$rs             = $conn->execute($sql);	
	$suggestion_arr = $rs->getrows();
	$suggestion = array();	
	foreach ($suggestion_arr as $k => $v) {
		$suggestion[] = "{name: '".$v['expression']."', type: '".$v['total']."'}";
	} 
	$suggestion = "[".implode(",", $suggestion)."]";
	$smarty->assign('suggestion', $suggestion);	
	$smarty->assign('suggestion_arr', $suggestion_arr);		
	$current_url = ( isset($_SERVER['REQUEST_URI']) ) ? $_SERVER['REQUEST_URI'] : NULL;	
	if (strpos(strtolower($current_url), 'signup') !== false || strpos(strtolower($current_url), 'login') !== false) {
		$current_url = '';
	}
	$smarty->assign('current_url', $current_url);	
}

$smarty->assign('view', false);

?>
