{* Faixa de anúncio full-width intercalada no grid de vídeos (entre as paginações).
   Recebe `group` com o nome do grupo (ex.: index_feed, videos_feed).
   Usada pela home (index.tpl + include/ajax/home_feed.php) e pela listagem
   (videos.tpl) — se mexer aqui, vale para os três. *}
<div class="col-12 xb-feed-ad">
	{insert name=adv assign=adv group=$group}
	{if $adv.ad}
	<div class="ad-content">
		{$adv.ad}
	</div>
	{elseif $adv.help}
	<div class="ad-body" style="width:{$adv.width}px;">
		<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">{$group|upper}</span></p>
		<p class="ad-size">Auto &times; Auto</p>
	</div>
	{/if}
</div>