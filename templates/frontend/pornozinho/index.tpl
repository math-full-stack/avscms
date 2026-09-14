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

	{* Seção Destaques (hero) removida a pedido. O CSS .xb-hero-* ficou dormente
   em pornozinho.css e a query $hero_videos segue em index.php sem consumidor. *}
<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-thumbs-up"></i>Para Você</h2>
		<a class="xb-section-link" href="{$relative}/videos">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>

	{if $home_feed_videos}
	<div class="row content-row" id="home-feed">
		{section name=i loop=$home_feed_videos}
			{include file='video_card.tpl' v=$home_feed_videos[i] card_cols='col-6 col-sm-6 col-md-4 col-lg-3' show_tags=1}
			{if $smarty.section.i.iteration is div by 8}
			{include file='ad_feed.tpl' group='index_feed'}
			{/if}
		{/section}
	</div>
	<div class="xb-feed-more-wrap">
		<button type="button" id="home-feed-more" class="xb-feed-more-btn">
			<span class="material-symbols-rounded" aria-hidden="true">expand_more</span>
			<span class="xb-feed-more-label">Exibir mais</span>
		</button>
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

	{* Seção Creators removida a pedido. O CSS .xb-creators/.xb-creator-card ficou
	   dormente em pornozinho.css e a query $creators segue em index.php sem
	   consumidor (como o hero acima). *}
	{if $categories_sm}
	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-th-large"></i>Categorias</h2>
		<a class="xb-section-link" href="{$relative}/categories">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>
	<div class="xb-cats">
		{section name=c loop=$categories_sm}
		<a class="xb-cat-card" href="{$relative}/videos/{$categories_sm[c].slug}">
			<div class="xb-cat-thumb">
				<img src="{$categories_sm[c].cover_url}" title="{$categories_sm[c].name|escape:'html'}" alt="{$categories_sm[c].name|escape:'html'}" loading="lazy">
				<span class="xb-cat-count-badge"><i class="fas fa-play"></i> {$categories_sm[c].total_videos}</span>
			</div>
			<div class="xb-cat-info">
				<span class="xb-cat-name">{$categories_sm[c].name|escape:'html'}</span>
				<span class="xb-cat-videos">{$categories_sm[c].total_videos} {t c='global.videos'}</span>
			</div>
		</a>
		{/section}
	</div>
	<script>
	{literal}
	document.querySelectorAll('.xb-cat-thumb > img').forEach(function(img){
		function check(){
			if(img.naturalWidth && img.naturalHeight){
				if(img.naturalHeight > img.naturalWidth * 1.1){
					var wrap=document.createElement('div');wrap.className='xb-trio xb-cat-trio';
					for(var i=0;i<3;i++){var c=img.cloneNode(true);c.removeAttribute('title');wrap.appendChild(c);}
					img.replaceWith(wrap);
				}
			} else { img.addEventListener('load',check,{once:true}); }
		}
		check();
	});
	{/literal}
	</script>
	{/if}

	{insert name=adv assign=adv group='index_bottom'}
	{if $adv.ad}
	<div class="ad-content ad-bottom">
		{$adv.ad}
	</div>	
	{elseif $adv.help}		
		<div class="ad-body ad-bottom">
			<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">INDEX BOTTOM</span></p>
			<p class="ad-size">Auto &times; Auto</p>
		</div>			
	{/if}	
</div>