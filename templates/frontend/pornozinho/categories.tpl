<div class="container mt-3 mb-3">
	<div class="well well-filters">
			<div class="float-left">
				<h1>{t c='menu.categories'}</h1>
			</div>
			<div class="float-left">
				{if $video_module+$photo_module  > 1}
				<div class="well-action float-left m-l-20 {if $section != 'a'}active{/if}">
					<a href="{url base='categories' strip='s' value=''}"><span class="sw-left">{t c='global.videos'}</span></a>
				</div>
				<div class="well-action float-left m-r-15 {if $section == 'a'}active{/if}">						
					<a href="{url base='categories' strip='s' value='a'}"><span class="sw-right">{t c='global.albums'}</span></a>						
				</div>					
				{/if}
			</div>
			<div class="clearfix"></div>
	</div>
	<div class="row">
		<div class="content-left mt-3">
		{if $section == "a" &&  $photo_module == '1'}
            {if $categories}
			<div class="xb-cats">
            {section name=i loop=$categories}
				<a class="xb-cat-card" href="{$relative}/albums/{$categories[i].slug}">
					<div class="xb-cat-thumb">
						<img src="{$categories[i].cover_url}" title="{$categories[i].name|escape:'html'}" alt="{$categories[i].name|escape:'html'}" loading="lazy">
						<span class="xb-cat-count-badge"><i class="fas fa-images"></i> {$categories[i].total}</span>
					</div>
					<div class="xb-cat-info">
						<span class="xb-cat-name">{$categories[i].name|escape:'html'}</span>
						<span class="xb-cat-videos">{$categories[i].total} {t c='global.albums'}</span>
					</div>
				</a>
            {/section}
			</div>
            {/if}
		{/if}

		{if $section == "v" &&  $video_module == '1'}
            {if $categories}
			<div class="xb-cats">
            {section name=i loop=$categories}
				<a class="xb-cat-card" href="{$relative}/videos/{$categories[i].slug}">
					<div class="xb-cat-thumb">
						<img src="{$categories[i].cover_url}" title="{$categories[i].name|escape:'html'}" alt="{$categories[i].name|escape:'html'}" loading="lazy">
						<span class="xb-cat-count-badge"><i class="fas fa-play"></i> {$categories[i].total}</span>
					</div>
					<div class="xb-cat-info">
						<span class="xb-cat-name">{$categories[i].name|escape:'html'}</span>
						<span class="xb-cat-videos">{$categories[i].total} {t c='global.videos'}</span>
					</div>
				</a>
            {/section}
			</div>
            {/if}
		{/if}
	
		
		</div>
		<div class="content-right mt-3 mb-3">
			{insert name=adv assign=adv group='categories_right'}
			{if $adv.ad}
			<div class="ad-content">
				{$adv.ad}
			</div>	
			{elseif $adv.help}		
				<div class="ad-body" style="width:{$adv.width}px;">
					<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">CATEGORIES RIGHT</span></p>
					<p class="ad-size">{$adv.width} &times; Auto</p>
				</div>			
			{/if}		
		</div>
	</div>
	{insert name=adv assign=adv group='categories_bottom'}
	{if $adv.ad}	
	<div class="ad-content">
		{$adv.ad}
	</div>	
	{elseif $adv.help}		
		<div class="ad-body">
			<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">CATEGORIES BOTTOM</span></p>
			<p class="ad-size">Auto &times; Auto</p>
		</div>			
	{/if}	
	
</div>