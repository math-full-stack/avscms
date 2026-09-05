<!DOCTYPE html>
<html lang="pt-BR">
{if $view}
	<head prefix="og: http://ogp.me/ns#">
{else}
	<head>
{/if}
	{if $view}
		{assign var='vtags' value=$video.keyword}
	
		<meta property="og:site_name" content="{$site_name}">
		<meta property="og:title" content="{$video.title|escape:'html'}">
		<meta property="og:url" content="{$baseurl}/video/{$video.VID}/{$video.title|clean}">
		<meta property="og:type" content="video">
		<meta property="og:image" content="{insert name=thumb_path vid=$video.VID}/{if $video.embed_code != ''}1{else}default{/if}.jpg">
		<meta property="og:description" content="{if $video.description}{$video.description|escape:'html'}{else}{$video.title|escape:'html'}{/if}">
	{section name=i loop=$vtags}
	<meta property="video:tag" content="{$vtags[i]}">
	{/section}			
		{if !$video.embed_code}	
			{include file='player_settings.tpl'}	
		{/if}
	{/if}

    <title>{if isset($self_title) && $self_title != ''}{$self_title|escape:'html'}{else}{$site_name}{/if}</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=1280, initial-scale=1, maximum-scale=1, user-scalable=no">	
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="robots" content="index, follow" />
    <meta name="revisit-after" content="1 days" />
    <meta name="keywords" content="{if isset($self_keywords) && $self_keywords != ''}{$self_keywords|escape:'html'}{else}{$meta_keywords}{/if}" />
    <meta name="description" content="{if isset($self_description) && $self_description != ''}{$self_description|escape:'html'}{else}{$meta_description}{/if}" />

	<link rel="Shortcut Icon" type="image/ico" href="{$baseurl}/images/favicons/favicon.ico" />
	<link rel="apple-touch-icon" sizes="57x57" href="{$baseurl}/images/favicons/apple-icon-57x57.png">
	<link rel="apple-touch-icon" sizes="60x60" href="{$baseurl}/images/favicons/apple-icon-60x60.png">
	<link rel="apple-touch-icon" sizes="72x72" href="{$baseurl}/images/favicons/apple-icon-72x72.png">
	<link rel="apple-touch-icon" sizes="76x76" href="{$baseurl}/images/favicons/apple-icon-76x76.png">
	<link rel="apple-touch-icon" sizes="114x114" href="{$baseurl}/images/favicons/apple-icon-114x114.png">
	<link rel="apple-touch-icon" sizes="120x120" href="{$baseurl}/images/favicons/apple-icon-120x120.png">
	<link rel="apple-touch-icon" sizes="144x144" href="{$baseurl}/images/favicons/apple-icon-144x144.png">
	<link rel="apple-touch-icon" sizes="152x152" href="{$baseurl}/images/favicons/apple-icon-152x152.png">
	<link rel="apple-touch-icon" sizes="180x180" href="{$baseurl}/images/favicons/apple-icon-180x180.png">
	<link rel="icon" type="image/png" sizes="192x192"  href="{$baseurl}/images/favicons/android-icon-192x192.png">
	<link rel="icon" type="image/png" sizes="32x32" href="{$baseurl}/images/favicons/favicon-32x32.png">
	<link rel="icon" type="image/png" sizes="96x96" href="{$baseurl}/images/favicons/favicon-96x96.png">
	<link rel="icon" type="image/png" sizes="16x16" href="{$baseurl}/images/favicons/favicon-16x16.png">
	<link rel="manifest" href="{$baseurl}/images/favicons/manifest.json">
	<meta name="msapplication-TileColor" content="#080808">
	<meta name="msapplication-TileImage" content="{$baseurl}/images/favicons/ms-icon-144x144.png">
	<meta name="theme-color" content="#080808">		

    <script type="text/javascript">
    var base_url = "{$baseurl}";
	var max_thumb_folders = "{$max_thumb_folders}";
	var thumb_cdn_base = "{insert name=gcs_thumbs_base}";
    var tpl_url = "{$relative_tpl}";
	{if isset($video.VID)}var video_id = "{$video.VID}";{/if}
	var lang_deleting = "{t c='global.deleting'}";
	var lang_flaging = "{t c='global.flaging'}";
	var lang_loading = "{t c='global.loading'}";
	var lang_sending = "{t c='global.sending'}";
	var lang_share_name_empty = "{t c='share.name_empty'}";
	var lang_share_rec_empty = "{t c='share.recipient'}";
	var fb_signin = "{$fb_signin}";
	var fb_appid = "{$fb_appid}";
	var g_signin = "{$g_signin}";
	var g_cid = "{$g_cid}";
	var signup_section = false;
	var relative = "{$relative}";
	var search_v = "{t c='ajax.search'} {t c='global.videos'}";
	var search_a = "{t c='ajax.search'} {t c='global.albums'}";
	var search_u = "{t c='ajax.search'} {t c='global.users'}";	
	var lang_global_delete 	 	 = "{t c='global.delete'}";
	var lang_global_yes 	 	 = "{t c='global.yes'}";
	var lang_global_no 		 = "{t c='global.no'}";		
	var lang_global_remove 	 	 = "{t c='global.remove'}";
	{if isset($smarty.session.uid)}
		var session_uid = "{$smarty.session.uid}";
	{else}
		var session_uid = "";	
	{/if}
	var current_url = "{$current_url}";	
	var alert_messages = {$messages|json_encode};
	var alert_errors = {$errors|json_encode};	
	</script>

    <script src="https://code.jquery.com/jquery-3.1.0.min.js" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js" integrity="sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js" integrity="sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy" crossorigin="anonymous"></script>
	
	<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">

	<link rel="stylesheet" href="{$relative_tpl}/css/easy-autocomplete.min.css"> 	
	<link rel="stylesheet" href="{$relative_tpl}/css/easy-autocomplete.themes.min.css">	
	
	<link href="{$relative_tpl}/css/style.css" rel="stylesheet">
	<link href="{$relative_tpl}/css/novinhasbr.css" rel="stylesheet">
	<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css" integrity="sha384-fnmOCqbTlWIlj8LyTjo7mOUStjsKC4pOpQbqyi7RrhN7udi9RwhKkMHpvLbHG9Sr" crossorigin="anonymous">
	
	<!-- Video Player -->
	{if $view && !$video.embed_code}
		<link href="{$baseurl}/media/player/videojs/video-js.css" rel="stylesheet">	
		<link href="{$baseurl}/media/player/videojs/plugins/videojs-resolution-switcher-master/lib/videojs-resolution-switcher.css" rel="stylesheet">		
		<link href="{$baseurl}/media/player/videojs/plugins/videojs-logobrand-master/src/videojs.logobrand.css" rel="stylesheet">
		<link href="{$baseurl}/media/player/videojs/plugins/videojs-thumbnails-master/videojs.thumbnails.css" rel="stylesheet">
		<link href="{$baseurl}/media/player/videojs/video-js-custom.css" rel="stylesheet">					
		{if $vast_vpaid && $player.vast_vpaid_adv}
			<link href="{$baseurl}/media/player/videojs/plugins/videojs-vast-vpaid-master/bin/videojs.vast.vpaid.css" rel="stylesheet">			
		{/if}
		<script src="{$baseurl}/media/player/videojs/ie8/videojs-ie8.min.js"></script>
		<script src="{$baseurl}/media/player/videojs/video.js"></script>
		{if $vast_vpaid && $player.vast_vpaid_adv}
			<script src="{$baseurl}/media/player/videojs/plugins/videojs-vast-vpaid-master/bin/es5-shim.js"></script>				
			<script src="{$baseurl}/media/player/videojs/plugins/videojs-vast-vpaid-master/bin/ie8fix.js"></script>			
			<script src="{$baseurl}/media/player/videojs/plugins/videojs-vast-vpaid-master/bin/videojs_5.vast.vpaid.min.js"></script>				
		{/if}
		<script src="{$baseurl}/media/player/videojs/plugins/videojs-resolution-switcher-master/lib/videojs-resolution-switcher.js"></script>
		<script src="{$baseurl}/media/player/videojs/plugins/videojs-logobrand-master/src/videojs.logobrand.js"></script>
		<script src="{$baseurl}/media/player/videojs/plugins/videojs-thumbnails-master/videojs.thumbnails.js"></script>
	{/if}	
	<!-- End Video Player -->
	{if $menu == 'blogs'}
		<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.15/dist/summernote-lite.min.css" rel="stylesheet">
		<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.15/dist/summernote-lite.min.js"></script>
	{/if}
	
