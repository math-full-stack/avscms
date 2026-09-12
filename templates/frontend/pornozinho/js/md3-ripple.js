/* ==========================================================================
   Material Design 3 — Ripple
   Vanilla JS, sem dependências. Listener delegado (pointerdown) que injeta um
   span.md3-ripple nos controles interativos do tema. O estilo/animação vive em
   css/pornozinho-md3.css (.md3-ripple / @keyframes md3-ripple-in).
   ========================================================================== */
(function (window, document) {
	'use strict';

	var RIPPLE_SELECTOR = [
		'.btn',
		'.dropdown-item',
		'.nav-link',
		'.xb-action',
		'.xb-nav-link',
		'.well-action',
		'.page-link',
		'.list-group-item',
		'.xb-search-type-btn',
		'.xb-dropdown-list li a',
		'.xb-dropdown-video',
		'.xb-dropdown-cat',
		'.xb-dropdown-view-all',
		'.xb-creator-card',
		'.tag',
		'.xb-cat-card',
		'.thumb-overlay',
		'.xb-covers-item a'
	].join(', ');

	var isDisabled = function (el) {
		return !el || el.matches(':disabled, .disabled, [aria-disabled="true"]');
	};

	/* O alvo precisa ser containing block do ripple. Overflow hidden clipeia o
	   círculo ao formato do controle (cantos do pill/card). */
	var ensureContainer = function (el) {
		var pos = window.getComputedStyle(el).position;
		var overflow = window.getComputedStyle(el).overflow;
		if (pos === 'static') {
			el.style.position = 'relative';
		}
		if (overflow === 'visible') {
			el.style.overflow = 'hidden';
		}
	};

	var spawnRipple = function (el, clientX, clientY) {
		var rect = el.getBoundingClientRect();
		var size = Math.max(rect.width, rect.height) * 2;

		ensureContainer(el);

		var ripple = document.createElement('span');
		ripple.className = 'md3-ripple';
		ripple.style.width = size + 'px';
		ripple.style.height = size + 'px';
		ripple.style.left = (clientX - rect.left - size / 2) + 'px';
		ripple.style.top = (clientY - rect.top - size / 2) + 'px';

		el.appendChild(ripple);

		var remove = function () {
			if (ripple.parentNode === el) {
				el.removeChild(ripple);
			}
		};

		ripple.addEventListener('animationend', remove);
		/* Fallback caso o animationend não dispare (reduzid motion, tab inativo) */
		window.setTimeout(remove, 900);
	};

	document.addEventListener('pointerdown', function (event) {
		var el = event.target && event.target.closest ? event.target.closest(RIPPLE_SELECTOR) : null;
		if (!el || isDisabled(el)) {
			return;
		}
		spawnRipple(el, event.clientX, event.clientY);
	}, true);
})(window, document);