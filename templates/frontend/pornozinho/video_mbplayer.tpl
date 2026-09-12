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
	<button type="button" class="avs-big-play" title="Play">
		<svg class="avs-icon avs-icon-xl" aria-hidden="true"><use href="#avs-i-play"></use></svg>
	</button>
	{/if}
	<svg class="avs-sprite" aria-hidden="true">
		<symbol id="avs-i-play" viewBox="0 0 24 24">
			<path fill="currentColor" d="M8 5v14l11-7z"/>
		</symbol>
		<symbol id="avs-i-pause" viewBox="0 0 24 24">
			<path fill="currentColor" d="M6 5h4v14H6zM14 5h4v14h-4z"/>
		</symbol>
		<symbol id="avs-i-vol-high" viewBox="0 0 24 24">
			<path fill="currentColor" d="M3 9v6h4l5 4V5L7 9H3z"/>
			<path fill="currentColor" d="M16.2 8.3a4.8 4.8 0 0 1 0 7.4l-1.2-1.4a2.9 2.9 0 0 0 0-4.6z"/>
			<path fill="currentColor" d="M18.7 5.7a8.4 8.4 0 0 1 0 12.6l-1.2-1.4a6.5 6.5 0 0 0 0-9.8z"/>
		</symbol>
		<symbol id="avs-i-vol-low" viewBox="0 0 24 24">
			<path fill="currentColor" d="M3 9v6h4l5 4V5L7 9H3z"/>
			<path fill="currentColor" d="M16.2 8.3a4.8 4.8 0 0 1 0 7.4l-1.2-1.4a2.9 2.9 0 0 0 0-4.6z"/>
		</symbol>
		<symbol id="avs-i-vol-mute" viewBox="0 0 24 24">
			<path fill="currentColor" d="M3 9v6h4l5 4V5L7 9H3z"/>
			<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M16.5 8l4 8M20.5 8l-4 8"/>
		</symbol>
		<symbol id="avs-i-settings" viewBox="0 0 24 24">
			<circle cx="12" cy="12" r="3.2"/>
			<g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
				<path d="M12 2.5v3.2M12 18.3v3.2M2.5 12h3.2M18.3 12h3.2"/>
				<path d="M5 5l2.3 2.3M16.7 16.7 19 19M5 19l2.3-2.3M16.7 7.3 19 5"/>
			</g>
		</symbol>
		<symbol id="avs-i-fs-enter" viewBox="0 0 24 24">
			<g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
				<path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/>
			</g>
		</symbol>
		<symbol id="avs-i-fs-exit" viewBox="0 0 24 24">
			<g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
				<path d="M8 3v5H3M16 3v5h5M8 21v-5H3M16 21v-5h5"/>
			</g>
		</symbol>
		<symbol id="avs-i-rw10" viewBox="0 0 24 24">
			<path fill="currentColor" d="M10.5 8.4 6.6 12l3.9 3.6z"/>
			<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" d="M14.8 8.4v7.2"/>
			<circle cx="18.2" cy="12" r="2.1" fill="none" stroke="currentColor" stroke-width="1.6"/>
		</symbol>
		<symbol id="avs-i-fw10" viewBox="0 0 24 24">
			<path fill="currentColor" d="M6.4 12l3.9-3.6v7.2z"/>
			<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" d="M14.2 8.4v7.2"/>
			<circle cx="17.6" cy="12" r="2.1" fill="none" stroke="currentColor" stroke-width="1.6"/>
		</symbol>
		
	</svg>
	<div class="avs-controls">
		<div class="avs-seek" role="slider" aria-label="Seek" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" title="Seek">
			<div class="avs-seek-track"></div>
			<div class="avs-seek-buffer"></div>
			<div class="avs-seek-fill"><span class="avs-seek-handle"></span></div>
		</div>
		<div class="avs-controls-row">
			<div class="avs-controls-left">
				<button type="button" class="avs-btn" data-action="play" title="Play / Pausar">
					<svg class="avs-icon" aria-hidden="true"><use href="#avs-i-play"></use></svg>
				</button>
				<div class="avs-volume-wrap">
					<button type="button" class="avs-btn" data-action="volume" title="Mudo">
						<svg class="avs-icon" aria-hidden="true"><use href="#avs-i-vol-high"></use></svg>
					</button>
					<input type="range" class="avs-volume-slider" min="0" max="100" step="1" value="80" title="Volume" aria-label="Volume">
				</div>
				<span class="avs-time avs-current">00:00</span>
				<span class="avs-time avs-sep">/</span>
				<span class="avs-time avs-duration">00:00</span>
			</div>
			<div class="avs-controls-right">
				<button type="button" class="avs-btn avs-settings-btn" data-action="settings" title="Configurações">
					<svg class="avs-icon" aria-hidden="true"><use href="#avs-i-settings"></use></svg>
				</button>
				<select class="avs-quality" hidden title="Quality"></select>
				<button type="button" class="avs-btn" data-action="fullscreen" title="Tela cheia">
					<svg class="avs-icon" aria-hidden="true"><use href="#avs-i-fs-enter"></use></svg>
				</button>
			</div>
		</div>
	</div>
	<div class="avs-settings" role="menu" aria-label="Configurações">
		<div class="avs-settings-head">
			<div class="avs-settings-title">
				<span class="material-symbols-rounded" aria-hidden="true">settings</span>
				<span>Configurações</span>
			</div>
			<div class="avs-settings-tabs" role="tablist" aria-label="Ajustes">
				<button type="button" class="avs-settings-tab avs-settings-tab-active" data-settings-tab="quality" role="tab" aria-selected="true">
					<span class="material-symbols-rounded" aria-hidden="true">hd</span>
					Qualidade
				</button>
				<button type="button" class="avs-settings-tab" data-settings-tab="speed" role="tab" aria-selected="false">
					<span class="material-symbols-rounded" aria-hidden="true">speed</span>
					Velocidade
				</button>
			</div>
		</div>
		<div class="avs-settings-pane avs-settings-pane-active" data-settings-group="quality" role="tabpanel" aria-label="Qualidade"></div>
		<div class="avs-settings-pane" data-settings-group="speed" role="tabpanel" aria-label="Velocidade"></div>
	</div>
	<div class="avs-center" aria-hidden="true">
		<button type="button" class="avs-center-btn avs-center-rw" title="-10 segundos">
			<svg class="avs-icon" aria-hidden="true"><use href="#avs-i-rw10"></use></svg>
		</button>
		<button type="button" class="avs-center-btn avs-center-toggle" title="Play / Pausar">
			<svg class="avs-icon avs-icon-xl" aria-hidden="true"><use href="#avs-i-play"></use></svg>
		</button>
		<button type="button" class="avs-center-btn avs-center-fw" title="+10 segundos">
			<svg class="avs-icon" aria-hidden="true"><use href="#avs-i-fw10"></use></svg>
		</button>
	</div>
	<div class="avs-error" style="display:none;"></div>
</div>
{if $player.timeline_preview}<link rel="preload" as="image" href="{insert name=thumb_path vid=$video.VID}/sprite.jpg">{/if}
<link rel="stylesheet" href="{$baseurl}/media/player/mediabunny/avs-player.css?ver=3.1.0">
<script type="module" src="{$baseurl}/media/player/mediabunny/avs-player.js?ver=3.1.0"></script>
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