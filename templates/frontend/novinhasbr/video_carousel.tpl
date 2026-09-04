{if $videos}
<div class="xb-carousel-sec mt-4">
	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas {$icon}"></i>{$title}</h2>
		{if isset($link)}<a class="xb-section-link" href="{$link}">{t c='global.view_more'} <i class="fas fa-chevron-right"></i></a>{/if}
	</div>
	<div class="xb-carousel">
		<button type="button" class="xb-carousel-arrow xb-carousel-prev" aria-label="Anterior"><i class="fas fa-chevron-left"></i></button>
		<div class="xb-carousel-track">
			{section name=i loop=$videos}
			<div class="xb-carousel-item">
				<a href="{$relative}/video/{$videos[i].VID}/{$videos[i].title|clean}">
					<div class="thumb-overlay" {if $videos[i].vthumbs == '1'} id="playvthumb_{$videos[i].VID}"{/if}>
						<img src="{insert name=thumb_path vid=$videos[i].VID}/{$videos[i].thumb}.jpg" title="{$videos[i].title|escape:'html'}" alt="{$videos[i].title|escape:'html'}" {if $videos[i].vthumbs == '0'}id="rotate_{$videos[i].VID}_{$videos[i].thumbs}_{$videos[i].thumb}_viewed"{/if} class="img-responsive {if $videos[i].type == 'private'}img-private{/if}"/>
						{if $videos[i].type == 'private'}<div class="label-private">{t c='global.PRIVATE'}</div>{/if}
						<span class="xb-thumb-meta">
							{insert name=views assign=s_views views=$videos[i].viewnumber text='0'}
							{insert name=views assign=s_views_w views=$videos[i].viewnumber text='w'}
							<span class="xb-thumb-views"><i class="fas fa-eye"></i> {$s_views}<span class="xb-thumb-views-word"> {$s_views_w}</span></span>
							{if isset($videos[i].username)}
							<span class="xb-thumb-user">@{$videos[i].username}</span>
							{/if}
							<span class="xb-thumb-title">
								<span class="xb-thumb-title-inner">
									<span class="xb-tt">{$videos[i].title|escape:'html'}</span><span class="xb-tt">{$videos[i].title|escape:'html'}</span>
								</span>
							</span>
						</span>
						<div class="duration">
							{if $videos[i].hd==1}<span class="hd-text-icon">HD</span>{/if}
							{insert name=duration assign=duration duration=$videos[i].duration}
							{$duration}
						</div>
					</div>
				</a>
			</div>
			{/section}
		</div>
		<button type="button" class="xb-carousel-arrow xb-carousel-next" aria-label="Próximo"><i class="fas fa-chevron-right"></i></button>
	</div>
	<script>
	(function() {
		var sec = document.currentScript.parentElement;
		var track = sec.querySelector('.xb-carousel-track');
		var prev = sec.querySelector('.xb-carousel-prev');
		var next = sec.querySelector('.xb-carousel-next');
		if (!track || !prev || !next) return;
		function itemWidth() {
			var first = track.querySelector('.xb-carousel-item');
			return first ? first.offsetWidth + 12 : 300;
		}
		function updateArrows() {
			var max = track.scrollWidth - track.clientWidth - 2;
			prev.disabled = track.scrollLeft <= 2;
			next.disabled = track.scrollLeft >= max;
		}
		prev.addEventListener('click', function() {
			track.scrollBy({ left: -itemWidth() * 5, behavior: 'smooth' });
		});
		next.addEventListener('click', function() {
			track.scrollBy({ left: itemWidth() * 5, behavior: 'smooth' });
		});
		track.addEventListener('scroll', updateArrows, { passive: true });
		window.addEventListener('resize', updateArrows);
		updateArrows();
	})();
	</script>
</div>
{/if}