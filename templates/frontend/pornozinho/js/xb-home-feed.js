/**
 * AVSCMS — Feed "Novos" da home com botão "Exibir mais".
 * A 1ª página vem do servidor (index.tpl -> feed_grid.tpl); o botão pede as
 * seguintes a include/ajax/novos_feed.php (mesmo score híbrido), que devolve o
 * MESMO grid (feed_grid.tpl) e anexa em #novos-feed. Quando o serviço diz que
 * não tem mais (has_more=false ou count 0), o botão é removido.
 *
 * O lote chega como nós de nível superior já prontos — linhas
 * (.row.content-row) e faixas de anúncio irmãs delas. Anexar o lote cru dentro
 * da última linha jogaria a faixa para dentro do .row (display:flex) e ela
 * ficaria mais larga que as colunas; por isso cada nó vai para o grid. Só o
 * bloco .xb-feed-cont (cards que completam uma linha já renderizada) é movido
 * para dentro da última linha.
 *
 * A faixa de anúncio chega junto do lote e traz a própria tag (<script> +
 * <ins>). innerHTML NÃO executa <script>, então sem recriar esses nós o <ins>
 * fica vazio e a faixa vira uma caixa em branco ("o anúncio não funciona
 * repetido"). runScripts() recria cada script na mesma ordem do HTML, fazendo
 * todas as faixas — inclusive nas páginas seguintes — renderizarem o anúncio.
 */
(function (window, document) {
	'use strict';

	var grid = document.getElementById('novos-feed');
	var btn = document.getElementById('novos-feed-more');
	var wrap = btn && btn.parentNode;
	if (!grid || !btn || !wrap) {
		return;
	}

	var page = 1;
	var loading = false;
	var label = btn.querySelector('.xb-feed-more-label');

	function getLastRow() {
		var rows = grid.querySelectorAll('.row.content-row');
		return rows.length ? rows[rows.length - 1] : null;
	}

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

	// Scripts inseridos por innerHTML não rodam: recria cada <script> do nó
	// recém-anexado (na mesma ordem) para o navegador executá-lo. Sem isso a
	// tag do anúncio da faixa nunca é carregada e o <ins> fica vazio.
	function runScripts(scope) {
		var found = scope.getElementsByTagName('script');
		var list = [];
		var i;
		for (i = 0; i < found.length; i++) {
			list.push(found[i]);
		}
		for (i = 0; i < list.length; i++) {
			var old = list[i];
			var s = document.createElement('script');
			for (var a = 0; a < old.attributes.length; a++) {
				s.setAttribute(old.attributes[a].name, old.attributes[a].value);
			}
			s.text = old.text || old.textContent || '';
			old.parentNode.replaceChild(s, old);
		}
	}

	function appendCards(html) {
		var holder = document.createElement('div');
		holder.innerHTML = html;
		var touched = false;
		while (holder.firstChild) {
			var node = holder.firstChild;
			if (node.nodeType === 1 && node.classList.contains('xb-feed-cont')) {
				var row = getLastRow();
				if (row) {
					while (node.firstChild) {
						row.appendChild(node.firstChild);
					}
					holder.removeChild(node);
					touched = true;
					continue;
				}
				node.className = 'row content-row';
			}
			grid.appendChild(node);
			touched = true;
			if (node.nodeType === 1) {
				runScripts(node);
			}
		}
		if (touched && typeof xbOrientThumbs === 'function') {
			xbOrientThumbs();
		}
		window.dispatchEvent(new Event('resize'));
	}

	function loadNext() {
		if (loading) {
			return;
		}
		setLoading(true);

		fetch(base_url + '/ajax/novos_feed?page=' + (page + 1), {
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