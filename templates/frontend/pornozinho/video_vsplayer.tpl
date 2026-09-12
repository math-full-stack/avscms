<link rel="stylesheet" href="{$baseurl}/media/player/vidstack/vidstack.css?ver=1.0.0">
<div class="vidstack-wrap" id="vs-player">
	<div class="vs-error" style="display:none;"></div>
</div>
<script>
{literal}
window.__vidstack = {
{/literal}
	poster: "{insert name=thumb_path vid=$video.VID}/default.jpg",
	title: "{$video.title|escape:'javascript'|escape:'html'}",
	autoplay: {if $smarty.get.autoplay == '1' || $player.autoplay}true{else}false{/if},
	start_muted: {if $player.start_muted == '1'}true{else}false{/if},
	src: [
		{if $video.iphone == 1}
			{if $video.hd == 1}{literal}{{/literal} src: "{$video.hd_url}", type: "video/mp4", label: "1080p" {literal}}{/literal},{/if}
			{literal}{{/literal} src: "{$video.iphone_url}", type: "video/mp4", label: "720p" {literal}}{/literal}
		{else}
			{section name=i loop=$video.files}
				{literal}{{/literal} src: "{$video.files[i].url}", type: "video/{$video.files[i].format}", label: "{$video.files[i].height}p" {literal}}{/literal}{if !$smarty.section.i.last},{/if}
			{/section}
		{/if}
	]
{literal}
};
{/literal}
{literal}
window.__vsReady = false;
setTimeout(function () {
	if (window.__vsReady) return;
	var box = document.querySelector('#vs-player .vs-error');
	if (box && !box.textContent) {
		box.textContent = 'O player não carregou. Verifique sua conexão ou desative bloqueadores e recarregue a página.';
		box.style.display = '';
	}
}, 10000);
{/literal}
</script>
<script type="module" src="{$baseurl}/media/player/vidstack/vidstack-player.js?ver=1.0.0"></script>