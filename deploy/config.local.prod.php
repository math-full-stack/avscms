<?php
defined('_VALID') or die('Restricted Access!');
// ============================================================
// AVSCMS production config.local.php — VM (pornozinho-vm)
// ------------------------------------------------------------
// Este arquivo é estático: não contém segredos. O GitHub Action copia
// este arquivo para include/config.local.php na VM. Segredos (DB, admin,
// GCS) vêm do ambiente via include/dotenv.php, que lê /etc/avscms/.env
// (fora do webroot) ou variáveis do ambiente real.
//
// A VM atua com worker_role = 'web' (serve páginas, não converte).
// A conversão/FFmpeg roda no PC local (worker_role = 'converter'),
// conectado à mesma Cloud SQL via túnel Linux/socat.
// ============================================================

// --- Site / domínio ---
// NOTE: BASE_URL/RELATIVE e todos os *.URL são calculados em
// include/config.paths.php a partir da env var SITE_BASE_URL
// (definida via Apache SetEnv na VM = https://pornozinho.com).
// Não sobrescreva aqui para manter consistência com IMG_URL etc.
$config['site_name']  = 'Pornozinho';
$config['site_title'] = 'Pornozinho';
$config['admin_name'] = 'admin';
$config['admin_pass'] = getenv('ADMIN_PASS') ?: '';
$config['noreply_email'] = 'noreply@pornozinho.com';
$config['admin_email']   = 'admin@pornozinho.com';
$config['emailsender']   = 'Pornozinho';
$config['meta_description'] = 'Free Porn Videos';
$config['meta_keywords']    = 'porn, sex, porno, free porn, porn tube, free streaming porn, full sex videos';

// --- Tool paths (VM Debian 13) ---
$config['phppath']  = '/usr/bin/php';
$config['ffmpeg']   = '/usr/bin/ffmpeg';
$config['ffprobe']  = '/usr/bin/ffprobe';
$config['thumbs_tool'] = 'ffmpeg';

// --- Processamento: VM é 'web' (só serve). PC local converte. ---
$config['processor']   = 'ffmpeg';
$config['worker_role'] = 'web';

// --- Performance / conversão ---
$config['vbitrate']   = '500';
$config['sbitrate']   = '22050';
$config['vresize']    = '1';
$config['vresize_x']  = '1280';
$config['vresize_y']  = '720';
$config['hd_convert'] = '1';
$config['iphone_convert'] = '1';
$config['flv_convert']    = '0';
$config['log_conversion'] = '1';
$config['vthumbs']    = '1';
$config['vthumb_width'] = '960';
$config['vthumb_height'] = '540';
$config['thumbnail_player_width'] = '1920';
$config['thumbnail_player_height'] = '1080';
$config['thumbnail_remove_bb'] = '1';
$config['thumbnail_keep_ar'] = '1';
$config['watermark_image'] = 'media/player/logo/logo.png';

// --- Paginação / exibição ---
$config['videos_per_page'] = '32';
$config['albums_per_page'] = '22';
$config['users_per_page'] = '15';
$config['blogs_per_page'] = '10';
$config['watched_per_page'] = '18';
$config['recent_per_page'] = '30';
$config['items_per_front_page'] = '24';

// --- Upload ---
$config['max_img_size'] = '200';
$config['img_max_width'] = '1920';
$config['img_max_height'] = '1080';
$config['max_display_size'] = '400';
$config['max_video_size'] = '300000000';
$config['video_max_size'] = '20';
$config['video_allowed_extensions'] = 'avi,mpg,mov,asf,mpeg,xvid,divx,3gp,mkv,3gpp,mp4,rmvb,rm,dat,wmv,flv,ogg,ogv,webm';
$config['image_allowed_extensions'] = 'jpg,jpeg,png,gif,bmp';
$config['image_max_size'] = '100000000000000';

// --- Moderação / flags ---
$config['approve'] = '0';
$config['approve_photos'] = '0';
$config['approve_blogs'] = '0';
$config['downloads'] = '1';
$config['del_original_video'] = '1';
$config['pm_notify'] = '1';
$config['user_registration'] = '1';
$config['email_verification'] = '1';
$config['video_view'] = 'all';
$config['video_comments'] = '1';
$config['private_msgs'] = 'all';
$config['video_module'] = '1';
$config['friends_module'] = '1';
$config['groups_module'] = '1';
$config['upload_module'] = '1';
$config['channels_module'] = '1';
$config['community_module'] = '1';
$config['photo_module'] = '0';
$config['blog_module'] = '0';
$config['show_private_videos'] = '1';
$config['show_private_albums'] = '1';

