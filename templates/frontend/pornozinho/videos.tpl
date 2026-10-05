<div class="container mt-3 mb-3">

	<div class="well-filters">
			<div class="float-left">
				<h1>{t c='global.videos'}</h1>
			</div>
			<div class="float-left">
				<div class="d-none d-md-inline">
					<div class="well-action float-left m-l-20 {if $quality != 'hd'}active{/if}">
						<a href="{url base=$base strip='q' value='all'}"><span class="sw-left">{t c='global.all'}</span></a>
					</div>
					<div class="well-action float-left m-r-15 {if $quality == 'hd'}active{/if}">						
						<a href="{url base=$base strip='q' value='hd'}"><span class="sw-right">HD</span></a>						
					</div>					
					<div class="btn-group m-r-10">
						<a class="well-action dropdown-toggle" data-toggle="dropdown">{if $type == ''}{t c='global.type'}{elseif $type == 'public'}{t c='global.public'}{elseif $type == 'private'}{t c='global.private'}{else}{t c='global.featured'}{/if} <span class="caret"></span></a>
						<ul class="dropdown-menu">
							<li {if $type == ''}class="active"{/if}><a href="{url base=$base strip='type' value=''}">{t c='global.all'}</a></li>
							<li {if $type == 'public'}class="active"{/if}><a href="{url base=$base strip='type' value='public'}">{t c='global.public'}</a></li>
							<li {if $type == 'private'}class="active"{/if}><a href="{url base=$base strip='type' value='private'}">{t c='global.private'}</a></li>	
							<li {if $type == 'featured'}class="active"{/if}><a href="{url base=$base strip='type' value='featured'}">{t c='global.featured'}</a></li>								
						</ul>
					</div>
					
					<div class="btn-group m-r-10">
						<a class="well-action dropdown-toggle" data-toggle="dropdown">{if $timeframe == 'a'}{t c='global.timeline'}{elseif $timeframe == 't'}{t c='global.added'} {t c='global.today'}{elseif $timeframe == 'w'}{t c='global.added'} {t c='global.this_week'}{else}{t c='global.added'} {t c='global.this_month'}{/if} <span class="caret"></span></a>
						<ul class="dropdown-menu">
							<li {if $timeframe == 'a'}class="active"{/if}><a href="{url base=$base strip='t' value='a'}">{t c='global.all'}</a></li>							
							<li {if $timeframe == 't'}class="active"{/if}><a href="{url base=$base strip='t' value='t'}">{t c='global.added'} {t c='global.today'}</a></li>
							<li {if $timeframe == 'w'}class="active"{/if}><a href="{url base=$base strip='t' value='w'}">{t c='global.added'} {t c='global.this_week'}</a></li>
							<li {if $timeframe == 'm'}class="active"{/if}><a href="{url base=$base strip='t' value='m'}">{t c='global.added'} {t c='global.this_month'}</a></li>
						</ul>
					</div>					

					<div class="btn-group m-r-10">
						<a class="well-action dropdown-toggle" data-toggle="dropdown">{if $order == 'bw'}{t c='global.being_watched'}{elseif $order == 'mr'}{t c='global.most_recent'}{elseif $order == 'mv'}{t c='global.most_viewed'}{elseif $order == 'tr'}{t c='global.top_rated'}{elseif $order == 'md'}{t c='global.most_commented'}{elseif $order == 'tf'}{t c='global.top_favorites'}{else}{t c='global.longest'}{/if} <span class="caret"></span></a>
						<ul class="dropdown-menu">
							<li {if $order == 'bw'}class="active"{/if}><a href="{url base=$base strip='o' value='bw'}">{t c='global.being_watched'}</a></li>						
							<li {if $order == 'mr'}class="active"{/if}><a href="{url base=$base strip='o' value='mr'}">{t c='global.most_recent'}</a></li>
							<li {if $order == 'mv'}class="active"{/if}><a href="{url base=$base strip='o' value='mv'}">{t c='global.most_viewed'}</a></li>
							<li {if $order == 'md'}class="active"{/if}><a href="{url base=$base strip='o' value='md'}">{t c='global.most_commented'}</a></li>
							<li {if $order == 'tr'}class="active"{/if}><a href="{url base=$base strip='o' value='tr'}">{t c='global.top_rated'}</a></li>							
							<li {if $order == 'tf'}class="active"{/if}><a href="{url base=$base strip='o' value='tf'}">{t c='global.top_favorites'}</a></li>
							<li {if $order == 'lg'}class="active"{/if}><a href="{url base=$base strip='o' value='lg'}">{t c='global.longest'}</a></li>
						</ul>
					</div>					
				</div>	
				<div class="d-inline d-md-none">
					<div class="btn-group m-l-20">
						<a class="well-action dropdown-toggle" data-toggle="dropdown">Filters <span class="caret"></span></a>
						<ul class="dropdown-menu">
							<li {if $type == ''}class="active"{/if}><a href="{url base=$base strip='type' value=''}">{t c='global.all'}</a></li>
							<li {if $type == 'public'}class="active"{/if}><a href="{url base=$base strip='type' value='public'}">{t c='global.public'}</a></li>
							<li {if $type == 'private'}class="active"{/if}><a href="{url base=$base strip='type' value='private'}">{t c='global.private'}</a></li>						
							<div class="dropdown-divider"></div>
							<li {if $timeframe == 'a'}class="active"{/if}><a href="{url base=$base strip='t' value='a'}">{t c='global.all'}</a></li>							
							<li {if $timeframe == 't'}class="active"{/if}><a href="{url base=$base strip='t' value='t'}">{t c='global.added'} {t c='global.today'}</a></li>
							<li {if $timeframe == 'w'}class="active"{/if}><a href="{url base=$base strip='t' value='w'}">{t c='global.added'} {t c='global.this_week'}</a></li>
							<li {if $timeframe == 'm'}class="active"{/if}><a href="{url base=$base strip='t' value='m'}">{t c='global.added'} {t c='global.this_month'}</a></li>
							<div class="dropdown-divider"></div>			
							<li {if $order == 'bw'}class="active"{/if}><a href="{url base=$base strip='o' value='bw'}">{t c='global.being_watched'}</a></li>						
							<li {if $order == 'mr'}class="active"{/if}><a href="{url base=$base strip='o' value='mr'}">{t c='global.most_recent'}</a></li>
							<li {if $order == 'mv'}class="active"{/if}><a href="{url base=$base strip='o' value='mv'}">{t c='global.most_viewed'}</a></li>
							<li {if $order == 'md'}class="active"{/if}><a href="{url base=$base strip='o' value='md'}">{t c='global.most_commented'}</a></li>
							<li {if $order == 'tr'}class="active"{/if}><a href="{url base=$base strip='o' value='tr'}">{t c='global.top_rated'}</a></li>							
							<li {if $order == 'tf'}class="active"{/if}><a href="{url base=$base strip='o' value='tf'}">{t c='global.top_favorites'}</a></li>
							<li {if $order == 'lg'}class="active"{/if}><a href="{url base=$base strip='o' value='lg'}">{t c='global.longest'}</a></li>
						</ul>
					</div>				
				</div>
			</div>
			<div class="float-right well-action">
				<a href="{$relative}/upload/video"><span class="d-none d-sm-inline">{t c='videos.upload'}</span><span class="d-xs-inline d-sm-none"><i class="fas fa-upload"></i></span></a>
			</div>		
			<div class="clearfix"></div>
	</div>
	<div class="well-info">
		{if $videos}
		{t c='global.showing'} <span class="text-highlighted">{$start_num}</span> {t c='global.to'} <span class="text-highlighted">{$end_num}</span> {t c='global.of'} <span class="text-highlighted">{$videos_total}</span> {t c='videos.videos'}.
		{/if}
	</div>		
	<div class="row">	
		<div class="content-left">
            {if $videos}		
			{capture name=videos_cols}col-12 col-sm-6 col-md-4 col-lg-4 col-xl-4 col-xxl-4 col-xxxl-4{/capture}
			{* Mesma geometria do feed da home (feed_grid.tpl): 3 cards por linha aqui
			   e 1 faixa de anúncio (videos_feed) a cada 4 linhas = 12 cards, sempre
			   irmã da linha — dentro do .row a faixa vira item de flex e estoura a
			   largura das colunas. *}
			{include file='feed_grid.tpl' videos=$videos group='videos_feed' grid_cols=$smarty.capture.videos_cols grid_show_tags=0 grid_per_row=3 grid_ad_rows=4}
            {else}
			<div class="well well-sm">
				<span class="text-danger">{t c='videos.no_videos_found'}.</span>
			</div>
            {/if}	

			{if $videos}
				{if $page_link}			
					<div class="d-block d-sm-none">
						<ul class="pagination pagination-lg">{$page_link}</ul>
					</div>
					<div class="d-none d-sm-block">
						<ul class="pagination">{$page_link}</ul>
					</div>					
				{/if}
			{/if}
		</div>
		
		<div class="content-right mb-3">