</head>
<body>

<div class="modal fade in" id="login-modal">
	<div class="modal-dialog login-modal">
		<div class="modal-content">
			<form name="login_form" method="post" action="{$relative}/login">	
				<div class="modal-header">
					<h4 class="modal-title">{t c='signup.login'}</h4>				
					<button type="button" class="close" data-dismiss="modal">&times;</button>		
				</div>
				<div class="modal-body">
					<input name="current_url" type="hidden" value="{$current_url}"/>
					{if $fb_signin == '1'}
					<div class="mb-4">
						<button id="facebook-signin" class="btn btn-facebook" disabled><div></div><i class="fab fa-facebook-f"></i> <span>{t c='socialsignup.login_with'} Facebook</span></button>
					</div>
					{/if}
					{if $g_signin == '1'}						
					<div class="mb-4">
						<button id="google-signin" class="btn btn-google" disabled><div></div><i class="fab fa-google-plus-g"></i> <span>{t c='socialsignup.login_with'} Google</span></button>
					</div>
					{/if}
					<input name="username" type="text" value="" id="login_username" class="form-control mb-3" placeholder="{t c='global.username'}"/>
					<input name="password" type="password" value="" id="login_password" class="form-control mb-3" placeholder="{t c='global.password'}"/>
					<a href="{$relative}/lost" id="lost_password">{t c='global.forgot'}</a><br />
					<a href="{$relative}/confirm" id="confirmation_email">{t c='global.confirm'}</a>		
				</div>
				<div class="modal-footer">
					<button name="submit_login" id="login_submit" type="submit" class="btn btn-primary btn-bold">{t c='global.login'}</button>
					<a href="{$relative}/signup" class="btn btn-secondary btn-bold">{translate c='global.sign_up'}</a>
				</div>
			</form>			
		</div>
    </div>
