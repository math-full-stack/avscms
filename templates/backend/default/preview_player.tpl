<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>{$title|escape:'html'}</title>
	<link href="{$baseurl}/media/player/videojs/video-js.css" rel="stylesheet">	
	<link href="{$baseurl}/media/player/videojs/video-js-custom.css?ver=1.0.6" rel="stylesheet">
	<script src="{$baseurl}/media/player/videojs/video.js"></script>
	<style>
		html, body {
			margin: 0;
			padding: 0;
			width: 100%;
			height: 100%;
			background-color: #000;
			overflow: hidden;
		}
		.player-wrapper {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.video-js {
			width: 100% !important;
			height: 100% !important;
		}
		.embed-responsive-frame {
			width: 100%;
			height: 100%;
			border: 0;
		}
	</style>
</head>
<body>
	<div class="player-wrapper">
		{* Prioriza embed do YouTube (youtube-nocookie) por ser estável, sem expiração e sem CORS. Stream direto (src) usado apenas como fallback *}
		{if $embed_url != ''}
			<iframe class="embed-responsive-frame" src="{$embed_url|escape:'html'}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
		{elseif $src != ''}
			<video id="preview_vplayer" class="video-js vjs-16-9 vjs-big-play-centered vjs-sublime-skin" controls autoplay preload="auto" poster="{$poster|escape:'html'}" data-setup='{literal}{"fluid": true, "autoplay": true}{/literal}'>
				<source src="{$src|escape:'html'}" type="video/mp4" label="HD" res="720">
				<p class="vjs-no-js">Para assistir a este vídeo, por favor habilite o JavaScript no seu navegador.</p>
			</video>
		{else}
			<div style="color: #fff; text-align: center; font-family: sans-serif; padding: 20px;">
				<p>Fonte de vídeo indisponível para pré-visualização.</p>
				{if $title}<p style="font-size:12px;opacity:0.7">{$title|escape:'html'}</p>{/if}
			</div>
		{/if}
	</div>
</body>
</html>
