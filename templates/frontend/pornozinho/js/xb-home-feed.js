/**
 * AVSCMS — Feed único da home (Para Você) com botão "Exibir mais".
 * A 1ª página vem do servidor (index.tpl -> $home_feed_videos); o botão pede
 * as seguintes a include/ajax/home_feed.php (mesmo score híbrido) e anexa os
 * cards DENTRO do #home-feed. Quando o serviço diz que não tem mais
 * (has_more=false ou count 0), o botão é removido.
 */
(function (window, document) {
	'use strict';

	var grid = document.getElementById('home-feed');
	var btn = document.getElementById('home-feed-more');
	var wrap = btn && btn.parentNode;
	if (!grid || !btn || !wrap) {
		return;
	}

	var page = 1;
	var loading = false;
	var label = btn.querySelector('.xb-feed-more-label');

	function setLoading(on) {
		loading = on;
		btn.classList.toggle('is-loading', on);
		btn.disabled = on;
		label.textContent = on ? 'Carregando...' : 'Exibir mais';
	}

	function finish() {
		if (wrap.parentNode) {
			wrap.parentNode.removeChild(wrap);
		}
	}

	function appendCards(html) {
		var holder = document.createElement('div');
		holder.innerHTML = html;
		var nodes = holder.childNodes;
		while (holder.firstChild) {
			grid.appendChild(holder.firstChild);
		}
		// Os novos cards trazem .thumb-overlay (fundo portrait etc.) e faixas de
		// tags; xbOrientThumbs (jquery.rotator) e a marcação .is-scrollable das
		// setas (xb-tags-rail) são funções de quadro — re-disparar fica barato
		// porque ambas já sabem pular o que está feito.
		if (nodes.length && typeof xbOrientThumbs === 'function') {
			xbOrientThumbs();
		}
		window.dispatchEvent(new Event('resize'));
	}

	function loadNext() {
		if (loading) {
			return;
		}
		setLoading(true);

		fetch(base_url + '/ajax/home_feed?page=' + (page + 1), {
			credentials: 'same-origin'
		}).then(function (res) {
			return res.json();
		}).then(function (data) {
			setLoading(false);
			if (!data || data.status !== 1 || !data.html || data.count === 0) {
				finish();
				return;
			}
			page = data.page;
			appendCards(data.html);
			if (!data.has_more) {
				finish();
			}
		}).catch(function () {
			setLoading(false);
		});
	}

	btn.addEventListener('click', loadNext);

	// A 2ª página carrega sozinha (usuário pediu 2 páginas automáticas);
	// o botão é para as seguintes.
	loadNext();
})(window, document);