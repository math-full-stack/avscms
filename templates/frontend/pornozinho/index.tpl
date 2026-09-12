<div class="container mt-3 mb-3">

	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-bolt"></i>Novos vídeos</h2>
		<a class="xb-section-link" href="{$relative}/videos?o=mr">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>

	{if $recent_videos}
	<div class="row content-row">
		{section name=i loop=$recent_videos}
		<div class="col-6 col-sm-6 col-md-4 col-lg-3">
			<a href="{$relative}/video/{$recent_videos[i].VID}/{$recent_videos[i].title|clean}">					<div class="thumb-overlay{if isset($recent_videos[i].orientation) && $recent_videos[i].orientation == 'portrait'} xb-portrait{/if}" {if $recent_videos[i].vthumbs == '1'} id="playvthumb_{$recent_videos[i].VID}"{/if}>
						{if isset($recent_videos[i].orientation) && $recent_videos[i].orientation == 'portrait'}{insert name=video_trio vid=$recent_videos[i].VID thumb=$recent_videos[i].thumb thumbs=$recent_videos[i].thumbs opt=$recent_videos[i].thumbnails_opt title=$recent_videos[i].title type=$recent_videos[i].type}{else}<img src="{insert name=thumb_path vid=$recent_videos[i].VID}/{$recent_videos[i].thumb}.jpg" title="{$recent_videos[i].title|escape:'html'}" alt="{$recent_videos[i].title|escape:'html'}" {if $recent_videos[i].vthumbs == '0'}id="rotate_{$recent_videos[i].VID}_{$recent_videos[i].thumbs}_{$recent_videos[i].thumb}_viewed"{/if} class="img-responsive {if $recent_videos[i].type == 'private'}img-private{/if}"/>{/if}
						{if $recent_videos[i].type == 'private'}<div class="label-private">{t c='global.PRIVATE'}</div>{/if}
						<span class="xb-thumb-meta">
							{insert name=views assign=s_views views=$recent_videos[i].viewnumber text='0'}
							{insert name=views assign=s_views_w views=$recent_videos[i].viewnumber text='w'}
							<span class="xb-thumb-views"><i class="fas fa-eye"></i> {$s_views}<span class="xb-thumb-views-word"> {$s_views_w}</span></span>
							{if isset($recent_videos[i].username) && $recent_videos[i].username != 'anonymous'}
							<span class="xb-thumb-user">@{$recent_videos[i].username}</span>
							{/if}
							<span class="xb-thumb-title">
								<span class="xb-thumb-title-inner">
									<span class="xb-tt">{$recent_videos[i].title|escape:'html'}</span><span class="xb-tt">{$recent_videos[i].title|escape:'html'}</span>
								</span>
							</span>
						</span>
					<div class="duration">
						{if $recent_videos[i].hd==1}<span class="hd-text-icon">HD</span>{/if}
						{insert name=duration assign=duration duration=$recent_videos[i].duration}
						{$duration}
					</div>
				</div>
			</a>
			<div class="content-info">
				<a href="{$relative}/video/{$recent_videos[i].VID}/{$recent_videos[i].title|clean}">
					<span class="content-title">{$recent_videos[i].title|escape:'html'}</span>
				</a>
				{if $recent_videos[i].keywords}
				<div class="xb-tags">
					{section name=t loop=$recent_videos[i].keywords max=4}
						<a href="{$relative}/search/tags/{$recent_videos[i].keywords[t]}">#{$recent_videos[i].keywords[t]}</a>
					{/section}
				</div>
				{/if}
			</div>
		</div>
		{/section}
	</div>
	{else}
	<div class="well well-sm">
		<span class="text-danger">{t c='videos.no_videos_found'}.</span>
	</div>
	{/if}

	{if $hero_videos}
	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-star"></i>Destaques</h2>
		<a class="xb-section-link" href="{$relative}/videos?o=f">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>

	{* Card principal grande com auto-play *}
	{assign var=hero_main value=$hero_videos[0]}
	<div class="row content-row mb-4">
		<div class="col-12 col-lg-8">
			<a href="{$relative}/video/{$hero_main.VID}/{$hero_main.title|clean}" class="xb-hero-main-link">
				<div class="thumb-overlay xb-hero-main" {if $hero_main.vthumbs == '1'} id="playvthumb_{$hero_main.VID}"{/if}>
					{if $hero_main.hero_src}
					<video id="xb-hero-video" class="xb-hero-video" src="{$hero_main.hero_src}" poster="{insert name=thumb_path vid=$hero_main.VID}/{$hero_main.thumb}.jpg" autoplay muted loop playsinline webkit-playsinline preload="auto"></video>
					{else}
					{if isset($hero_main.orientation) && $hero_main.orientation == 'portrait'}{insert name=video_trio vid=$hero_main.VID thumb=$hero_main.thumb thumbs=$hero_main.thumbs opt=$hero_main.thumbnails_opt title=$hero_main.title type=$hero_main.type}{else}<img src="{insert name=thumb_path vid=$hero_main.VID}/{$hero_main.thumb}.jpg" title="{$hero_main.title|escape:'html'}" alt="{$hero_main.title|escape:'html'}" {if $hero_main.vthumbs == '0'}id="rotate_{$hero_main.VID}_{$hero_main.thumbs}_{$hero_main.thumb}_viewed"{/if} class="img-responsive {if $hero_main.type == 'private'}img-private{/if}"/>{/if}
					{/if}
					{if $hero_main.type == 'private'}<div class="label-private">{t c='global.PRIVATE'}</div>{/if}
					<div class="duration">
						{if $hero_main.hd==1}<span class="hd-text-icon">HD</span>{/if}
						{insert name=duration assign=duration duration=$hero_main.duration}
						{$duration}
					</div>
					<div class="xb-hero-overlay">
						<div class="xb-hero-meta">
							<span><i class="fas fa-eye"></i> {insert name=views assign=s_views views=$hero_main.viewnumber}{$s_views}</span>
							{if $hero_main.username != 'anonymous'}<span>@{$hero_main.username}</span>{/if}
						</div>
						<h3 class="xb-hero-title">{$hero_main.title|escape:'html'}</h3>
					</div>
				</div>
			</a>
		</div>

		{* Cards menores para os demais *}
		<div class="col-12 col-lg-4 d-none d-md-block">
			<div class="xb-hero-side-grid">
				{section name=h loop=$hero_videos start=1}
				<div class="xb-hero-side-card">
					<a href="{$relative}/video/{$hero_videos[h].VID}/{$hero_videos[h].title|clean}">
						<div class="thumb-overlay{if isset($hero_videos[h].orientation) && $hero_videos[h].orientation == 'portrait'} xb-portrait{/if}" {if $hero_videos[h].vthumbs == '1'} id="playvthumb_{$hero_videos[h].VID}"{/if}>
							{if isset($hero_videos[h].orientation) && $hero_videos[h].orientation == 'portrait'}{insert name=video_trio vid=$hero_videos[h].VID thumb=$hero_videos[h].thumb thumbs=$hero_videos[h].thumbs opt=$hero_videos[h].thumbnails_opt title=$hero_videos[h].title type=$hero_videos[h].type}{else}<img src="{insert name=thumb_path vid=$hero_videos[h].VID}/{$hero_videos[h].thumb}.jpg" title="{$hero_videos[h].title|escape:'html'}" alt="{$hero_videos[h].title|escape:'html'}" {if $hero_videos[h].vthumbs == '0'}id="rotate_{$hero_videos[h].VID}_{$hero_videos[h].thumbs}_{$hero_videos[h].thumb}_viewed"{/if} class="img-responsive {if $hero_videos[h].type == 'private'}img-private{/if}"/>{/if}
							{if $hero_videos[h].type == 'private'}<div class="label-private">{t c='global.PRIVATE'}</div>{/if}
							<div class="duration">
								{if $hero_videos[h].hd==1}<span class="hd-text-icon">HD</span>{/if}
								{insert name=duration assign=duration duration=$hero_videos[h].duration}
								{$duration}
							</div>
						</div>
					</a>
					<div class="xb-hero-side-info">
						<a href="{$relative}/video/{$hero_videos[h].VID}/{$hero_videos[h].title|clean}">
							<span class="content-title">{$hero_videos[h].title|escape:'html'}</span>
						</a>
					</div>
				</div>
				{/section}
			</div>
		</div>
	</div>
	{/if}

	<script>
	{literal}
	(function(){
		var v = document.getElementById('xb-hero-video');
		if (!v) return;
		v.muted = true;
		var play = function(){ v.play().catch(function(){}); };
		v.addEventListener('loadeddata', play);
		play();
	})();
	{/literal}
	</script>

	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-fire"></i>Em alta</h2>
		<a class="xb-section-link" href="{$relative}/videos?o=bw">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>
	</div>

	{if $viewed_videos}
	<div class="row content-row">
		{section name=i loop=$viewed_videos}
		<div class="col-6 col-sm-6 col-md-4 col-lg-3">
			<a href="{$relative}/video/{$viewed_videos[i].VID}/{$viewed_videos[i].title|clean}">					<div class="thumb-overlay{if isset($viewed_videos[i].orientation) && $viewed_videos[i].orientation == 'portrait'} xb-portrait{/if}" {if $viewed_videos[i].vthumbs == '1'} id="playvthumb_{$viewed_videos[i].VID}"{/if}>
						{if isset($viewed_videos[i].orientation) && $viewed_videos[i].orientation == 'portrait'}{insert name=video_trio vid=$viewed_videos[i].VID thumb=$viewed_videos[i].thumb thumbs=$viewed_videos[i].thumbs opt=$viewed_videos[i].thumbnails_opt title=$viewed_videos[i].title type=$viewed_videos[i].type}{else}<img src="{insert name=thumb_path vid=$viewed_videos[i].VID}/{$viewed_videos[i].thumb}.jpg" title="{$viewed_videos[i].title|escape:'html'}" alt="{$viewed_videos[i].title|escape:'html'}" {if $viewed_videos[i].vthumbs == '0'}id="rotate_{$viewed_videos[i].VID}_{$viewed_videos[i].thumbs}_{$viewed_videos[i].thumb}_viewed"{/if} class="img-responsive {if $viewed_videos[i].type == 'private'}img-private{/if}"/>{/if}
						{if $viewed_videos[i].type == 'private'}<div class="label-private">{t c='global.PRIVATE'}</div>{/if}
						<span class="xb-thumb-meta">
							{insert name=views assign=s_views views=$viewed_videos[i].viewnumber text='0'}
							{insert name=views assign=s_views_w views=$viewed_videos[i].viewnumber text='w'}
							<span class="xb-thumb-views"><i class="fas fa-eye"></i> {$s_views}<span class="xb-thumb-views-word"> {$s_views_w}</span></span>
							{if isset($viewed_videos[i].username) && $viewed_videos[i].username != 'anonymous'}
							<span class="xb-thumb-user">@{$viewed_videos[i].username}</span>
							{/if}
							<span class="xb-thumb-title">
								<span class="xb-thumb-title-inner">
									<span class="xb-tt">{$viewed_videos[i].title|escape:'html'}</span><span class="xb-tt">{$viewed_videos[i].title|escape:'html'}</span>
								</span>
							</span>
						</span>
					<div class="duration">
						{if $viewed_videos[i].hd==1}<span class="hd-text-icon">HD</span>{/if}
						{insert name=duration assign=duration duration=$viewed_videos[i].duration}
						{$duration}
					</div>
				</div>
			</a>
			<div class="content-info">
				<a href="{$relative}/video/{$viewed_videos[i].VID}/{$viewed_videos[i].title|clean}">
					<span class="content-title">{$viewed_videos[i].title|escape:'html'}</span>
				</a>
				{if $viewed_videos[i].keywords}
				<div class="xb-tags">
					{section name=t loop=$viewed_videos[i].keywords max=4}
						<a href="{$relative}/search/tags/{$viewed_videos[i].keywords[t]}">#{$viewed_videos[i].keywords[t]}</a>
					{/section}
				</div>
				{/if}
			</div>
		</div>
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