</div>

<div class="modal fade" id="dialogModal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title"></h4>
				<button type="button" class="close" data-dismiss="modal">&times;</button>
			</div>
			<div class="modal-body">	
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary btn-bold opt-1"></button>			
				<button type="button" class="btn btn-secondary btn-bold opt-2" data-dismiss="modal"></button>
			</div>
		</div>
	</div>
</div>

{if $fb_signin == '1'}
	{include file='fb_signup_modal.tpl'}
{/if}
{if $g_signin == '1'}
	{include file='g_signup_modal.tpl'}
{/if}

<div class="modal fade" id="language-modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title">{t c='global.select_language'}</h4>
				<button type="button" class="close" data-dismiss="modal">&times;</button>
			</div>
			<div class="modal-body">
				<div class="row mb-4">
				{foreach from=$languages key=key item=language }
					<div class="col-6 col-sm-4">
						{if $smarty.session.language != $key}
							<a href="#" id="{$key}" class="change-language">{$language.name}</a>
						{else}
							<span class="change-language language-active">{$language.name}</span>
						{/if}
					</div>
				{/foreach}
				</div>
			</div>
			<form name="languageSelect" id="languageSelect" method="post" action="">
			<input name="language" id="language" type="hidden" value="" />
			</form>	
		</div>
	</div>
</div>


<div class="xb-topbar">
	<div class="container xb-topbar-inner">
		<a class="xb-logo" href="{$relative}/"><img src="{$relative}/images/logo/logo.png" alt="{$site_name}"></a>

		<div class="xb-search">
			<form name="search" id="search_form" method="post" action="{$relative}/search/{if !isset($search_type)}videos{else}{$search_type}{/if}">
				<input type="text" class="xb-search-input" placeholder="{t c='ajax.search'} {if isset($search_type) && $search_type == 'photos'} {t c='global.albums'}{elseif isset($search_type) && $search_type == 'users'} {t c='global.users'}{else}{t c='global.videos'}{/if}" name="search_query" id="search_query" value="{if isset($search_query)}{$search_query_f}{/if}" autocomplete="off">
				<a id="search_select" class="xb-search-select">{if isset($search_type) && $search_type == 'photos'}<i class="fas fa-camera"></i>{elseif isset($search_type) && $search_type == 'users'}<i class="fas fa-user"></i>{else}<i class="fas fa-video"></i>{/if}</a>
				<button type="submit" class="xb-search-btn"><i class="fa fa-search"></i></button>
				<input type="hidden" id="search_type" value="{$search_type}">
			</form>
		</div>

		<div class="xb-actions">
			{if $multi_language}
				{insert name=language assign=flag}
				<a class="xb-action" data-toggle="modal" href="#language-modal">{$flag}</a>
			{/if}
			{if isset($smarty.session.uid)}
				<div class="btn-group">
					<a href="#" class="xb-action dropdown-toggle" data-toggle="dropdown" data-display="static" aria-haspopup="true" aria-expanded="false">
						<i class="fas fa-user"></i>
						<span class="xb-action-text">{$smarty.session.username|truncate:15:"..."}</span> <i class="fas fa-caret-down"></i>
					</a>
					<div class="dropdown-menu dropdown-menu-right">
						<a class="dropdown-item" href = "{$relative}/user/edit">{t c='user.edit_profile'}</a>						
						<a class="dropdown-item" href="{$relative}/user">{t c='topnav.my_profile'}</a>					
						{if $video_module == '1'}<a class="dropdown-item" href="{$relative}/user/{$smarty.session.username}/videos">{t c='topnav.my_videos'}</a>{/if}
						{if $photo_module == '1'}<a class="dropdown-item" href="{$relative}/user/{$smarty.session.username}/albums">{t c='topnav.my_photos'}</a>{/if}
						<a class="dropdown-item" href="{$relative}/user/{$smarty.session.username}/blog">{t c='topnav.my_blog'}</a>
						<a class="dropdown-item" href="{$relative}/feeds">{translate c='global.my_feeds'}</a>
						<a class="dropdown-item" href="{$relative}/requests"><span class="float-left">{translate c='global.requests'}</span>{if $requests_count > 0}<span class="badge badge-danger float-right">{$requests_count}</span>{/if}<div class="clearfix"></div></a>
						<a class="dropdown-item" href="{$relative}/mail/inbox"><span class="float-left">{translate c='global.inbox'}</span>{if $mails_count > 0}<span class="badge badge-danger float-right">{$mails_count}</span>{/if}<div class="clearfix"></div></a>
						<a class="dropdown-item" href="{$relative}/logout">{translate c='global.sign_out'}</a>					
					</div>
				</div>
			{else}
				<a class="xb-action" data-toggle="modal" href="#login-modal"><i class="fas fa-key"></i><span class="xb-action-text"> {translate c='global.login'}</span></a>
				<a class="xb-action" href="{$relative}/signup" rel="nofollow"><i class="fas fa-user-plus"></i><span class="xb-action-text"> {translate c='global.sign_up'}</span></a>
			{/if}
			{if $video_module == '1'}
				<a class="xb-action xb-action-upload" href="{$relative}/upload"><i class="fas fa-upload"></i><span> {translate c='menu.upload'}</span></a>
			{/if}
			<button class="xb-hamburger d-lg-none" type="button" data-toggle="collapse" data-target="#xbNav" aria-controls="xbNav" aria-expanded="false" aria-label="Toggle navigation"><i class="fas fa-bars"></i></button>
		</div>
	</div>
