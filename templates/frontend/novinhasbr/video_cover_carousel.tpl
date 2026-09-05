{if $cover_frames|@count > 1}
<link href="{$relative_tpl}/css/lightbox.css" rel="stylesheet" />
<script type="text/javascript">
var lang_photo = "{t c='photo.Photo'}";
var lang_of = "{t c='global.of'}";
</script>
<script type="text/javascript" src="{$relative_tpl}/js/lightbox.js"></script>
<div class="xb-carousel-sec mt-4">
	<div class="xb-section">
		<span class="xb-section-bar"></span>
		<h2><i class="fas fa-images"></i>{t c='video.cover_images'}</h2>
	</div>
	<div class="xb-carousel xb-covers">
		<button type="button" class="xb-carousel-arrow xb-carousel-prev" aria-label="Anterior"><i class="fas fa-chevron-left"></i></button>
		<div class="xb-carousel-track xb-covers-track">
			{section name=i loop=$cover_urls}
			<div class="xb-covers-item">
				<a href="{$cover_urls[i]}" data-lightbox="video-covers-{$video.VID}" data-title="{$video.title|escape:'html'}">
					<img src="{$cover_urls[i]}" alt="{$video.title|escape:'html'}" class="img-responsive" loading="lazy">
					<span class="xb-cover-frame"><i class="fas fa-expand"></i></span>
				</a>
			</div>
			{/section}
		</div>
		<button type="button" class="xb-carousel-arrow xb-carousel-next" aria-label="Próximo"><i class="fas fa-chevron-right"></i></button>
	</div>
	<script>
	(function() {
		var sec = document.currentScript.parentElement;
		var track = sec.querySelector('.xb-covers-track');
		var prev = sec.querySelector('.xb-carousel-prev');
		var next = sec.querySelector('.xb-carousel-next');
		if (!track || !prev || !next) return;
		function itemWidth() {
			var first = track.querySelector('.xb-covers-item');
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