// --- Email / SMTP ---
$config['mailer'] = 'mail';
$config['sendmail'] = '/usr/sbin/sendmail';
$config['smtp'] = '';
$config['smtp_auth'] = '0';
$config['smtp_username'] = '';
$config['smtp_password'] = '';
$config['smtp_port'] = '';
$config['smtp_prefix'] = '';
$config['smtp_autotls'] = '1';
$config['smtp_debug'] = '0';

// --- Sessão ---
$config['session_lifetime'] = '1440';
$config['session_driver'] = 'database';
$config['gzip_encoding'] = '1';
$config['user_remember'] = '1';
$config['submenu_tag_scroller'] = '1';

// --- SEO ---
$config['seo_urls'] = '';
$config['index_title'] = 'Free Porn Videos';
$config['offline'] = '0';
$config['force_utf8'] = '1';

// --- Idiomas / template ---
$config['language'] = 'pt_BR';
$config['multi_language'] = '0';
$config['template'] = 'pornozinho';
$config['template_admin'] = 'default';

// --- Redes sociais ---
$config['facebook_id'] = 'fb_avs';
$config['insgram_id'] = '';
$config['twitter_id'] = 'twitter_avs';
$config['reddit_id'] = 'reddit_avs';
$config['instagram_id'] = 'instagram_avs';

// --- Streaming ---
$config['streaming_method'] = 'progressive_download';

// --- Layout ---
$config['max_col'] = '5';
$config['min_col'] = '2';

// --- Grabber (só web; conversão no PC) ---
$config['grabber'] = '1';
$config['grabber_auth_mode'] = '3';
$config['grabber_player_client'] = 'mweb';
$config['grabber_js_runtime'] = 'node';
$config['grabber_cookies'] = '';
$config['grabber_cookies_browser'] = '';

// --- Recaptcha / login social ---
$config['captcha'] = '0';
$config['captcha_level'] = 'easy';
$config['fb_signin'] = '0';
$config['fb_appid'] = '';
$config['g_signin'] = '0';
$config['g_cid'] = '';
$config['recaptcha_site_key'] = '';
$config['recaptcha_secret_key'] = '';

// --- Google Cloud Storage (media via bucket pornozinho-cdn1) ---
$config['gcs_enabled'] = '1';
$config['gcs_bucket'] = 'pornozinho-cdn1';
$config['gcs_streaming_url'] = 'https://storage.googleapis.com/pornozinho-cdn1';
// Chave GCS fica FORA do webroot (/etc/avscms/gcs-service-account.json na VM)
// ou inline via env GCS_KEY_JSON (Cloud Run). Nunca dentro de include/ (exposto).
$config['gcs_key_path'] = getenv('GCS_KEY_PATH') ?: '/etc/avscms/gcs-service-account.json';
$config['gcs_acl'] = 'publicRead';
$config['gcs_cache_control'] = 'public, max-age=31536000';

// --- Rejeitos: conversão em lote (feita pelo PC) ---
$config['conversion_q'] = '0';
$config['q_limit'] = '4';
$config['q_timeout'] = '6';
$config['max_thumb_folders'] = '32000';
$config['splash'] = '0';
$config['lighttpd'] = '0';
$config['multiserver'] = '0';
$config['multi_server'] = '1';
$config['edit_videos'] = '1';
$config['use_feeds'] = '1';
$config['guest_limit'] = '0';
$config['guest_bandwidth'] = '50';
$config['delete_photos'] = '1';
$config['delete_videos'] = '0';
$config['delete_album'] = '1';
$config['plugin_login'] = '1';
$config['plugin_statistics'] = '1';
$config['plugin_tag_cloud'] = '1';
$config['plugin_ptag_cloud'] = '1';
$config['plugin_gtag_cloud'] = '1';
$config['plugin_vtag_cloud'] = '1';

// --- Permissões visitante / free / premium ---
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
$config['video_rate'] = 'user';
$config['rating'] = 'user';
$config['photo_rate'] = 'user';
$config['user_rate'] = 'user';
$config['comment_rate'] = 'user';
$config['video_embed'] = '1';
$config['photo_comments'] = '1';
$config['blog_comments'] = '1';
$config['wall_comments'] = '1';
$config['notice_comments'] = '1';
$config['video_rate'] = 'user';
$config['ads'] = '1';

// --- OpenSSL / segurança: NULL (não usado aqui) ---
$config['neroaacenc'] = '';
$config['mp4box'] = '';
$config['mediainfo'] = '';
$config['ftp_host'] = '';
$config['ftp_username'] = '';
$config['ftp_password'] = '';
$config['ftp_root'] = '';
