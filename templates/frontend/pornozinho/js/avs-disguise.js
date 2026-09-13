/* ==========================================================================
   AVSCMS — Modo camuflado ("disfarce")
   Vanilla JS, sem dependências. Esconde a página adulta atrás de uma falsa
   vitrine de vídeos de carros (embeds do YouTube) quando o botão
   .xb-action-disguise é clicado ou quando a tecla Y é pressionada duas vezes
   rápido (janela de ~800ms; funciona em maiúsculo/minúsculo e é ignorado
   quando o foco está num campo de texto).

   Ao ativar: constrói o overlay (novo a cada vez, para os iframes recomeçarem
   limpos), troca o <title> e o favicon por uma identidade de site automotivo,
   pausa vídeo/áudio da página e trava o scroll. Ao sair: remove o overlay
   (destrói os iframes → nada continua tocando), devolve o título/favicon e
   retoma apenas o que tocava antes.

   Lista de vídeos: IDs válidos de vídeos reais de carros que permitem embed
   (verificados via oembed do YouTube). É a única parte que o dono do site
   precisa editar para trocar a "vitrine".
   ========================================================================== */
(function (window, document) {
	'use strict';

	var VID_LIST = [
		{ id: 'hc5-_c9ZYuo', title: 'GR Supra Final Edition — teste e track review', meta: 'Grassroots Motorsports' },
		{ id: '552gXtsUu6U', title: 'GR Supra Final Edition — a despedida de um ícone', meta: 'Braden Carlson' },
		{ id: 'mzmrqKETtgQ', title: '2026 GR Supra — um clássico do futuro? Review', meta: 'Realistick' },
		{ id: 'yKhPGg-90Vk', title: 'Supra 382 cv na Autobahn — pegada de fábrica', meta: 'AutoTopNL' },
		{ id: 'bQpQYXKWdOY', title: 'POV: Supra 770 cv em passeio urbano', meta: 'NOVITEC' }
	];

	var DG_PAGE_TITLE = 'AutoVitrine — Vídeos, testes e lançamentos de carros';
	var DG_FAVICON = 'data:image/svg+xml,' + encodeURIComponent(
		"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'>" +
		"<rect width='32' height='32' rx='6' fill='#0e1116'/>" +
		"<path d='M6 17.2L9 12h14l3 5.2V23H6z' fill='#1c2636' stroke='#4b8bf5' stroke-width='1.6' stroke-linejoin='round'/>" +
		"<circle cx='11' cy='20.5' r='2.1' fill='#4b8bf5'/><circle cx='21' cy='20.5' r='2.1' fill='#4b8bf5'/>" +
		"<path d='M9.5 14.5h13' stroke='#4b8bf5' stroke-width='1.1' stroke-linecap='round'/>" +
		'</svg>');

	var DOUBLE_KEY = 'y';
	var DOUBLE_WINDOW_MS = 800;
	var lastPressAt = 0;

	var originalTitle = document.title;
	var savedFavicons = [];
	var pausedMedia = [];

	var esc = function (s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	};

	var cardHTML = function (item) {
		var title = esc(item.title);
		return '<article class="avs-dg-card" data-dg-title="' + title + '">' +
			'<div class="avs-dg-frame">' +
			'<iframe src="https://www.youtube-nocookie.com/embed/' + encodeURIComponent(item.id) + '" title="' + title + '" ' +
			'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe>' +
			'</div>' +
			'<div class="avs-dg-card-body">' +
			'<h2 class="avs-dg-card-title">' + title + '</h2>' +
			'<span class="avs-dg-card-meta">' + esc(item.meta) + '</span>' +
			'</div>' +
			'</article>';
	};

	var buildOverlay = function () {
		var grid = '';
		for (var i = 0; i < VID_LIST.length; i++) {
			grid += cardHTML(VID_LIST[i]);
		}
		var div = document.createElement('div');
		div.id = 'avsDisguise';
		div.className = 'avs-dg';
		div.innerHTML =
			'<div class="avs-dg-inner">' +
				'<header class="avs-dg-top">' +
					'<div class="avs-dg-brand"><span class="avs-dg-badge">AV</span> AutoVitrine</div>' +
					'<nav class="avs-dg-nav">' +
						'<a href="#" class="avs-dg-link avs-dg-link-active">Novidades</a>' +
						'<a href="#" class="avs-dg-link">Testes</a>' +
						'<a href="#" class="avs-dg-link">Comparativos</a>' +
						'<a href="#" class="avs-dg-link">Lançamentos</a>' +
					'</nav>' +
					'<div class="avs-dg-search">' +
						'<i class="fas fa-search avs-dg-search-icon" aria-hidden="true"></i>' +
						'<input type="search" id="dgSearchQ" placeholder="Buscar carros, marcas, modelos..." aria-label="Buscar vídeos de carros" autocomplete="off">' +
					'</div>' +
					'<span class="avs-dg-pill">Modo camuflado</span>' +
					'<button type="button" class="avs-dg-exit" data-dg-exit title="Voltar ao site (ou pressione Y duas vezes)">Voltar</button>' +
				'</header>' +
				'<main>' +
					'<div class="avs-dg-head">' +
						'<div>' +
							'<h1 class="avs-dg-title">Releases da semana</h1>' +
							'<p class="avs-dg-sub">Testes, análises e novidades das máquinas mais esperadas.</p>' +
						'</div>' +
						'<div class="avs-dg-filter"><span class="material-symbols-rounded" aria-hidden="true">tune</span> Mais recentes</div>' +
					'</div>' +
					'<div class="avs-dg-grid" id="dgGrid">' + grid +
						'<div class="avs-dg-empty avs-dg-hidden" id="dgEmpty">Nenhum vídeo encontrado para a busca.</div>' +
					'</div>' +
				'</main>' +
				'<footer class="avs-dg-foot">' +
					'<div class="avs-dg-brand"><span class="avs-dg-badge">AV</span> AutoVitrine</div>' +
					'<span>Conteúdo automotivo em destaque.</span>' +
				'</footer>' +
			'</div>';
		return div;
	};

	var filterGrid = function () {
		var q = ((document.getElementById('dgSearchQ').value || '').trim().toLowerCase());
		var cards = document.querySelectorAll('#dgGrid .avs-dg-card');
		var any = false;
		for (var i = 0; i < cards.length; i++) {
			var match = !q || cards[i].getAttribute('data-dg-title').toLowerCase().indexOf(q) !== -1;
			cards[i].classList.toggle('avs-dg-hidden', !match);
			if (match) any = true;
		}
		document.getElementById('dgEmpty').classList.toggle('avs-dg-hidden', any);
	};

	var isOn = function () {
		return !!document.getElementById('avsDisguise');
	};

	var on = function () {
		var overlay = buildOverlay();
		document.body.appendChild(overlay);
		document.documentElement.classList.add('avs-dg-lock');

		originalTitle = document.title;
		document.title = DG_PAGE_TITLE;

		savedFavicons = [];
		var icons = document.querySelectorAll('link[rel~="icon"]');
		for (var i = 0; i < icons.length; i++) {
			savedFavicons.push({ el: icons[i], href: icons[i].href });
			icons[i].href = DG_FAVICON;
		}

		pausedMedia = [];
		var media = document.querySelectorAll('video, audio');
		for (var j = 0; j < media.length; j++) {
			if (!media[j].paused && media[j].currentTime > 0) {
				pausedMedia.push(media[j]);
				media[j].pause();
			}
		}

		window.scrollTo(0, 0);
	};

	var off = function () {
		var overlay = document.getElementById('avsDisguise');
		if (overlay) overlay.remove();
		document.documentElement.classList.remove('avs-dg-lock');

		document.title = originalTitle;

		for (var i = 0; i < savedFavicons.length; i++) {
			if (savedFavicons[i].el && document.contains(savedFavicons[i].el)) {
				savedFavicons[i].el.href = savedFavicons[i].href;
			}
		}
		savedFavicons = [];

		for (var j = 0; j < pausedMedia.length; j++) {
			if (document.contains(pausedMedia[j]) && !pausedMedia[j].ended) {
				var p = pausedMedia[j].play();
				if (p && p.catch) p.catch(function () {});
			}
		}
		pausedMedia = [];
	};

	var toggle = function () {
		if (isOn()) {
			off();
		} else {
			on();
		}
	};

	/* Botão da topbar e botão "Voltar" dentro do overlay */
	document.addEventListener('click', function (ev) {
		if (!ev.target || !ev.target.closest) return;
		var target = ev.target.closest('[data-dg-toggle], [data-dg-exit]');
		if (!target) return;
		ev.preventDefault();
		toggle();
	});

	/* Atalho: Y duas vezes. Ignorado enquanto o usuário digita em campos. */
	document.addEventListener('keydown', function (ev) {
		var t = ev.target;
		if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
		if (ev.metaKey || ev.ctrlKey || ev.altKey) return;
		if (ev.key !== DOUBLE_KEY && ev.key !== DOUBLE_KEY.toUpperCase()) return;
		var now = Date.now();
		if (now - lastPressAt <= DOUBLE_WINDOW_MS) {
			lastPressAt = 0;
			ev.preventDefault();
			toggle();
		} else {
			lastPressAt = now;
		}
	});

	/* Filtro "ao vivo" da busca da vitrine */
	document.addEventListener('input', function (ev) {
		if (ev.target && ev.target.id === 'dgSearchQ' && isOn()) filterGrid();
	});

	/* Expoõe para uso externo/console */
	window.AvsDisguise = {
		toggle: toggle,
		isOn: isOn
	};
})(window, document);