/**
 * AVSCMS — Controlador de Vídeos Verticais (Reels / Shorts)
 * Scroll Snap, Auto-Play, Double-Tap Like, Volume Persistente,
 * Wheel Debounce, Comentários em Drawer e Feed Infinito.
 */

(function () {
	'use strict';

	var app = document.getElementById('avs-shorts-app');
	if (!app) return;

	var stream = document.getElementById('avs-shorts-stream');
	var baseUrl = app.getAttribute('data-baseurl') || '';
	var activeTab = app.getAttribute('data-active-tab') || 'foryou';
	var isLogged = app.getAttribute('data-logged') === '1';

	// Estado Centralizado
	var state = {
		isMuted: true,
		activeCard: null,
		activeIndex: 0,
		page: 1,
		isLoadingMore: false,
		hasMore: false, // Sem infinite scroll - todos carregados no server-side
		seenVids: new Set(window.__AVS_EXCLUDE_VIDS || []),
		wheelCooldown: false,
		adLockUntil: 0,
		adLockIndex: -1,
		touchStartY: 0,
		lastTapTime: 0,
		tapTimeout: null,
		activeCommentsVid: 0,
		canLoopForward: false,
		canLoopBackward: false
	};

	// Recuperar preferência de mudo no localStorage
	try {
		var savedMuted = localStorage.getItem('avs_shorts_muted');
		if (savedMuted !== null) {
			state.isMuted = (savedMuted === '1');
		}
	} catch (e) {}

	// Sincronizar UI global de som
	function syncSoundUi() {
		var globalOn = document.querySelector('#avs-global-mute-btn .sound-icon-on');
		var globalOff = document.querySelector('#avs-global-mute-btn .sound-icon-off');
		if (globalOn && globalOff) {
			globalOn.style.display = state.isMuted ? 'none' : 'inline-block';
			globalOff.style.display = state.isMuted ? 'inline-block' : 'none';
		}

		// Atualizar todos os botões de som individuais
		document.querySelectorAll('.avs-short-card').forEach(function (card) {
			var video = card.querySelector('video');
			if (video) {
				video.muted = state.isMuted;
			}
			var btnOn = card.querySelector('.icon-sound-on');
			var btnOff = card.querySelector('.icon-sound-off');
			if (btnOn && btnOff) {
				btnOn.style.display = state.isMuted ? 'none' : 'inline-block';
				btnOff.style.display = state.isMuted ? 'inline-block' : 'none';
			}
			var badge = card.querySelector('.avs-unmute-overlay-badge');
			if (badge) {
				if (!state.isMuted) {
					badge.classList.add('hidden');
				}
			}
		});
	}

	function setMuted(muted) {
		state.isMuted = muted;
		try {
			localStorage.setItem('avs_shorts_muted', muted ? '1' : '0');
		} catch (e) {}
		syncSoundUi();
		showToast(muted ? 'Áudio desativado' : 'Áudio ativado');
	}

	// Toast Notificação
	var toastEl = document.getElementById('avs-shorts-toast');
	var toastTimer = null;
	function showToast(msg) {
		if (!toastEl) return;
		toastEl.textContent = msg;
		toastEl.classList.add('show');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () {
			toastEl.classList.remove('show');
		}, 2400);
	}

	// IntersectionObserver para Auto-Play e detecção de card ativo
	var observerOptions = {
		root: stream,
		threshold: 0.65
	};

	var cardObserver = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			var card = entry.target;

			if (entry.isIntersecting) {
				// Pausar vídeo anterior se for outro. Precisa acontecer ANTES de
				// checar vídeo: um card de anúncio (sem <video>) tem que parar o
				// que tocava atrás — senão o áudio do short continua sob o anúncio.
				if (state.activeCard && state.activeCard !== card) {
					var prevVideo = state.activeCard.querySelector('video');
					if (prevVideo) {
						prevVideo.pause();
					}
				}

				state.activeCard = card;
				state.activeIndex = parseInt(card.getAttribute('data-index') || '0', 10);
				var vid = card.getAttribute('data-vid');

				// Atualizar URL sem recarregar para suporte a deep-link compartilhável
				if (vid && window.history && window.history.replaceState) {
					var newUrl = baseUrl + '/shorts?v=' + vid;
					window.history.replaceState({ vid: vid }, '', newUrl);
				}

				var video = card.querySelector('video');

				// Lock de anúncio entre shorts: um card de anúncio ativo marca
				// 3s de espera (o avanço fica bloqueado até expirar).
				syncAdLock(card);

				if (!video) return; // anúncio: sem reprodução

				// Tocar vídeo ativo
				video.muted = state.isMuted;
				var playPromise = video.play();
				if (playPromise !== undefined) {
					playPromise.catch(function (err) {
						// Se browser bloqueou com som, tentar mutado
						if (!video.muted) {
							video.muted = true;
							state.isMuted = true;
							syncSoundUi();
							video.play();
						}
					});
				}

				// Pré-carregar próximo vídeo no stream
				var nextCard = card.nextElementSibling;
				if (nextCard && nextCard.classList.contains('avs-short-card')) {
					var nextVideo = nextCard.querySelector('video');
					if (nextVideo && nextVideo.preload !== 'auto') {
						nextVideo.preload = 'auto';
					}
				}

				// Checar se estamos no fim para loop infinito (em vez de infinite scroll)
				var allCards = stream.querySelectorAll('.avs-short-card');
				var currentIndex = Array.prototype.indexOf.call(allCards, card);
				if (currentIndex >= allCards.length - 1) {
					// No último card - preparar para loop para o primeiro
					// Não faz scroll automático, apenas marca que pode loopar
					state.canLoopForward = true;
				} else {
					state.canLoopForward = false;
				}
				if (currentIndex <= 0) {
					state.canLoopBackward = true;
				} else {
					state.canLoopBackward = false;
				}
			} else {
				// Pausar se saiu do viewport
				var video = card.querySelector('video');
				if (video) {
					video.pause();
				}
			}
		});
	}, observerOptions);

	// Anexar observador a todos os cards iniciais
	function bindCards() {
		var cards = stream.querySelectorAll('.avs-short-card');
		cards.forEach(function (card) {
			if (!card.__avs_bound) {
				card.__avs_bound = true;
				cardObserver.observe(card);
				setupCardEvents(card);
				var vid = parseInt(card.getAttribute('data-vid') || '0', 10);
				if (vid > 0) state.seenVids.add(vid);
			}
		});
	}

	// Configurar eventos de cada card
	function setupCardEvents(card) {
		var vid = card.getAttribute('data-vid');
		var playerWrapper = card.querySelector('.avs-player-wrapper');
		var video = card.querySelector('video');
		var pulseEl = card.querySelector('.avs-play-pulse');
		var pulsePlayIcon = pulseEl ? pulseEl.querySelector('.icon-play') : null;
		var pulsePauseIcon = pulseEl ? pulseEl.querySelector('.icon-pause') : null;
		var progressBar = card.querySelector('.avs-short-progress-bar');
		var progressFill = card.querySelector('.avs-short-progress-fill');
		var heartBurstContainer = card.querySelector('.avs-heart-burst');
		var unmuteBadge = card.querySelector('.avs-unmute-overlay-badge');

		// Box do player acompanha o aspecto REAL do vídeo (--avs-video-ar). O
		// server já manda o do banco; isto refina/conserta quando não há medida
		// confiável — sem isso um vídeo fora de 9:16 fica com barra ou cortado.
		if (video && playerWrapper) {
			var applyAspect = function () {
				if (video.videoWidth > 0 && video.videoHeight > 0) {
					playerWrapper.style.setProperty('--avs-video-ar', (video.videoWidth / video.videoHeight).toFixed(5));
				}
			};
			if (video.readyState >= 1) {
				applyAspect();
			} else {
				video.addEventListener('loadedmetadata', applyAspect);
			}
		}

		// Scrubber / Progresso
		if (video && progressFill) {
			video.addEventListener('timeupdate', function () {
				if (video.duration) {
					var pct = (video.currentTime / video.duration) * 100;
					progressFill.style.width = pct + '%';
				}
			});
		}

		if (progressBar && video) {
			progressBar.addEventListener('click', function (e) {
				e.stopPropagation();
				var rect = progressBar.getBoundingClientRect();
				var pos = (e.clientX - rect.left) / rect.width;
				if (video.duration) {
					video.currentTime = pos * video.duration;
				}
			});
		}

		// Clique no badge de áudio
		if (unmuteBadge) {
			unmuteBadge.addEventListener('click', function (e) {
				e.stopPropagation();
				setMuted(false);
			});
		}

		// Clique simples vs Double Tap no Player Wrapper
		if (playerWrapper) {
			playerWrapper.addEventListener('click', function (e) {
				// Ignorar se o clique veio de botões ou links internos
				if (e.target.closest('.avs-short-actions, .avs-short-meta-bottom, .avs-unmute-overlay-badge, .avs-short-progress-bar')) {
					return;
				}

				var now = Date.now();
				var diff = now - state.lastTapTime;
				state.lastTapTime = now;

				if (diff < 300) {
					// Double Tap -> Like
					clearTimeout(state.tapTimeout);
					spawnHeart(e, heartBurstContainer);
					triggerLike(card, vid);
				} else {
					// Clique simples -> Play / Pause após pequeno delay
					state.tapTimeout = setTimeout(function () {
						if (!video) return;
						if (video.paused) {
							video.play();
							flashPulse(pulseEl, pulsePlayIcon, pulsePauseIcon, true);
						} else {
							video.pause();
							flashPulse(pulseEl, pulsePlayIcon, pulsePauseIcon, false);
						}
					}, 240);
				}
			});
		}

		// Botão de Like na lateral
		var likeBtn = card.querySelector('.avs-btn-like');
		if (likeBtn) {
			likeBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				triggerLike(card, vid);
			});
		}

		// Botão de Favoritar
		var favBtn = card.querySelector('.avs-btn-fav');
		if (favBtn) {
			favBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				triggerFavorite(favBtn, vid);
			});
		}

		// Botão de Comentários
		var commentsBtn = card.querySelector('.avs-btn-comments');
		if (commentsBtn) {
			commentsBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				openComments(vid);
			});
		}

		// Botão de Compartilhar
		var shareBtn = card.querySelector('.avs-btn-share');
		if (shareBtn) {
			shareBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				var shareUrl = card.getAttribute('data-share-url') || window.location.href;
				var shareTitle = card.getAttribute('data-title') || document.title;
				if (navigator.share) {
					navigator.share({
						title: shareTitle,
						url: shareUrl
					}).catch(function () {});
				} else {
					if (navigator.clipboard) {
						navigator.clipboard.writeText(shareUrl).then(function () {
							showToast('Link do vídeo copiado para a área de transferência!');
						});
					} else {
						showToast('Link: ' + shareUrl);
					}
				}
			});
		}

		// Botão de Som Individual
		var soundBtn = card.querySelector('.avs-btn-sound');
		if (soundBtn) {
			soundBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				setMuted(!state.isMuted);
			});
		}

		// Botões de Follow
		var followBtns = card.querySelectorAll('.avs-btn-follow, .avs-action-plus-btn');
		followBtns.forEach(function (fbtn) {
			fbtn.addEventListener('click', function (e) {
				e.stopPropagation();
				var uid = fbtn.getAttribute('data-uid');
				triggerSubscribe(fbtn, uid, card);
			});
		});

		// Botões Desktop de Próximo / Anterior
		var prevBtn = card.querySelector('.avs-nav-prev');
		var nextBtn = card.querySelector('.avs-nav-next');
		if (prevBtn) {
			prevBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				scrollCard(-1);
			});
		}
		if (nextBtn) {
			nextBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				scrollCard(1);
			});
		}

		// Descrição expansível
		var descEl = card.querySelector('.avs-short-desc');
		if (descEl) {
			descEl.addEventListener('click', function (e) {
				e.stopPropagation();
				descEl.classList.toggle('expanded');
			});
		}
	}

	// Feedback animado de Play / Pause
	function flashPulse(pulseEl, playIcon, pauseIcon, isPlaying) {
		if (!pulseEl) return;
		if (isPlaying) {
			if (playIcon) playIcon.style.display = 'inline-block';
			if (pauseIcon) pauseIcon.style.display = 'none';
		} else {
			if (playIcon) playIcon.style.display = 'none';
			if (pauseIcon) pauseIcon.style.display = 'inline-block';
		}
		pulseEl.classList.add('active');
		setTimeout(function () {
			pulseEl.classList.remove('active');
		}, 450);
	}

	// Animação de Coração Explodindo no Double-Tap
	function spawnHeart(e, container) {
		if (!container) return;
		var rect = container.getBoundingClientRect();
		var x = e.clientX - rect.left;
		var y = e.clientY - rect.top;

		var heart = document.createElement('span');
		heart.className = 'material-symbols-rounded avs-floating-heart';
		heart.textContent = 'favorite';
		heart.style.left = x + 'px';
		heart.style.top = y + 'px';
		container.appendChild(heart);

		setTimeout(function () {
			heart.remove();
		}, 900);
	}

	// Ação de Like
	function triggerLike(card, vid) {
		var likeBtn = card.querySelector('.avs-btn-like');
		var countEl = card.querySelector('.avs-like-count');
		var wasActive = likeBtn ? likeBtn.classList.contains('active') : false;

		if (likeBtn) {
			likeBtn.classList.toggle('active', !wasActive);
		}

		if (countEl) {
			var currentCount = parseInt(countEl.getAttribute('data-count') || '0', 10);
			var newCount = wasActive ? Math.max(0, currentCount - 1) : currentCount + 1;
			countEl.setAttribute('data-count', newCount);
			countEl.textContent = formatCount(newCount);
		}

		// Enviar voto via AJAX
		$.ajax({
			url: baseUrl + '/ajax/vote',
			type: 'POST',
			dataType: 'json',
			data: {
				type: 'video',
				id: vid,
				vote: wasActive ? 'down' : 'up'
			},
			success: function (res) {
				if (res && res.likes !== undefined && countEl) {
					countEl.setAttribute('data-count', res.likes);
					countEl.textContent = formatCount(res.likes);
				}
			}
		});
	}

	// Ação de Favorito
	function triggerFavorite(btn, vid) {
		if (!isLogged) {
			showToast('Faça login para salvar este vídeo!');
			return;
		}

		$.ajax({
			url: baseUrl + '/ajax/favorite_video',
			type: 'POST',
			dataType: 'json',
			data: { video_id: vid },
			success: function (res) {
				if (res && res.status === 1) {
					btn.classList.add('active');
					showToast('Vídeo adicionado aos favoritos!');
				} else if (res && res.msg) {
					showToast(res.msg);
				}
			}
		});
	}

	// Ação de Seguir Criador
	function triggerSubscribe(btn, uid, card) {
		if (!isLogged) {
			showToast('Faça login para seguir o criador!');
			return;
		}

		$.ajax({
			url: baseUrl + '/ajax/user_subscription',
			type: 'POST',
			dataType: 'json',
			data: {
				user_id: uid,
				action: 'subscribe'
			},
			success: function (res) {
				card.querySelectorAll('.avs-btn-follow').forEach(function (f) {
					f.classList.add('following');
					f.textContent = 'Seguindo';
				});
				card.querySelectorAll('.avs-action-plus-btn').forEach(function (p) {
					p.classList.add('active');
				});
				showToast('Inscrição confirmada!');
			}
		});
	}

	// Formatação simples de números
	function formatCount(num) {
		num = parseInt(num, 10);
		if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
		if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
		return String(num);
	}

	// --- Lock de anúncio (3s) ---
	// Marca/limpa a janela de bloqueio quando o card ativo é um anúncio (o pill
	// de contagem vem no markup do próprio card, servidor ou AJAX).
	function syncAdLock(card) {
		if (card && card.getAttribute('data-ad') === '1') {
			if (state.adLockUntil <= 0 || state.adLockIndex !== state.activeIndex) {
				state.adLockUntil = Date.now() + 3000;
				state.adLockIndex = state.activeIndex;
			}
		} else {
			clearAdLock();
		}
	}

	function clearAdLock() {
		state.adLockUntil = 0;
		state.adLockIndex = -1;
		if (state.activeCard) {
			var pill = state.activeCard.querySelector('.avs-ad-lock');
			if (pill) pill.parentNode.removeChild(pill);
		}
	}

	// Tick único do countdown: atualiza o número do pill e libera quando o lock
	// expira ou quando o card ativo deixa de ser o anúncio correspondente.
	setInterval(function () {
		if (!state.adLockUntil) return;

		var remain = state.adLockUntil - Date.now();
		var active = state.activeCard;
		if (remain <= 0 || !active || active.getAttribute('data-ad') !== '1') {
			clearAdLock();
			return;
		}

		var pill = active.querySelector('.avs-ad-lock');
		if (!pill) return;
		var b = pill.querySelector('.avs-ad-lock-count');
		if (b) b.textContent = String(Math.max(1, Math.ceil(remain / 1000)));
	}, 200);

	// Navegação entre cards (offset: +1 ou -1) com loop infinito
	function scrollCard(offset) {
		var cards = stream.querySelectorAll('.avs-short-card');
		if (!cards.length) return;

		// Lock de anúncio ENTRE shorts: durante os primeiros 3s do anúncio ativo
		// não deixa AVANÇAR (offset > 0). Voltar segue liberado. Todos os caminhos
		// de avanço (teclado, wheel, botões desktop, teaser ad-next) passam por aqui.
		if (offset > 0 && state.adLockUntil && Date.now() < state.adLockUntil
			&& cards[state.activeIndex] && cards[state.activeIndex].getAttribute('data-ad') === '1') {
			return;
		}

		var targetIndex = state.activeIndex + offset;
		
		// Loop infinito: último -> primeiro, primeiro -> último
		if (targetIndex >= cards.length) {
			targetIndex = 0; // Loop para o primeiro
		} else if (targetIndex < 0) {
			targetIndex = cards.length - 1; // Loop para o último
		}
		
		var targetCard = cards[targetIndex];
		if (targetCard) {
			targetCard.scrollIntoView({ behavior: 'smooth' });
		}
	}

	// Wheel Lock Debounce no Desktop (para não disparar 10 cards por giro de mouse)
	stream.addEventListener('wheel', function (e) {
		if (state.wheelCooldown) {
			e.preventDefault();
			return;
		}

		if (Math.abs(e.deltaY) > 35) {
			e.preventDefault();
			state.wheelCooldown = true;
			if (e.deltaY > 0) {
				scrollCard(1);
			} else {
				scrollCard(-1);
			}
			setTimeout(function () {
				state.wheelCooldown = false;
			}, 360);
		}
	}, { passive: false });

	// Swipe nativo (mobile): quando o anúncio entre shorts está no lock de 3s,
	// deslizar PARA FRENTE (dy < 0) fica bloqueado no touchmove — o snap do
	// scroll nativo não passa por scrollCard. Para trás continua livre.
	stream.addEventListener('touchstart', function (e) {
		state.touchStartY = e.touches[0].clientY;
	}, { passive: true });

	stream.addEventListener('touchmove', function (e) {
		if (!state.adLockUntil || Date.now() >= state.adLockUntil) return;
		var active = state.activeCard;
		if (!active || active.getAttribute('data-ad') !== '1') return;
		var dy = e.touches[0].clientY - (state.touchStartY || e.touches[0].clientY);
		if (dy < 0) e.preventDefault();
	}, { passive: false });

	// Clique no teaser "Próximo vídeo" do card de anúncio (delegado no stream:
	// cobre os cards do servidor e os injetados pelo AJAX). Vale para todos os
	// cards de anúncio, então usa o data-index do card atual para avançar.
	stream.addEventListener('click', function (e) {
		var next = e.target.closest('[data-action="ad-next"]');
		if (!next) return;
		e.preventDefault();
		var adCard = next.closest('.avs-short-card');
		if (adCard) {
			state.activeIndex = parseInt(adCard.getAttribute('data-index') || '0', 10);
		}
		scrollCard(1);
	});

	// Atalhos de Teclado
	window.addEventListener('keydown', function (e) {
		// Ignorar se estiver digitando em campo de texto
		if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
			return;
		}

		switch (e.key) {
			case 'ArrowDown':
			case 'j':
			case 'J':
				e.preventDefault();
				scrollCard(1);
				break;
			case 'ArrowUp':
			case 'k':
			case 'K':
				e.preventDefault();
				scrollCard(-1);
				break;
			case ' ':
				e.preventDefault();
				if (state.activeCard) {
					var v = state.activeCard.querySelector('video');
					if (v) {
						if (v.paused) v.play();
						else v.pause();
					}
				}
				break;
			case 'm':
			case 'M':
				e.preventDefault();
				setMuted(!state.isMuted);
				break;
			case 'l':
			case 'L':
				e.preventDefault();
				if (state.activeCard) {
					var vid = state.activeCard.getAttribute('data-vid');
					if (!vid) return; // anúncio: sem ações de vídeo
					triggerLike(state.activeCard, vid);
				}
				break;
			case 'c':
			case 'C':
				e.preventDefault();
				if (state.activeCard) {
					var vid = state.activeCard.getAttribute('data-vid');
					if (!vid) return; // anúncio: sem ações de vídeo
					toggleComments(vid);
				}
				break;
			case 'Escape':
				closeComments();
				break;
		}
	});

	// Botão Global de Mudo no Topo
	var globalMuteBtn = document.getElementById('avs-global-mute-btn');
	if (globalMuteBtn) {
		globalMuteBtn.addEventListener('click', function () {
			setMuted(!state.isMuted);
		});
	}

	// Carregar mais vídeos (Infinite Scroll)
	function loadMoreVideos() {
		if (state.isLoadingMore || !state.hasMore) return;
		state.isLoadingMore = true;

		var loader = document.getElementById('avs-shorts-infinite-loader');
		if (loader) loader.style.display = 'flex';

		var excludeArr = Array.from(state.seenVids);
		state.page++;

		$.ajax({
			url: baseUrl + '/ajax/shorts_feed',
			type: 'GET',
			dataType: 'json',
			data: {
				tab: activeTab,
				page: state.page,
				limit: 5,
				exclude: excludeArr.join(',')
			},
			success: function (res) {
				if (loader) loader.style.display = 'none';
				state.isLoadingMore = false;

				if (res && res.status === 1 && res.videos && res.videos.length > 0) {
					appendVideos(res.videos);
					if (!res.has_more) {
						state.hasMore = false;
					}
				} else {
					state.hasMore = false;
				}
			},
			error: function () {
				if (loader) loader.style.display = 'none';
				state.isLoadingMore = false;
			}
		});
	}

	// Anexar novos vídeos ao DOM
	function appendVideos(videos) {
		var allCards = stream.querySelectorAll('.avs-short-card');
		var startIndex = allCards.length;

		videos.forEach(function (v, idx) {
			// Card de anúncio (híbrido: banner + próximo vídeo): vem do server
			// com is_ad — não conta como vídeo visto nem carrega <video>.
			if (v.is_ad) {
				var adIndex = startIndex + idx;
				var adEl = document.createElement('article');
				adEl.className = 'avs-short-card avs-ad-card';
				adEl.setAttribute('data-index', adIndex);
				adEl.setAttribute('data-ad', '1');
				adEl.innerHTML = [
					'<div class="avs-ambient-bg" style="background-image: url(\'' + escapeHtml(v.next_short.poster_url) + '\');" aria-hidden="true"></div>',
					'<div class="avs-ad-stage">',
					'  <span class="avs-ad-tag">Anúncio</span>',
					'  <div class="avs-ad-slot">' + v.adv_html + '</div>',
					'</div>',
					'<a class="avs-ad-next" data-action="ad-next" role="button" aria-label="Próximo vídeo">',
					'  <span class="avs-ad-next-thumb" style="background-image: url(\'' + escapeHtml(v.next_short.poster_url) + '\');"></span>',
					'  <span class="avs-ad-next-meta">',
					'    <span class="avs-ad-next-label">Próximo vídeo</span>',
					'    <span class="avs-ad-next-title">' + escapeHtml(v.next_short.title) + '</span>',
					'  </span>',
					'  <span class="material-symbols-rounded avs-ad-next-icon" aria-hidden="true">skip_next</span>',
					'</a>',
					'<div class="avs-ad-lock" aria-hidden="true">',
					'  <span class="material-symbols-rounded">timer</span>',
					'  <b class="avs-ad-lock-count">3</b>',
					'</div>'
				].join('');
				var loaderAd = document.getElementById('avs-shorts-infinite-loader');
				if (loaderAd) {
					stream.insertBefore(adEl, loaderAd);
				} else {
					stream.appendChild(adEl);
				}
				return;
			}

			if (state.seenVids.has(v.vid)) return;
			state.seenVids.add(v.vid);

			var cardIndex = startIndex + idx;
			var card = document.createElement('article');
			card.className = 'avs-short-card';
			card.id = 'short-' + v.vid;
			card.setAttribute('data-vid', v.vid);
			card.setAttribute('data-index', cardIndex);
			card.setAttribute('data-title', v.title);
			card.setAttribute('data-author', v.creator.username);
			card.setAttribute('data-share-url', v.share_url);

			var vertClass = v.is_vertical ? 'avs-is-vertical' : 'avs-is-horizontal';
			var likeActive = v.is_liked ? 'active' : '';
			var favActive = v.is_fav ? 'active' : '';
			var followActive = v.creator.is_subscribed ? 'active' : '';
			var followBtnText = v.creator.is_subscribed ? 'Seguindo' : 'Seguir';
			var followBtnClass = v.creator.is_subscribed ? 'following' : '';

			card.innerHTML = [
				'<div class="avs-ambient-bg" style="background-image: url(\'' + escapeHtml(v.poster_url) + '\');" aria-hidden="true"></div>',
				(v.ad_meta ? '<div class="avs-ad-side avs-ad-side-left">' + v.ad_meta + '</div>'
					+ '<div class="avs-ad-side avs-ad-side-right">' + v.ad_meta + '</div>' : ''),
				'<div class="avs-player-wrapper ' + vertClass + '"' + (v.aspect ? ' style="--avs-video-ar: ' + v.aspect + '"' : '') + '>',
				'  <video class="avs-video-el" src="' + escapeHtml(v.video_url) + '" poster="' + escapeHtml(v.poster_url) + '" playsinline webkit-playsinline loop preload="none" muted></video>',
				'  <div class="avs-play-pulse" aria-hidden="true">',
				'    <span class="material-symbols-rounded icon-play">play_arrow</span>',
				'    <span class="material-symbols-rounded icon-pause" style="display:none;">pause</span>',
				'  </div>',
				'  <div class="avs-heart-burst" aria-hidden="true"></div>',
				'  <button type="button" class="avs-unmute-overlay-badge ' + (!state.isMuted ? 'hidden' : '') + '" aria-label="Ativar áudio">',
				'    <span class="material-symbols-rounded" aria-hidden="true">volume_off</span>',
				'    <span>Toque para ativar o som</span>',
				'  </button>',
				'  <div class="avs-short-meta-bottom">',
				(v.ad_meta
					? '    <div class="avs-ad-meta-band"><span class="avs-ad-tag avs-ad-tag-sm">Anúncio</span>' + v.ad_meta + '</div>'
					: '    <div class="avs-short-title-wrap">'
						+ '      <h2 class="avs-short-title">' + escapeHtml(v.title) + '</h2>'
						+ (v.description ? '      <p class="avs-short-desc">' + escapeHtml(v.description) + '</p>' : '')
						+ '    </div>'),
				'  </div>',
				'  <aside class="avs-short-actions" aria-label="Ações do vídeo">',
				'    <div class="avs-action-item avs-action-profile">',
				'      <a href="' + escapeHtml(v.creator.channel_url) + '" class="avs-action-avatar-link" title="' + escapeHtml(v.creator.username) + '">',
				'        <img src="' + escapeHtml(v.creator.avatar_url) + '" alt="' + escapeHtml(v.creator.username) + '" class="avs-action-avatar-img">',
				'      </a>',
				'      <button type="button" class="avs-action-plus-btn ' + followActive + '" data-uid="' + v.creator.uid + '" title="Seguir criador">',
				'        <span class="material-symbols-rounded" aria-hidden="true">add</span>',
				'      </button>',
				'    </div>',
				'    <div class="avs-action-item">',
				'      <button type="button" class="avs-action-btn avs-btn-like ' + likeActive + '" data-vid="' + v.vid + '" title="Curtir vídeo (L)">',
				'        <span class="material-symbols-rounded" aria-hidden="true">favorite</span>',
				'      </button>',
				'      <span class="avs-action-label avs-like-count" data-count="' + v.likes + '">' + escapeHtml(v.likes_formatted) + '</span>',
				'    </div>',
				'    <div class="avs-action-item">',
				'      <button type="button" class="avs-action-btn avs-btn-comments" data-vid="' + v.vid + '" title="Ver comentários (C)">',
				'        <span class="material-symbols-rounded" aria-hidden="true">chat_bubble</span>',
				'      </button>',
				'      <span class="avs-action-label avs-comment-count">' + escapeHtml(v.comments_formatted) + '</span>',
				'    </div>',
				'    <div class="avs-action-item">',
				'      <button type="button" class="avs-action-btn avs-btn-fav ' + favActive + '" data-vid="' + v.vid + '" title="Salvar nos favoritos">',
				'        <span class="material-symbols-rounded" aria-hidden="true">bookmark</span>',
				'      </button>',
				'      <span class="avs-action-label">Salvar</span>',
				'    </div>',
				'    <div class="avs-action-item">',
				'      <button type="button" class="avs-action-btn avs-btn-share" data-vid="' + v.vid + '" data-url="' + escapeHtml(v.share_url) + '" data-title="' + escapeHtml(v.title) + '" title="Compartilhar">',
				'        <span class="material-symbols-rounded" aria-hidden="true">share</span>',
				'      </button>',
				'      <span class="avs-action-label">Enviar</span>',
				'    </div>',
				'    <div class="avs-action-item avs-action-audio">',
				'      <button type="button" class="avs-action-btn avs-btn-sound" title="Alternar som">',
				'        <span class="material-symbols-rounded icon-sound-off" style="' + (!state.isMuted ? 'display:none;' : '') + '">volume_off</span>',
				'        <span class="material-symbols-rounded icon-sound-on" style="' + (state.isMuted ? 'display:none;' : '') + '">volume_up</span>',
				'      </button>',
				'    </div>',
				'  </aside>',
				'  <div class="avs-short-progress-bar" role="progressbar">',
				'    <div class="avs-short-progress-fill"></div>',
				'  </div>',
				'</div>',
				'<div class="avs-desktop-nav" aria-hidden="true">',
				'  <button type="button" class="avs-nav-btn avs-nav-prev" title="Vídeo anterior"><span class="material-symbols-rounded">expand_less</span></button>',
				'  <button type="button" class="avs-nav-btn avs-nav-next" title="Próximo vídeo"><span class="material-symbols-rounded">expand_more</span></button>',
				'</div>'
			].join('');

			var loader = document.getElementById('avs-shorts-infinite-loader');
			if (loader) {
				stream.insertBefore(card, loader);
			} else {
				stream.appendChild(card);
			}
		});

		bindCards();
	}

	function escapeHtml(str) {
		if (!str) return '';
		return String(str).replace(/[&<>"']/g, function (m) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			}[m];
		});
	}

	// --- Gaveta de Comentários ---
	var drawer = document.getElementById('avs-comments-drawer');
	var drawerBackdrop = document.getElementById('avs-drawer-backdrop');
	var drawerCloseBtn = document.getElementById('avs-drawer-close-btn');
	var commentsContainer = document.getElementById('avs-comments-container');
	var commentsLoading = document.getElementById('avs-comments-loading');
	var commentsEmpty = document.getElementById('avs-comments-empty');
	var drawerCount = document.getElementById('avs-drawer-count');
	var commentForm = document.getElementById('avs-comment-form');
	var commentInput = document.getElementById('avs-comment-input');

	function toggleComments(vid) {
		if (drawer.classList.contains('open')) {
			closeComments();
		} else {
			openComments(vid);
		}
	}

	function openComments(vid) {
		state.activeCommentsVid = vid;
		drawer.classList.add('open');
		drawer.setAttribute('aria-hidden', 'false');
		loadComments(vid);
	}

	function closeComments() {
		if (!drawer) return;
		drawer.classList.remove('open');
		drawer.setAttribute('aria-hidden', 'true');
	}

	if (drawerBackdrop) drawerBackdrop.addEventListener('click', closeComments);
	if (drawerCloseBtn) drawerCloseBtn.addEventListener('click', closeComments);

	function loadComments(vid) {
		if (!commentsContainer) return;
		commentsContainer.innerHTML = '';
		if (commentsLoading) commentsLoading.style.display = 'flex';
		if (commentsEmpty) commentsEmpty.style.display = 'none';

		$.ajax({
			url: baseUrl + '/ajax/load_comments',
			type: 'POST',
			dataType: 'json',
			data: {
				type: 'video',
				id: vid,
				page: 1,
				order: 'recent'
			},
			success: function (res) {
				if (commentsLoading) commentsLoading.style.display = 'none';
				if (res && res.code) {
					// O backend retorna HTML pronto dos itens
					var temp = document.createElement('div');
					temp.innerHTML = res.code;
					// Extrair comment-items
					var items = temp.querySelectorAll('.comment-item');
					if (items.length > 0) {
						if (drawerCount) drawerCount.textContent = '(' + items.length + ')';
						items.forEach(function (it) {
							var userImg = it.querySelector('.comment-user img');
							var userName = it.querySelector('.comment-user-info a');
							var userTime = it.querySelector('.comment-add-time');
							var body = it.querySelector('.comment-body');
							
							var imgSrc = userImg ? userImg.getAttribute('src') : baseUrl + '/media/users/nopic-m.gif';
							var author = userName ? userName.textContent.trim() : 'Usuário';
							var timeStr = userTime ? userTime.textContent.trim() : '';
							
							// Remover nós de actions e cabeçalho para pegar apenas o texto
							var clone = body ? body.cloneNode(true) : it.cloneNode(true);
							var removeTargets = clone.querySelectorAll('.comment-actions, .comment-user-info, .comment-actions-bar');
							removeTargets.forEach(function (rt) { rt.remove(); });
							var text = clone.textContent.trim();

							var bubble = document.createElement('div');
							bubble.className = 'avs-comment-bubble';
							bubble.innerHTML = [
								'<img src="' + escapeHtml(imgSrc) + '" class="avs-comment-bubble-avatar" alt="">',
								'<div class="avs-comment-bubble-content">',
								'  <a href="' + baseUrl + '/user/' + encodeURIComponent(author) + '" class="avs-comment-bubble-author">@' + escapeHtml(author) + '</a>',
								'  <p class="avs-comment-bubble-text">' + escapeHtml(text) + '</p>',
								(timeStr ? '  <span class="avs-comment-bubble-time">' + escapeHtml(timeStr) + '</span>' : ''),
								'</div>'
							].join('');
							commentsContainer.appendChild(bubble);
						});
					} else {
						if (commentsEmpty) commentsEmpty.style.display = 'flex';
						if (drawerCount) drawerCount.textContent = '(0)';
					}
				} else {
					if (commentsEmpty) commentsEmpty.style.display = 'flex';
					if (drawerCount) drawerCount.textContent = '(0)';
				}
			},
			error: function () {
				if (commentsLoading) commentsLoading.style.display = 'none';
				if (commentsEmpty) commentsEmpty.style.display = 'flex';
			}
		});
	}

	// Enviar Comentário
	if (commentForm) {
		commentForm.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!commentInput) return;
			var text = commentInput.value.trim();
			if (!text || !state.activeCommentsVid) return;

			var submitBtn = document.getElementById('avs-comment-submit-btn');
			if (submitBtn) submitBtn.disabled = true;

			$.ajax({
				url: baseUrl + '/ajax/post_comment',
				type: 'POST',
				dataType: 'json',
				data: {
					type: 'video',
					id: state.activeCommentsVid,
					comment: text
				},
				success: function (res) {
					if (submitBtn) submitBtn.disabled = false;
					if (res && res.status === 1) {
						commentInput.value = '';
						if (commentsEmpty) commentsEmpty.style.display = 'none';

						// Adicionar comentário no topo imediatamente
						var bubble = document.createElement('div');
						bubble.className = 'avs-comment-bubble';
						bubble.innerHTML = [
							'<div class="avs-comment-bubble-content">',
							'  <span class="avs-comment-bubble-author" style="color:var(--shorts-accent);">Você</span>',
							'  <p class="avs-comment-bubble-text">' + escapeHtml(text) + '</p>',
							'  <span class="avs-comment-bubble-time">Agora</span>',
							'</div>'
						].join('');
						commentsContainer.insertBefore(bubble, commentsContainer.firstChild);

						// Atualizar contagem no card
						var card = document.getElementById('short-' + state.activeCommentsVid);
						if (card) {
							var countLabel = card.querySelector('.avs-comment-count');
							if (countLabel) {
								var c = parseInt(countLabel.textContent.replace(/[^\d]/g, '') || '0', 10) + 1;
								countLabel.textContent = formatCount(c);
							}
						}
						showToast('Comentário publicado!');
					} else {
						showToast(res.msg || 'Erro ao publicar comentário');
					}
				},
				error: function () {
					if (submitBtn) submitBtn.disabled = false;
					showToast('Erro de conexão ao enviar comentário');
				}
			});
		});
	}

	// Inicialização
	syncSoundUi();
	bindCards();

})();
