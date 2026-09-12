<div class="avs-player" id="avs-player" data-autoplay="{if $smarty.get.autoplay == '1'}1{elseif $player.autoplay}1{else}0{/if}" data-poster="{insert name=thumb_path vid=$video.VID}/default.jpg" data-source-w="{$video.width_sd}" data-source-h="{$video.height_sd}" data-vast-enabled="{if $vast_vpaid && $player.vast_vpaid_adv}1{else}0{/if}" data-vast-url="{if $vast_vpaid}{$vast_vpaid.adtagurl}{/if}" data-vast-cancel="{if $vast_vpaid}{$vast_vpaid.adscanceltimeout}{/if}">
	<canvas></canvas>
	{if $smarty.get.autoplay == '1' || $player.autoplay}
	<img class="avs-poster" src="{insert name=thumb_path vid=$video.VID}/default.jpg" alt="">
	{else}
	<div class="avs-poster avs-cover">{insert name=video_trio vid=$video.VID thumb=$video.thumb thumbs=$video.thumbs opt=$video.thumbnails_opt title=$video.title type=$video.type}</div>
	<video class="avs-preview-video" muted loop autoplay playsinline preload="auto">
		<source src="{insert name=thumb_path vid=$video.VID}/video.webm" type="video/webm">
		<source src="{insert name=thumb_path vid=$video.VID}/video.mp4" type="video/mp4">
	</video>
	<button type="button" class="avs-big-play" title="Play">&#9654;</button>
	{/if}
	<div class="avs-controls">
		<button type="button" class="avs-btn" data-action="play" title="Play / Pause">&#9654;</button>
		<span class="avs-time avs-current">00:00</span>
		<div class="avs-seek" title="Seek">
			<div class="avs-seek-track"></div>
			<div class="avs-seek-buffer"></div>
			<div class="avs-seek-fill"><span class="avs-seek-handle"></span></div>
		</div>
		<span class="avs-time avs-duration">00:00</span>
		<select class="avs-quality" style="display:none;" title="Quality"></select>
		<button type="button" class="avs-btn" data-action="volume" title="Mute">&#128266;</button>
		<button type="button" class="avs-btn" data-action="fullscreen" title="Fullscreen">&#9974;</button>
	</div>
	<div class="avs-error" style="display:none;"></div>
</div>
{if $player.timeline_preview}<link rel="preload" as="image" href="{insert name=thumb_path vid=$video.VID}/sprite.jpg">{/if}
<link rel="stylesheet" href="{$baseurl}/media/player/mediabunny/avs-player.css?ver=2.4.9">
<script type="module" src="{$baseurl}/media/player/mediabunny/avs-player.js?ver=2.4.9"></script>
<script>
{literal}
window.__avsReady = false;
setTimeout(function () {
	if (window.__avsReady) return;
	var box = document.querySelector('#avs-player .avs-error');
	if (box && box.style.display === 'none' && !box.textContent) {
		box.textContent = 'O player não carregou. Verifique sua conexão ou desative bloqueadores e recarregue a página.';
		box.style.display = '';
	}
}, 10000);
{/literal}
</script>
