/* AVSCMS - Mini player do mediabunny nas páginas que NÃO têm o player.

   A janelinha NÃO é mais montada por JS: o HTML dela vem pronto do footer.tpl
   (server-side), que só o renderiza quando existe o cookie 'avs_mini' — gravado
   pelo avs-player.js enquanto há vídeo tocando. Este arquivo só HIDRATA esse
   shell: acha o elemento pronto, liga os controles e aponta para o vídeo salvo.

   Por que assim: montar a janela por JS a cada navegação custava criar ~45 nós,
   injetar avs-player.css depois do parse (o browser só descobria o CSS no fim) e
   pintar o shell sem estilo por um instante — era o "parece que recarrega" a
   cada página. Com o markup no template o CSS é descoberto junto com o HTML e a
   janela aparece já estilizada, sem reconstrução.

   O estado em si (fonte, tempo, qualidades, capa) continua no sessionStorage,
   que é por aba, escrito por avs-player.js. O shell chega com
   style="display:none" e só aparece no fim da hidratação; se o estado não
   servir, ele é removido da página — nunca sobra um quadrado vazio na tela.

   IMPORTANTE: esta janela é o MESMO UI do mini da página do vídeo
   (.avs-player.avs-mini-mode, de avs-player.css) — mesmas classes, mesma barra
   de controles, mesmo menu de configurações e mesmos ícones. O que muda é só a
   engine por baixo: aqui é um <video> nativo, que é justamente o caminho de
   fallback que o próprio player usa quando o mediabunny não está disponível (ver
   useNativeFallback em avs-player.js). A página do próprio vídeo usa o mini
   interno e não renderiza este shell. */
