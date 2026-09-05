{if $videos}
{assign var=enable_auto_scroll value=$auto_scroll|default:false}
<style>
.xb-carousel-sec--themed {
    position: relative;
    margin: 2rem -1rem;
    padding: 2.5rem 1rem;
    border-radius: 24px;
    overflow: hidden;
    background: linear-gradient(135deg, rgba(255, 20, 147, 0.12) 0%, rgba(139, 92, 246, 0.1) 50%, rgba(255, 20, 147, 0.12) 100%);
    border: 1px solid rgba(255, 20, 147, 0.18);
}
.xb-carousel-sec--themed::before {
    content: '';
    position: absolute;
    inset: -2px;
    border-radius: 26px;
    background: conic-gradient(from 0deg at 50% 50%, 
        rgba(255, 20, 147, 0.4) 0deg, 
        rgba(139, 92, 246, 0.35) 120deg, 
        rgba(59, 130, 246, 0.3) 240deg, 
        rgba(255, 20, 147, 0.4) 360deg);
    filter: blur(60px);
    opacity: 0.5;
    animation: xb-aurora-shift 12s ease-in-out infinite;
    pointer-events: none;
    z-index: -1;
}
.xb-carousel-sec--themed::after {
    content: '';
    position: absolute;
    inset: 0;
    background: 
        radial-gradient(ellipse 80% 50% at 20% 0%, rgba(255, 20, 147, 0.08) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 80% 100%, rgba(139, 92, 246, 0.06) 0%, transparent 55%),
        radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.02) 0%, transparent 70%);
    pointer-events: none;
    z-index: -1;
}
@keyframes xb-aurora-shift {
    0%, 100% { transform: rotate(0deg) scale(1); opacity: 0.45; }
    25% { transform: rotate(90deg) scale(1.1); opacity: 0.55; }
    50% { transform: rotate(180deg) scale(1.05); opacity: 0.5; }
    75% { transform: rotate(270deg) scale(1.15); opacity: 0.55; }
}
.xb-carousel-sec--themed .xb-section {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 0.5rem;
}
.xb-carousel-sec--themed .xb-section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.xb-carousel-sec--themed .xb-section-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.85rem;
    border-radius: 100px;
    background: linear-gradient(135deg, rgba(255, 20, 147, 0.2) 0%, rgba(139, 92, 246, 0.18) 100%);
    border: 1px solid rgba(255, 20, 147, 0.35);
    backdrop-filter: blur(10px);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #ff1493;
    box-shadow: 0 2px 12px rgba(255, 20, 147, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.1);
    animation: xb-badge-pulse 3s ease-in-out infinite;
}
@keyframes xb-badge-pulse {
    0%, 100% { box-shadow: 0 2px 12px rgba(255, 20, 147, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.1); }
    50% { box-shadow: 0 4px 20px rgba(255, 20, 147, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.15); }
}
.xb-carousel-sec--themed .xb-section-bar {
    display: none;
}
.xb-carousel-sec--themed h2 {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    background: linear-gradient(135deg, #ffffff 0%, #f5efff 30%, #ffeef8 60%, #fff0f8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    background-size: 200% 200%;
    animation: xb-title-shimmer 4s ease-in-out infinite;
    filter: drop-shadow(0 2px 16px rgba(255, 20, 147, 0.25));
}
@keyframes xb-title-shimmer {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}
.xb-carousel-sec--themed h2 i {
    background: linear-gradient(135deg, #ff1493 0%, #8b5cf6 50%, #3b82f6 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    font-size: 1.1em;
    margin-right: 0.25rem;
    filter: drop-shadow(0 0 8px rgba(255, 20, 147, 0.4));
}
.xb-carousel-sec--themed .xb-section-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: rgba(255, 255, 255, 0.9);
    background: linear-gradient(135deg, rgba(255, 20, 147, 0.18) 0%, rgba(139, 92, 246, 0.15) 100%);
    border: 1px solid rgba(255, 20, 147, 0.35);
    padding: 0.55rem 1.2rem;
    border-radius: 100px;
    backdrop-filter: blur(12px);
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 2px 12px rgba(255, 20, 147, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.08);
}
.xb-carousel-sec--themed .xb-section-link:hover {
    background: linear-gradient(135deg, rgba(255, 20, 147, 0.3) 0%, rgba(139, 92, 246, 0.25) 100%);
    border-color: rgba(255, 20, 147, 0.55);
    color: #fff;
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 8px 28px rgba(255, 20, 147, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.12);
}
.xb-carousel-sec--themed .xb-section-link i {
    transition: transform 0.3s ease;
}
.xb-carousel-sec--themed .xb-section-link:hover i {
    transform: translateX(4px);
}
.xb-carousel-sec--themed .xb-carousel {
    position: relative;
    z-index: 1;
    margin-top: 1rem;
}
.xb-carousel-sec--themed .xb-carousel-track {
    padding: 0.5rem 0.25rem 1rem;
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 20, 147, 0.4) transparent;
}
.xb-carousel-sec--themed .xb-carousel-track::-webkit-scrollbar {
    height: 6px;
}
.xb-carousel-sec--themed .xb-carousel-track::-webkit-scrollbar-track {
    background: rgba(255, 20, 147, 0.08);
    border-radius: 3px;
}
.xb-carousel-sec--themed .xb-carousel-track::-webkit-scrollbar-thumb {
    background: linear-gradient(90deg, #ff1493, #8b5cf6);
    border-radius: 3px;
}
.xb-carousel-sec--themed .xb-carousel-arrow {
    background: rgba(15, 15, 15, 0.9) !important;
    border: 1px solid rgba(255, 20, 147, 0.3) !important;
    color: #fff !important;
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 20, 147, 0.1) inset;
    transition: all 0.3s ease;
}
.xb-carousel-sec--themed .xb-carousel-arrow:hover:not(:disabled) {
    background: rgba(255, 20, 147, 0.25) !important;
    border-color: rgba(255, 20, 147, 0.6) !important;
    box-shadow: 0 8px 30px rgba(255, 20, 147, 0.2), 0 0 0 1px rgba(255, 20, 147, 0.2) inset;
    transform: scale(1.05);
}
.xb-carousel-sec--themed .xb-carousel-arrow:disabled {
    opacity: 0.3;
}
.xb-carousel-sec--themed .xb-carousel-item a {
    border-radius: 16px;
    overflow: hidden;
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease;
}
.xb-carousel-sec--themed .xb-carousel-item a:hover {
    transform: translateY(-6px) scale(1.02);
    box-shadow: 0 20px 40px rgba(255, 20, 147, 0.15), 0 0 0 1px rgba(255, 20, 147, 0.15);
    z-index: 10;
}
.xb-carousel-sec--themed .xb-carousel-item .thumb-overlay::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, transparent 40%, rgba(0, 0, 0, 0.7) 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
    z-index: 1;
}
.xb-carousel-sec--themed .xb-carousel-item a:hover .thumb-overlay::before {
    opacity: 1;
}
.xb-carousel-sec--themed .xb-thumb-meta {
    transform: translateY(10px);
    opacity: 0;
    transition: all 0.3s ease;
}
.xb-carousel-sec--themed .xb-carousel-item a:hover .xb-thumb-meta {
    transform: translateY(0);
    opacity: 1;
}
.xb-carousel-sec--themed .xb-carousel-item img {
    transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1), filter 0.4s ease;
}
.xb-carousel-sec--themed .xb-carousel-item a:hover img {
    transform: scale(1.08);
    filter: saturate(1.15) brightness(1.05);
}
</style>
<div class="xb-carousel-sec xb-carousel-sec--themed mt-4">
	<div class="xb-section">
		<div class="xb-section-header">
			<span class="xb-section-badge">Categoria em destaque</span>
			<h2><i class="fas {$icon}"></i>{$title}</h2>
		</div>
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
							{if isset($videos[i].username) && $videos[i].username != 'anonymous'}
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
		
		var items = track.querySelectorAll('.xb-carousel-item');
		var itemCount = items.length;
		if (itemCount === 0) return;
		
		// Limit to 30 videos max
		var MAX_ITEMS = 30;
		if (itemCount > MAX_ITEMS) {
			for (var i = MAX_ITEMS; i < itemCount; i++) {
				items[i].style.display = 'none';
			}
			itemCount = MAX_ITEMS;
			items = Array.from(items).slice(0, MAX_ITEMS);
		}
		
		// Clone items for infinite loop
		var originalItems = Array.from(items);
		var isTransitioning = false;
		
		function itemWidth() {
			var first = track.querySelector('.xb-carousel-item');
			return first ? first.offsetWidth + 12 : 300;
		}
		
		function updateArrows() {
			var max = track.scrollWidth - track.clientWidth - 2;
			prev.disabled = track.scrollLeft <= 2;
			next.disabled = track.scrollLeft >= max;
		}
		
		function scrollToIndex(index, smooth) {
			var item = track.querySelectorAll('.xb-carousel-item')[index];
			if (!item) return;
			var target = item.offsetLeft - 12;
			track.scrollTo({ left: target, behavior: smooth ? 'smooth' : 'auto' });
		}
		
		prev.addEventListener('click', function() {
			if (isTransitioning) return;
			var first = track.querySelector('.xb-carousel-item');
			var target = track.scrollLeft - itemWidth() * 5;
			if (target <= 0) {
				isTransitioning = true;
				track.scrollTo({ left: track.scrollWidth - track.clientWidth, behavior: 'auto' });
				setTimeout(function() {
					track.scrollBy({ left: -itemWidth() * 5, behavior: 'smooth' });
					isTransitioning = false;
				}, 50);
			} else {
				track.scrollBy({ left: -itemWidth() * 5, behavior: 'smooth' });
			}
		});
		
		next.addEventListener('click', function() {
			if (isTransitioning) return;
			var max = track.scrollWidth - track.clientWidth - 2;
			var target = track.scrollLeft + itemWidth() * 5;
			if (target >= max) {
				isTransitioning = true;
				track.scrollTo({ left: 0, behavior: 'auto' });
				setTimeout(function() {
					track.scrollBy({ left: itemWidth() * 5, behavior: 'smooth' });
					isTransitioning = false;
				}, 50);
			} else {
				track.scrollBy({ left: itemWidth() * 5, behavior: 'smooth' });
			}
		});
		
		// Auto-scroll with loop
		var autoScrollTimer;
		var autoScrollSpeed = 3000; // ms between scrolls
		var scrollAmount = 1; // items per scroll
		
		function startAutoScroll() {
			stopAutoScroll();
			autoScrollTimer = setInterval(function() {
				if (isTransitioning) return;
				var max = track.scrollWidth - track.clientWidth - 2;
				var target = track.scrollLeft + itemWidth() * scrollAmount;
				if (target >= max) {
					isTransitioning = true;
					track.scrollTo({ left: 0, behavior: 'auto' });
					setTimeout(function() {
						track.scrollBy({ left: itemWidth() * scrollAmount, behavior: 'smooth' });
						isTransitioning = false;
					}, 50);
				} else {
					track.scrollBy({ left: itemWidth() * scrollAmount, behavior: 'smooth' });
				}
			}, autoScrollSpeed);
		}
		
		function stopAutoScroll() {
			if (autoScrollTimer) {
				clearInterval(autoScrollTimer);
				autoScrollTimer = null;
			}
		}
		
		// Pause on hover
		track.addEventListener('mouseenter', stopAutoScroll);
		track.addEventListener('mouseleave', startAutoScroll);
		prev.addEventListener('mouseenter', stopAutoScroll);
		prev.addEventListener('mouseleave', startAutoScroll);
		next.addEventListener('mouseenter', stopAutoScroll);
		next.addEventListener('mouseleave', startAutoScroll);
		
		// Pause on focus (accessibility)
		track.addEventListener('focusin', stopAutoScroll);
		track.addEventListener('focusout', startAutoScroll);
		
		track.addEventListener('scroll', updateArrows, { passive: true });
		window.addEventListener('resize', function() {
			updateArrows();
		});
		
		updateArrows();
		{if $enable_auto_scroll}startAutoScroll();{/if}
	})();
	</script>
</div>
{/if}