<div class="list-group mb-3">
			<a href="{url base='videos' strip='c' value=''}" {if $category == "0"}class="list-group-item active"{else}class="list-group-item"{/if}>
				{t c='global.all'}
			</a>
			{section name=p loop=$category_parents}
			<div class="category-parent-group">
				<a href="{url base='videos/'|cat:$category_parents[p].slug strip='c' value=''}" class="list-group-item list-group-item-heading {if $category == $category_parents[p].CHID}active{/if} fw-bold category-parent-link" data-parent-id="{$category_parents[p].CHID}">
					{$category_parents[p].name}
					{if $category_parents[p].children|@count > 0}<span class="float-right"><i class="material-symbols-rounded xb-nav-icon">expand_more</i></span>{/if}
				</a>
				{if $category_parents[p].children|@count > 0}
				<div class="category-children {if $active_parent_id == $category_parents[p].CHID}show{/if}">
					{section name=c loop=$category_parents[p].children}
					<a href="{url base='videos/'|cat:$category_parents[p].children[c].slug strip='c' value=''}" class="list-group-item list-group-item-small {if $category == $category_parents[p].children[c].CHID}active{/if}" style="padding-left: 32px;">
						{$category_parents[p].children[c].name}
					</a>
					{/section}
				</div>
				{/if}
			</div>
			{/section}
		</div>
			{insert name=adv assign=adv group='videos_right'}
			{if $adv.ad}
			<div class="ad-content">
				{$adv.ad}
			</div>	
			{elseif $adv.help}		
				<div class="ad-body" style="width:{$adv.width}px;">
					<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">VIDEOS RIGHT</span></p>
					<p class="ad-size">{$adv.width} &times; Auto</p>
				</div>			
			{/if}			
		
		</div>
	</div>
	{insert name=adv assign=adv group='videos_bottom'}
	{if $adv.ad}
	<div class="ad-content">
		{$adv.ad}
	</div>	
	{elseif $adv.help}		
		<div class="ad-body">
			<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">VIDEOS BOTTOM</span></p>
			<p class="ad-size">Auto &times; Auto</p>
		</div>			
	{/if}	

{literal}
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.category-parent-link').forEach(function(link) {
        if (link.dataset.parentId) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var siblingChildren = this.nextElementSibling;
                if (siblingChildren && siblingChildren.classList.contains('category-children')) {
                    siblingChildren.classList.toggle('show');
                    var icon = this.querySelector('.xb-nav-icon');
                    if (icon) icon.style.transform = siblingChildren.classList.contains('show') ? 'rotate(180deg)' : '';
                }
            });
        }
    });
});
</script>
{/literal}

</div>