</div>

<nav class="xb-nav collapse d-lg-block" id="xbNav">
	<div class="container">
		<form class="xb-mobilesearch" name="search" id="search_form_xs" method="post" action="{$relative}/search/{if !isset($search_type)}videos{else}{$search_type}{/if}">
			<input type="text" class="xb-search-input" placeholder="{t c='ajax.search'} {if isset($search_type) && $search_type == 'photos'} {t c='global.albums'}{elseif isset($search_type) && $search_type == 'users'} {t c='global.users'}{else}{t c='global.videos'}{/if}" name="search_query" id="search_query_xs" value="{if isset($search_query)}{$search_query_f}{/if}" autocomplete="off">
			<a id="search_select_xs" class="xb-search-select">{if isset($search_type) && $search_type == 'photos'}<i class="fas fa-camera"></i>{elseif isset($search_type) && $search_type == 'users'}<i class="fas fa-user"></i>{else}<i class="fas fa-video"></i>{/if}</a>
			<button type="submit" class="xb-search-btn"><i class="fa fa-search"></i></button>
			<input type="hidden" id="search_type_xs" value="{$search_type}">
		</form>
		<ul>
			<li class="{if $menu == 'home'}active{/if}"><a href="{$relative}/">{translate c='menu.home'}</a></li>
			<li><a href="{$relative}/videos?o=mv">Em alta</a></li>
			<li><a href="{$relative}/videos?o=mr">Novos</a></li>
			{if $video_module == '1'}
				<li class="{if $menu == 'videos'}active{/if}"><a href="{$relative}/videos">{translate c='menu.videos'}</a></li>
			{/if}
			<li class="{if $menu == 'categories'}active{/if}"><a href="{$relative}/categories">{translate c='menu.categories'}</a></li>
			<li><a href="{$relative}/users">Creators</a></li>
			<li class="{if $menu == 'tags'}active{/if}"><a href="{$relative}/tags">{translate c='menu.tags'}</a></li>
			{if $photo_module == '1'}
				<li class="{if $menu == 'albums'}active{/if}"><a href="{$relative}/albums">{translate c='menu.photos'}</a></li>
			{/if}
			{if $blog_module == '1'}
				<li class="{if $menu == 'blogs'}active{/if}"><a href="{$relative}/blogs">{translate c='menu.blogs'}</a></li>
			{/if}
			{if $community_module == '1'}
				<li class="{if $menu == 'community'}active{/if}"><a href="{$relative}/community">{translate c='menu.community'}</a></li>
			{/if}
		</ul>
	</div>
</nav>
<div id="wrapper">