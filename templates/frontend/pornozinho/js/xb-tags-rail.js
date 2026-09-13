/**
 * AVSCMS — faixa de tags dos cards de vídeo (uma linha, rolável).
 * Mantém as tags em UMA linha: arrasta com o mouse, rola com a roda e com a
 * seta discreta da direita. A seta só aparece quando a faixa estoura (classe
 * .is-scrollable, que também vive no CSS). Usa delegação de eventos, então
 * vale para cards injetados depois (paginação, feed).
 */
(function () {
	'use strict';

	// Passo da seta: um pouco menos que a largura visível, para não esconder tag.
	var STEP = 0.7;

	function scroller(el) {
		return el && el.closest ? el.closest('.xb-tags-rail .xb-tags') : null;
	}

	function railOf(scrollEl) {
		return scrollEl && scrollEl.closest ? scrollEl.closest('.xb-tags-rail') : null;
	}

	function mark(rail) {
		var sc = rail.querySelector('.xb-tags');
		if (!sc) return;
		rail.classList.toggle('is-scrollable', sc.scrollWidth - sc.clientWidth > 2);
	}

	function markAll() {
		Array.prototype.forEach.call(document.querySelectorAll('.xb-tags-rail'), mark);
	}

	// Seta: avança um passo e volta ao começo quando já está no fim.
	document.addEventListener('click', function (e) {
		var next = e.target.closest ? e.target.closest('.xb-tags-next') : null;
		if (!next) return;
		var sc = railOf(next).querySelector('.xb-tags');
		if (!sc) return;
		var atEnd = sc.scrollLeft + sc.clientWidth >= sc.scrollWidth - 2;
		sc.scrollTo({ left: atEnd ? 0 : sc.scrollLeft + sc.clientWidth * STEP, behavior: 'smooth' });
	});

	// Roda do mouse sobre a faixa rola na horizontal.
	document.addEventListener('wheel', function (e) {
		var sc = scroller(e.target);
		if (!sc) return;
		if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
		e.preventDefault();
		sc.scrollLeft += e.deltaY;
	}, { passive: false });

	// Arraste com o ponteiro. No toque o scroll nativo já resolve — o alvo é o mouse.
	var drag = null;

	document.addEventListener('pointerdown', function (e) {
		if (e.pointerType === 'touch' || e.button !== 0) return;
		var sc = scroller(e.target);
		if (!sc) return;
		drag = { sc: sc, x: e.clientX, left: sc.scrollLeft, moved: false };
		sc.style.scrollBehavior = 'auto';
		sc.style.cursor = 'grabbing';
	});

	document.addEventListener('pointermove', function (e) {
		if (!drag) return;
		var dx = e.clientX - drag.x;
		if (Math.abs(dx) > 3) drag.moved = true;
		drag.sc.scrollLeft = drag.left - dx;
	});

	function endDrag() {
		if (!drag) return;
		var sc = drag.sc;
		sc.style.scrollBehavior = '';
		sc.style.cursor = '';
		if (drag.moved) {
			// Engole o clique que fecha o arraste, senão a tag abriria a busca.
			var swallow = function (ev) { ev.preventDefault(); ev.stopPropagation(); };
			sc.addEventListener('click', swallow, true);
			setTimeout(function () { sc.removeEventListener('click', swallow, true); }, 0);
		}
		drag = null;
	}

	document.addEventListener('pointerup', endDrag);
	document.addEventListener('pointercancel', endDrag);

	function boot() {
		markAll();
		window.addEventListener('resize', markAll, { passive: true });
		window.addEventListener('load', markAll);
		// A largura das tags depende das fontes (Roboto/Material Symbols).
		if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
			document.fonts.ready.then(markAll);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
