{* Card de vídeo dos grids (home, lista de vídeos). Recebe `v` = item do vídeo.
   `card_cols` (classes Bootstrap da coluna, default 4-col) e `show_tags` (default 1).
   Único place de markup do grid — qualquer ajuste vale para todos os grids de uma vez. *}
<div class="{if $card_cols}{$card_cols}{else}col-6 col-sm-6 col-md-4 col-lg-3{/if}">
	<a href="{$relative}/video/{$v.VID}/{$v.title|clean}">
<div class="thumb-overlay{if isset($v.orientation) && $v.orientation == 'portrait'} xb-portrait{/if}" {if $v.vthumbs == '1'} id="playvthumb_{$v.VID}"{/if}>
			{if isset($v.orientation) && $v.orientation == 'portrait'}{insert name=video_trio vid=$v.VID thumb=$v.thumb thumbs=$v.thumbs opt=$v.thumbnails_opt title=$v.title type=$v.type}{else}<img src="{insert name=thumb_path vid=$v.VID}/{$v.thumb}.jpg" title="{$v.title|escape:'html'}" alt="{$v.title|escape:'html'}" {if $v.vthumbs == '0'}id="rotate_{$v.VID}_{$v.thumbs}_{$v.thumb}_viewed"{/if} class="img-responsive {if $v.type == 'private'}img-private{/if}"/>{/if}
			{if $v.type == 'private'}<div class="label-private">{t c='global.PRIVATE'}</div>{/if}
			{if $v.featured=='yes'}<div class="xb-featured-corner"><i class="fas fa-star"></i></div>{/if}
			<span class="xb-thumb-meta">
				{insert name=views assign=s_views views=$v.viewnumber text='0'}
				{insert name=views assign=s_views_w views=$v.viewnumber text='w'}
				<span class="xb-thumb-views"><i class="fas fa-eye"></i> {$s_views}<span class="xb-thumb-views-word"> {$s_views_w}</span></span>
				{if isset($v.username) && $v.username != 'anonymous'}
				<span class="xb-thumb-user">@{$v.username}</span>
				{/if}
				<span class="xb-thumb-title">
					<span class="xb-thumb-title-inner">
						<span class="xb-tt">{$v.title|escape:'html'}</span><span class="xb-tt">{$v.title|escape:'html'}</span>
					</span>
				</span>
			</span>
			<div class="duration">
				{if $v.hd==1}<span class="hd-text-icon">HD</span>{/if}
				{insert name=duration assign=duration duration=$v.duration}
				{$duration}
			</div>
		</div>
	</a>
	<div class="content-info">
		<a href="{$relative}/video/{$v.VID}/{$v.title|clean}">
			<span class="content-title">{$v.title|escape:'html'}</span>
		</a>
		{if $show_tags|default:1 && $v.keywords}
		<div class="xb-tags">
			{section name=t loop=$v.keywords max=4}
				<a href="{$relative}/search/tags/{$v.keywords[t]}">#{$v.keywords[t]}</a>
			{/section}
		</div>
		{/if}
	</div>
</div>