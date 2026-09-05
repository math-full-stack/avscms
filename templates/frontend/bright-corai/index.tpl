<div class="container mt-3 mb-3">
	<div class="well-filters mb-3">
		<div class="float-left">
			<h1>{translate c='index.most_recent_videos'}</h1>
		</div>
		<div class="float-right well-action">
			<a href="{$relative}/videos?o=mr"><span class="d-none d-sm-inline">{translate c='index.most_recent_videos_more'}</span><span class="d-xs-inline d-sm-none"><i class="fas fa-plus"></i></span></a>
		</div>		
		<div class="clearfix"></div>
	</div>
	
	<div class="row">
		<div class="col-sm-12">
            {if $recent_videos}
			<div class="row content-row">
            {section name=i loop=$recent_videos}
				<div class="{if $min_col == '2'}col-6{/if} col-sm-6 col-md-4 col-lg-3 {if $max_col == '5'}col-xl-2dot4{/if} i-container">
					<a href="{$relative}/video/{$recent_videos[i].VID}/{$recent_videos[i].title|clean}">
						<div class="thumb-overlay" {if $recent_videos[i].vthumbs == '1'} id="playvthumb_{$recent_videos[i].VID}"{/if}>
							<img src="{insert name=thumb_path vid=$recent_videos[i].VID}/{$recent_videos[i].thumb}.jpg" title="{$recent_videos[i].title|escape:'html'}" alt="{$recent_videos[i].title|escape:'html'}" {if $recent_videos[i].vthumbs == '0'}id="rotate_{$recent_videos[i].VID}_{$recent_videos[i].thumbs}_{$recent_videos[i].thumb}_viewed"{/if} class="img-responsive {if $recent_videos[i].type == 'private'}img-private{/if}"/>
							{if $recent_videos[i].type == 'private'}<div class="label-private">{t c='global.PRIVATE'}</div>{/if}
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
						<div class="content-details">
							{insert name=views assign=s_views views=$recent_videos[i].viewnumber}											
							<span class="content-views">
								{$s_views}								
							</span>
							{if $recent_videos[i].rate != 0}
								<span class="content-rating"><i class="fas fa-thumbs-up"></i> <span>{$recent_videos[i].rate}%</span></span>
							{/if}
						</div>				
					</div>			
				</div>							
            {/section}
			</div>
            {else}
			<div class="well well-sm">
				<span class="text-danger">{t c='videos.no_videos_found'}.</span>
			</div>
            {/if}			
		</div>
	</div>

	{if $hero_videos}
	<div class="well-filters mb-3">
		<div class="float-left">
			<h1>{translate c='index.featured_videos'}</h1>
		</div>
		<div class="float-right well-action">
			<a href="{$relative}/videos?o=f"><span class="d-none d-sm-inline">{translate c='index.featured_videos_more'}</span><span class="d-xs-inline d-sm-none"><i class="fas fa-plus"></i></span></a>
		</div>		
		<div class="clearfix"></div>
	</div>
	
	<div class="row">
		<div class="col-sm-12">
			<div class="row content-row">
            {section name=i loop=$hero_videos}
				<div class="{if $min_col == '2'}col-6{/if} col-sm-6 col-md-4 col-lg-3 {if $max_col == '5'}col-xl-2dot4{/if} i-container">
					<a href="{$relative}/video/{$hero_videos[i].VID}/{$hero_videos[i].title|clean}">
						<div class="thumb-overlay" {if $hero_videos[i].vthumbs == '1'} id="playvthumb_{$hero_videos[i].VID}"{/if}>
							<img src="{insert name=thumb_path vid=$hero_videos[i].VID}/{$hero_videos[i].thumb}.jpg" title="{$hero_videos[i].title|escape:'html'}" alt="{$hero_videos[i].title|escape:'html'}" {if $hero_videos[i].vthumbs == '0'}id="rotate_{$hero_videos[i].VID}_{$hero_videos[i].thumbs}_{$hero_videos[i].thumb}_viewed"{/if} class="img-responsive {if $hero_videos[i].type == 'private'}img-private{/if}"/>
							{if $hero_videos[i].type == 'private'}<div class="label-private">{t c='global.PRIVATE'}</div>{/if}
							<div class="duration">
								{if $hero_videos[i].hd==1}<span class="hd-text-icon">HD</span>{/if}
								{insert name=duration assign=duration duration=$hero_videos[i].duration}
								{$duration}
							</div>
						</div>
					</a>
					<div class="content-info">
						<a href="{$relative}/video/{$hero_videos[i].VID}/{$hero_videos[i].title|clean}">
							<span class="content-title">{$hero_videos[i].title|escape:'html'}</span>					
						</a>
						<div class="content-details">
							{insert name=views assign=s_views views=$hero_videos[i].viewnumber}											
							<span class="content-views">
								{$s_views}								
							</span>
							{if $hero_videos[i].rate != 0}
								<span class="content-rating"><i class="fas fa-thumbs-up"></i> <span>{$hero_videos[i].rate}%</span></span>
							{/if}
						</div>				
					</div>			
				</div>							
            {/section}
			</div>
		</div>
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