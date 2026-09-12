import { VidstackPlayer, VidstackPlayerLayout } from 'https://cdn.vidstack.io/player@1.15.6';

const root = document.querySelector('#vs-player');

function showError(msg) {
	if (!root) return;
	const box = root.querySelector('.vs-error');
	if (!box || box.textContent) return;
	box.textContent = msg;
	box.style.display = '';
}

async function boot() {
	try {
		const cfg = window.__vidstack || {};

		if (!Array.isArray(cfg.src) || !cfg.src.length) {
			showError('Nenhuma fonte de vídeo disponível para este vídeo.');
			return;
		}

		const layout = new VidstackPlayerLayout();
		const player = await VidstackPlayer.create({
			target: root,
			src: cfg.src,
			poster: cfg.poster || '',
			title: cfg.title || '',
			autoplay: !!cfg.autoplay,
			muted: !!cfg.start_muted,
			playsinline: true,
			layout,
			streamType: 'on-demand',
		});

		window.__vsReady = true;
		window.__vsPlayer = player;
	} catch (err) {
		console.error('[vidstack] failed to boot player:', err);
		showError('O player não pôde ser carregado. Verifique sua conexão ou desative bloqueadores de scripts e recarregue a página.');
	}
}

boot();