(function () {
    'use strict';

    var shell = document.getElementById('avs-mini-floating');
    if (!shell) return; // sem cookie avs_mini o footer.tpl não renderiza nada

    var STATE_KEY = 'avs_mini_state';
    var AUTO_KEY = 'avs_mini_auto';
    var COOKIE_KEY = 'avs_mini';
    var MAX_AGE = 120000; // estado com mais de 2 min não ressuscita
    var RATES = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2];
    var IDLE_MS = 3000;

    function dropShell() {
        if (shell.parentNode) shell.parentNode.removeChild(shell);
    }

    function readState() {
        try {
            var raw = sessionStorage.getItem(STATE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function clearStorage() {
        try { sessionStorage.removeItem(STATE_KEY); } catch (e) { /* noop */ }
    }

    function clearCookie() {
        try { document.cookie = COOKIE_KEY + '=; path=/; max-age=0; SameSite=Lax'; } catch (e) { /* noop */ }
    }

    function autoPref() {
        try {
            var v = localStorage.getItem(AUTO_KEY);
            return v === null ? true : v === '1';
        } catch (e) {
            return true;
        }
    }

    // Página do vídeo (usa o mini interno do player) ou outro player em uso: o
    // footer.tpl renderiza o shell só pelo cookie, sem saber em que página está,
    // então aqui é o único lugar que pode decidir que ele não serve. Não mexe no
    // estado — nesta página ele é o que retoma a reprodução no ponto salvo.
    if (document.getElementById('avs-player') || document.querySelector('.video-js')) {
        dropShell();
        return;
    }

    var state = readState();
    if (!state || !state.source || !state.href) {
        clearStorage();
        clearCookie();
        dropShell();
        return;
    }
    if (Date.now() - (state.ts || 0) > MAX_AGE) {
        clearStorage();
        clearCookie();
        dropShell();
        return;
    }
    if (!autoPref()) { // usuário desligou o mini automático: não ressuscita
        clearStorage();
        clearCookie();
        dropShell();
        return;
    }

    // Qualidades salvas pela página do vídeo (URLs do proxy gcs_video.php, então
    // não expiram) + a capa, para reproduzir o mesmo player aqui.
    var qualitySources = (state.sources && state.sources.length)
        ? state.sources.slice()
        : [{ src: state.source, label: 'Original', res: 0 }];
    var autoSource = state.source;
    var closed = false;
    var idleTimer = 0;
    var persistTimer = 0;
    var centerTimer = 0;

    var video = shell.querySelector('video');
    var seek = shell.querySelector('.avs-seek');
    var seekFill = shell.querySelector('.avs-seek-fill');
    var seekBuffer = shell.querySelector('.avs-seek-buffer');
    var curEl = shell.querySelector('.avs-current');
    var durEl = shell.querySelector('.avs-duration');
    var playBtn = shell.querySelector('[data-action="play"]');
    var volBtn = shell.querySelector('[data-action="volume"]');
    var volSlider = shell.querySelector('.avs-volume-slider');
    var fsBtn = shell.querySelector('[data-action="fullscreen"]');
    var setBtn = shell.querySelector('[data-action="settings"]');
    var pipBtn = shell.querySelector('[data-action="mini"]');
    var panel = shell.querySelector('.avs-settings');
    var centerToggle = shell.querySelector('.avs-center-toggle');
    var centerRw = shell.querySelector('.avs-center-rw');
    var centerFw = shell.querySelector('.avs-center-fw');
    var expandBtn = shell.querySelector('.avs-mini-expand');
    var closeBtn = shell.querySelector('.avs-mini-close');
    var errorBox = shell.querySelector('.avs-error');

    video.poster = state.poster || '';

    function setIcon(el, id) {
        if (!el) return;
        var use = el.querySelector('use');
        if (use) use.setAttribute('href', '#' + id);
    }

    function fmt(s) {
        s = Math.max(0, Math.floor(s || 0));
        var m = Math.floor(s / 60);
        var sec = s % 60;
        return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    function persist() {
        if (closed || !state || video.ended) return;
        try {
            sessionStorage.setItem(STATE_KEY, JSON.stringify({
                vid: state.vid,
                source: video.currentSrc || video.src || state.source,
                time: Math.round((video.currentTime || 0) * 10) / 10,
                href: state.href,
                poster: state.poster || '',
                sources: qualitySources,
                ts: Date.now()
            }));
        } catch (e) { /* storage indisponível */ }
    }

    function showError(msg) {
        if (!errorBox) return;
        errorBox.textContent = msg;
        errorBox.style.display = '';
    }

    function armIdle() {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(function () {
            if (!video.paused && !video.ended && !shell.classList.contains('avs-settings-open')) {
                shell.classList.add('avs-idle');
            }
        }, IDLE_MS);
    }

    function cancelIdle() {
        clearTimeout(idleTimer);
        shell.classList.remove('avs-idle');
    }

    function showCenter() {
        shell.classList.add('avs-center-visible');
        clearTimeout(centerTimer);
    }

    function hideCenterSoon() {
        clearTimeout(centerTimer);
        centerTimer = setTimeout(function () {
            if (!video.paused) shell.classList.remove('avs-center-visible');
        }, 1200);
    }

    function syncTimes() {
        var d = video.duration || 0;
        curEl.textContent = fmt(video.currentTime || 0);
        durEl.textContent = fmt(d);
        var pct = d ? (video.currentTime || 0) / d : 0;
        seekFill.style.width = (pct * 100) + '%';
        seek.setAttribute('aria-valuenow', String(Math.round(pct * 100)));
        if (seekBuffer && video.buffered && video.buffered.length && d) {
            try {
                var end = video.buffered.end(video.buffered.length - 1);
                seekBuffer.style.width = Math.max(0, Math.min(100, (end / d) * 100)) + '%';
            } catch (e) { /* range em transição */ }
        }
    }

    function syncVolume() {
        var v = video.muted ? 0 : (video.volume || 0);
        volSlider.value = String(Math.round(v * 100));
        setIcon(volBtn, v === 0 ? 'avs-i-vol-mute' : (v < 0.5 ? 'avs-i-vol-low' : 'avs-i-vol-high'));
    }

    function syncPlayIcon() {
        var isPlaying = !video.paused && !video.ended;
        setIcon(playBtn, isPlaying ? 'avs-i-pause' : 'avs-i-play');
        setIcon(centerToggle, isPlaying ? 'avs-i-pause' : 'avs-i-play');
        if (isPlaying) {
            hideCenterSoon();
            armIdle();
        } else {
            showCenter();
            cancelIdle();
        }
    }

    function attemptPlay() {
        video.play().catch(function () {
            // Autoplay com som bloqueado: cai para mudo, como faz a prévia.
            video.muted = true;
            syncVolume();
            return video.play();
        }).catch(function () { /* segue pausado; usuário dá play */ });
    }

    function togglePlay() {
        if (video.paused || video.ended) attemptPlay();
        else video.pause();
    }

    function jump(delta) {
        var d = video.duration || 0;
        video.currentTime = Math.max(0, Math.min(d || 0, (video.currentTime || 0) + delta));
        syncTimes();
    }

    // --- Menu de configurações (mesma estrutura do player) ----------------
    function buildItem(label, value, onClick, sub) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'avs-settings-item';
        btn.dataset.value = String(value);

        var text = document.createElement('span');
        text.className = 'avs-settings-item-text';
        text.textContent = label;
        if (sub) {
            var subEl = document.createElement('span');
            subEl.className = 'avs-settings-item-sub';
            subEl.textContent = sub;
            text.appendChild(subEl);
        }
        btn.appendChild(text);

        var check = document.createElement('span');
        check.className = 'material-symbols-rounded avs-settings-check';
        check.setAttribute('aria-hidden', 'true');
        check.textContent = 'check';
        btn.appendChild(check);

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            onClick();
        });
        return btn;
    }

    function mark(group, match) {
        var items = panel.querySelectorAll('[data-settings-group="' + group + '"] .avs-settings-item');
        Array.prototype.forEach.call(items, function (el) {
            el.classList.toggle('avs-settings-active', match(el.dataset.value));
        });
    }

    function qualityName(s, i) {
        return s.res ? s.res + 'p' : (s.label || 'Qualidade ' + (i + 1));
    }

    function setSource(src, keepTime) {
        var t = keepTime ? (video.currentTime || 0) : 0;
        var wasPlaying = !video.paused && !video.ended;
        state.source = src;
        video.src = src;
        video.addEventListener('loadedmetadata', function () {
            try { video.currentTime = Math.min(t, video.duration || 0); } catch (e) { /* noop */ }
            syncTimes();
            if (wasPlaying) attemptPlay();
        }, { once: true });
        video.load();
        persist();
    }

    function buildSettings() {
        var qualityPane = panel.querySelector('[data-settings-group="quality"]');
        var speedPane = panel.querySelector('[data-settings-group="speed"]');
        var playbackPane = panel.querySelector('[data-settings-group="playback"]');

        qualityPane.appendChild(buildItem('Automática', 'auto', function () {
            setSource(autoSource, true);
            mark('quality', function (v) { return v === 'auto'; });
        }, 'Melhor disponível'));
        qualitySources.forEach(function (s, i) {
            qualityPane.appendChild(buildItem(qualityName(s, i), i, function () {
                setSource(s.src, true);
                mark('quality', function (v) { return v === String(i); });
            }));
        });
        mark('quality', function (v) { return v === 'auto'; });

        RATES.forEach(function (rate) {
            speedPane.appendChild(buildItem(rate + 'x', rate, function () {
                video.playbackRate = rate;
                mark('speed', function (v) { return parseFloat(v) === rate; });
            }));
        });
        mark('speed', function (v) { return parseFloat(v) === 1; });

        playbackPane.appendChild(buildItem('Mini player automático', '1', function () {
            var on = !autoPref();
            try { localStorage.setItem(AUTO_KEY, on ? '1' : '0'); } catch (e) { /* noop */ }
            if (!on) closeMini(); // desligar = este mini sai de cena
            else mark('playback', function () { return true; });
        }, 'Ao sair da página do vídeo'));
        mark('playback', function () { return true; });

        var tabs = panel.querySelectorAll('.avs-settings-tab');
        var panes = panel.querySelectorAll('.avs-settings-pane');
        Array.prototype.forEach.call(tabs, function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                Array.prototype.forEach.call(tabs, function (other) {
                    var on = other.dataset.settingsTab === t.dataset.settingsTab;
                    other.classList.toggle('avs-settings-tab-active', on);
                    other.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                Array.prototype.forEach.call(panes, function (p) {
                    p.classList.toggle('avs-settings-pane-active', p.dataset.settingsGroup === t.dataset.settingsTab);
                });
            });
        });
    }

    function toggleSettings() {
        // O CSS abre o painel por `.avs-player.avs-settings-open`, então a classe
        // vai no shell (mesma mecânica do player).
        var open = !shell.classList.contains('avs-settings-open');
        shell.classList.toggle('avs-settings-open', open);
        if (open) cancelIdle();
        else armIdle();
    }

    function closeMini() {
        closed = true;
        try { video.pause(); } catch (e) { /* noop */ }
        clearStorage();
        clearCookie();
        dropShell();
    }

    function goToPlayer() {
        persist();
        location.href = state.href;
    }

    // --- fiação -----------------------------------------------------------
    buildSettings();

    video.src = state.source;
    video.addEventListener('loadedmetadata', function () {
        // Vídeo vertical: mesma regra do player (.avs-vertical + --avs-ratio).
        if (video.videoHeight > video.videoWidth) {
            shell.classList.add('avs-vertical');
            shell.style.setProperty('--avs-ratio', String(video.videoWidth / video.videoHeight));
        }
        try {
            video.currentTime = Math.min(state.time || 0, video.duration || 0);
        } catch (e) { /* keep 0 */ }
        syncTimes();
        syncVolume();
        attemptPlay();
    }, { once: true });

    video.addEventListener('error', function () {
        showError('Não foi possível carregar o vídeo. Tente outra qualidade ou recarregue a página.');
    });

    video.addEventListener('play', function () {
        syncPlayIcon();
        persist();
    });
    video.addEventListener('playing', function () {
        if (errorBox) errorBox.style.display = 'none';
        syncPlayIcon();
    });
    video.addEventListener('pause', function () {
        // Pausa do usuário = não ressuscitar nas próximas páginas (mas o estado
        // em memória continua, então voltar a tocar volta a gravar).
        if (!video.ended) clearStorage();
        syncPlayIcon();
    });
    video.addEventListener('ended', function () {
        clearStorage();
        syncPlayIcon();
        showCenter();
    });
    video.addEventListener('timeupdate', function () {
        syncTimes();
        clearTimeout(persistTimer);
        persistTimer = setTimeout(persist, 1000);
    });
    video.addEventListener('progress', syncTimes);
    video.addEventListener('volumechange', syncVolume);
    video.addEventListener('ratechange', function () {
        mark('speed', function (v) { return parseFloat(v) === video.playbackRate; });
    });

    // Clique na área do vídeo alterna play/pause (controles ficam de fora).
    shell.addEventListener('click', function (e) {
        if (e.target.closest('.avs-controls, .avs-settings, .avs-center, .avs-mini-actions, .avs-error')) return;
        togglePlay();
    });

    // Idle: esconde a barra enquanto toca, como no player.
    shell.addEventListener('mousemove', function () {
        if (video.paused) return;
        cancelIdle();
        armIdle();
    });
    shell.addEventListener('touchstart', function () {
        if (video.paused) return;
        cancelIdle();
        armIdle();
    }, { passive: true });

    playBtn.addEventListener('click', function (e) { e.stopPropagation(); togglePlay(); });
    centerToggle.addEventListener('click', function (e) { e.stopPropagation(); togglePlay(); });
    centerRw.addEventListener('click', function (e) { e.stopPropagation(); jump(-10); });
    centerFw.addEventListener('click', function (e) { e.stopPropagation(); jump(10); });

    volSlider.addEventListener('input', function () {
        var val = Math.max(0, Math.min(1, parseFloat(volSlider.value) / 100));
        video.muted = val === 0;
        video.volume = val;
        syncVolume();
    });
    volBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        video.muted = !video.muted;
        syncVolume();
    });

    setBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleSettings(); });

    fsBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (document.fullscreenElement) {
            if (document.exitFullscreen) document.exitFullscreen();
        } else if (shell.requestFullscreen) {
            shell.requestFullscreen().catch(function () { /* negado */ });
        }
    });
    document.addEventListener('fullscreenchange', function () {
        setIcon(fsBtn, document.fullscreenElement ? 'avs-i-fs-exit' : 'avs-i-fs-enter');
    });

    // Aqui não existe "player grande" na página: o botão de pip e o de expandir
    // levam de volta para a página do vídeo (é o mesmo gesto do player interno,
    // que sai do mini e volta ao player).
    pipBtn.addEventListener('click', function (e) { e.stopPropagation(); goToPlayer(); });
    expandBtn.addEventListener('click', function (e) { e.stopPropagation(); goToPlayer(); });
    closeBtn.addEventListener('click', function (e) { e.stopPropagation(); closeMini(); });

    // Barra de progresso: clique/arraste.
    function seekToClientX(clientX) {
        var rect = seek.getBoundingClientRect();
        if (!rect.width) return;
        var ratio = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
        var d = video.duration || 0;
        if (!d) return;
        video.currentTime = ratio * d;
        syncTimes();
    }
    var seeking = false;
    seek.addEventListener('pointerdown', function (e) {
        seeking = true;
        seekToClientX(e.clientX);
        try { seek.setPointerCapture(e.pointerId); } catch (err) { /* noop */ }
    });
    seek.addEventListener('pointermove', function (e) {
        if (seeking) seekToClientX(e.clientX);
    });
    seek.addEventListener('pointerup', function () { seeking = false; });
    seek.addEventListener('pointercancel', function () { seeking = false; });

    // Ao trocar de página a partir do mini, a última posição já foi gravada pelo
    // timeupdate throttled; pagehide dá o último empurrão garantido.
    window.addEventListener('pagehide', persist);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) persist();
    });

    // Hidratação concluída (fonte, poster e controles no lugar): só agora a
    // janelinha aparece. O shell vem do template com display:none justamente
    // para não pintar um quadrado vazio/vazio-de-estilo antes disto.
    shell.style.display = '';
})();
