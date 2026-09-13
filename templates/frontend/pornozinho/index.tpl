<div class="container mt-3 mb-3">

	{if $shorts_videos}
	<!-- Seção Shorts & Reels na Tela Inicial -->
	<div class="xb-section xb-section-shorts">
		<span class="xb-section-bar"></span>
		<h2><span class="material-symbols-rounded xb-shorts-badge-icon" aria-hidden="true">play_circle</span> Shorts &amp; Reels</h2>
		<a class="xb-section-link" href="{$relative}/shorts">Assistir no feed <i class="fas fa-chevron-right"></i></a>
	</div>

	<div class="xb-shorts-shelf-wrapper mb-4">
		<button type="button" class="xb-shelf-btn xb-shelf-prev" id="xb-shorts-prev" aria-label="Anterior"><i class="fas fa-chevron-left"></i></button>
		<div class="xb-shorts-shelf" id="xb-shorts-shelf">
			{* Mesmo idioma visual do card padrão de vídeo: thumb-overlay + chip de
			   views + duração, e nada abaixo do thumb. Mantém o box vertical 9:16
			   com capa única (o trio 16:9 do card padrão não cabe num vertical). *}
			{section name=s loop=$shorts_videos}
			<div class="xb-short-shelf-item">
				<a href="{$relative}/shorts?v={$shorts_videos[s].VID}" title="{$shorts_videos[s].title|escape:'html'}">
					<div class="thumb-overlay xb-portrait">
						<img src="{insert name=thumb_path vid=$shorts_videos[s].VID}/{$shorts_videos[s].thumb}.jpg" alt="{$shorts_videos[s].title|escape:'html'}" loading="lazy">
						<span class="xb-thumb-meta">Shorts</span>
						<div class="duration">
							{if $shorts_videos[s].hd==1}<span class="hd-text-icon">HD</span>{/if}
							{insert name=duration assign=duration duration=$shorts_videos[s].duration}
							{$duration}
						</div>
					</div>
				</a>
			</div>
			{/section}
		</div>
		<button type="button" class="xb-shelf-btn xb-shelf-next" id="xb-shorts-next" aria-label="Próximo"><i class="fas fa-chevron-right"></i></button>
	</div>

	<script>
	{literal}
	(function () {
		var shelf = document.getElementById('xb-shorts-shelf');
		var prev = document.getElementById('xb-shorts-prev');
		var next = document.getElementById('xb-shorts-next');
		if (!shelf) return;
		if (prev) {
			prev.addEventListener('click', function () {
				shelf.scrollBy({ left: -360, behavior: 'smooth' });
			});
		}
		if (next) {
			next.addEventListener('click', function () {
				shelf.scrollBy({ left: 360, behavior: 'smooth' });
			});
		}
	})();
	{/literal}
	</script>
	{/if}

	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-bolt"></i>Novos vídeos</h2>
		<a class="xb-section-link" href="{$relative}/videos?o=mr">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>

	{if $recent_videos}
	<div class="row content-row">
		{section name=i loop=$recent_videos}
			{include file='video_card.tpl' v=$recent_videos[i]}
		{/section}
	</div>
	{else}
	<div class="well well-sm">
		<span class="text-danger">{t c='videos.no_videos_found'}.</span>
	</div>
	{/if}

	{* Seção Destaques (hero) removida a pedido. O CSS .xb-hero-* ficou dormente
	   em pornozinho.css e a query $hero_videos segue em index.php sem consumidor. *}
	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-fire"></i>Em alta</h2>
		<a class="xb-section-link" href="{$relative}/videos?o=bw">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>

	{if $viewed_videos}
	<div class="row content-row">
		{section name=i loop=$viewed_videos}
			{include file='video_card.tpl' v=$viewed_videos[i]}
		{/section}
	</div>
	{else}
	<div class="well well-sm">
		<span class="text-danger">{t c='videos.no_videos_found'}.</span>
	</div>
	{/if}

	{if $random_category && $random_cat_videos}
	{assign var=random_cat_link value=$relative|cat:"/videos/"|cat:$random_category.slug}
	{include file='video_carousel.tpl' videos=$random_cat_videos title=$random_category.name icon='fa-dice' link=$random_cat_link auto_scroll=true}
	{/if}

	{if $creators}
	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-user-circle"></i>Creators</h2>
		<a class="xb-section-link" href="{$relative}/users">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>
	<div class="xb-creators">
		{section name=c loop=$creators}
		<a class="xb-creator-card" href="{$relative}/user/{$creators[c].username}">
			<img class="xb-avatar-lg" src="{$relative}/media/users/{if $creators[c].photo != ''}{$creators[c].photo}{else}nopic-{$creators[c].gender}.gif{/if}" alt="{$creators[c].username}">
			<span class="xb-creator-name">{$creators[c].username}</span>
			<span class="xb-creator-meta">{$creators[c].total_videos} {t c='global.videos'}</span>
		</a>
		{/section}
	</div>
	{/if}

	{if $categories_sm}
	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-th-large"></i>Categorias</h2>
		<a class="xb-section-link" href="{$relative}/categories">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>
	<div class="xb-cats">
		{section name=c loop=$categories_sm}
		<a class="xb-cat-card" href="{$relative}/videos/{$categories_sm[c].slug}">
			<img src="{$categories_sm[c].cover_url}" title="{$categories_sm[c].name|escape:'html'}" alt="{$categories_sm[c].name|escape:'html'}">
			<span class="xb-cat-overlay">
				<span class="xb-cat-name">{$categories_sm[c].name|escape:'html'}</span>
				<span class="xb-cat-count">{$categories_sm[c].total_videos}</span>
			</span>
		</a>
		{/section}
	</div>
	{/if}

	{if $tags_sm}
	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-hashtag"></i>Tags populares</h2>
		<a class="xb-section-link" href="{$relative}/tags">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>
	<div class="xb-tags xb-tags-lg mb-4">
		{section name=t loop=$tags_sm}
			<a href="{$relative}/search/tags/{$tags_sm[t].tag}">#{$tags_sm[t].tag}</a>
		{/section}
	</div>
	{/if}

	{insert name=adv assign=adv group='index_bottom'}
	{if $adv.ad}
	<div class="ad-content">
		{$adv.ad}
	</div>	
	{elseif $adv.help}		
		<div class="ad-body">
			<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">INDEX BOTTOM</span></p>
			<p class="ad-size">Auto &times; Auto</p>
		</div>			
	{/if}	
</div>