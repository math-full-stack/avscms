<div class="avs-player" id="avs-player" data-autoplay="{if $smarty.get.autoplay == '1'}1{elseif $player.autoplay}1{else}0{/if}" data-poster="{insert name=thumb_path vid=$video.VID}/default.jpg" data-vast-enabled="{if $vast_vpaid && $player.vast_vpaid_adv}1{else}0{/if}" data-vast-url="{if $vast_vpaid}{$vast_vpaid.adtagurl}{/if}" data-vast-cancel="{if $vast_vpaid}{$vast_vpaid.adscanceltimeout}{/if}">
	<canvas></canvas>
	<img class="avs-poster" src="{insert name=thumb_path vid=$video.VID}/default.jpg" alt="">
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
<link rel="stylesheet" href="{$baseurl}/media/player/mediabunny/avs-player.css?ver=2.3.2">
<script type="module" src="{$baseurl}/media/player/mediabunny/avs-player.js?ver=2.3.3"></script>
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
