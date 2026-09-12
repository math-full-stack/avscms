/* AVSCMS - Mini player do mediabunny (janela flutuante entre páginas).
   Carregado no footer global. Se o usuário estava reproduzindo um vídeo do
   mediabunny e saiu da página (sessionStorage 'avs_mini_state' gravado por
   avs-player.js), recria o vídeo numa janelinha fixa continuando de onde
   parou. A página do próprio vídeo (#avs-player presente) usa o mini interno
   (.avs-mini-mode) e não depende deste arquivo. */
(function () {
    'use strict';

    if (document.getElementById('avs-player')) return; // página do vídeo = mini interno
    if (document.querySelector('.video-js')) return;   // outro player já em uso

    var STATE_KEY = 'avs_mini_state';
    var MAX_AGE = 120000; // estado com mais de 2 min não ressuscita
    var persistTimer = 0;
    var state = read();
    var video = null;

    function read() {
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

    if (!state || !state.source || !state.href) return;
    if (Date.now() - (state.ts || 0) > MAX_AGE) {
        clearStorage();
        return;
    }

    // --- Monta a janelinha flutuante ------------------------------------
    var shell = document.createElement('div');
    shell.id = 'avs-mini-floating';
    shell.innerHTML =
        '<div class="avs-mini-stage">' +
            '<video playsinline preload="auto"></video>' +
        '</div>' +
        // Expandir/fechar no canto superior direito, igual ao .avs-mini-actions
        // do player (avs-player.css) — mesma posição, tamanho e fundo.
        '<div class="avs-mini-actions">' +
            '<a class="avs-mini-btn avs-mini-expand" title="Voltar ao player grande">' +
                '<svg class="avs-mini-icon" viewBox="0 0 24 24"><path fill="currentColor" d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>' +
            '</a>' +
            '<button type="button" class="avs-mini-btn avs-mini-close" title="Fechar">' +
                '<svg class="avs-mini-icon" viewBox="0 0 24 24"><path fill="currentColor" d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13l-6.3 6.3-1.4-1.4L9.8 12 3.5 5.7l1.4-1.4 6.3 6.3 6.3-6.3z"/></svg>' +
            '</button>' +
        '</div>' +
        '<div class="avs-mini-bar">' +
            '<button type="button" class="avs-mini-btn avs-mini-play" title="Reproduzir/Pausar">' +
                '<svg class="avs-mini-icon avs-mini-icon-play" viewBox="0 0 24 24"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>' +
                '<svg class="avs-mini-icon avs-mini-icon-pause" viewBox="0 0 24 24"><path fill="currentColor" d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>' +
            '</button>' +
            '<button type="button" class="avs-mini-btn avs-mini-mute" title="Mudo/Habilitar som">' +
                '<svg class="avs-mini-icon avs-mini-icon-vol" viewBox="0 0 24 24"><path fill="currentColor" d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05A4.5 4.5 0 0 0 16.5 12zM14 3.23v2.06A7 7 0 0 1 14 18.7v2.06A9 9 0 0 0 14 3.23z"/></svg>' +
                '<svg class="avs-mini-icon avs-mini-icon-mute" viewBox="0 0 24 24"><path fill="currentColor" d="M16.5 12A4.5 4.5 0 0 0 14 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.9 8.9 0 0 0 21 12c0-3.86-2.44-7.1-5.8-8.4v2.06A7 7 0 0 1 19 12zM4.27 3 3 4.27 9.73 11H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06a8.99 8.99 0 0 0 3.69-1.81L19.73 21 21 19.73 4.27 3zM12 4 9.91 6.09 12 8.18V4z"/></svg>' +
            '</button>' +
            '<input type="range" class="avs-mini-seek" min="0" max="0" step="0.1" value="0" aria-label="Posição">' +
            '<span class="avs-mini-time">0:00 / 0:00</span>' +
        '</div>';

    document.body.appendChild(shell);

    var stage = shell.querySelector('.avs-mini-stage');
    var bar = shell.querySelector('.avs-mini-bar');
    video = shell.querySelector('video');
    var playBtn = shell.querySelector('.avs-mini-play');
    var muteBtn = shell.querySelector('.avs-mini-mute');
    var seek = shell.querySelector('.avs-mini-seek');
    var timeEl = shell.querySelector('.avs-mini-time');
    var expand = shell.querySelector('.avs-mini-expand');
    var closeBtn = shell.querySelector('.avs-mini-close');

    expand.href = state.href; // via DOM evita problemas de escape no href

    function fmt(s) {
        s = Math.max(0, Math.floor(s || 0));
        var m = Math.floor(s / 60);
        var sec = s % 60;
        return m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    function persist() {
        if (!state || !video) return;
        try {
            sessionStorage.setItem(STATE_KEY, JSON.stringify({
                vid: state.vid,
                source: video.currentSrc || video.src || state.source,
                time: Math.round((video.currentTime || 0) * 10) / 10,
                href: state.href,
                ts: Date.now()
            }));
        } catch (e) { /* noop */ }
    }

    function sync() {
        var d = video.duration || 0;
        timeEl.textContent = fmt(video.currentTime || 0) + ' / ' + fmt(d);
        if (d) seek.value = String(video.currentTime || 0);
        playBtn.classList.toggle('is-playing', !video.paused);
        playBtn.classList.toggle('is-paused', video.paused);
        muteBtn.classList.toggle('is-muted', video.muted);
    }

    function attemptPlay() {
        video.play().then(sync).catch(function () {
            // Autoplay com som bloqueado no navegador: tenta mudo uma vez.
            video.muted = true;
            sync();
            return video.play();
        }).then(sync).catch(sync);
    }

    video.src = state.source;
    video.addEventListener('loadedmetadata', function () {
        seek.max = String(video.duration || 0);
        try {
            video.currentTime = Math.min(state.time || 0, video.duration || 0);
        } catch (e) { /* keep 0 */ }
        attemptPlay();
        sync();
    }, { once: true });

    video.addEventListener('timeupdate', function () {
        sync();
        clearTimeout(persistTimer);
        persistTimer = setTimeout(persist, 1000);
    });
    video.addEventListener('play', sync);
    video.addEventListener('pause', function () {
        // Pausa do usuário = não ressuscitar nas próximas páginas, mas mantém
        // o estado em memória para re-persistir se o usuário voltar a tocar.
        if (!video.ended) clearStorage();
        sync();
    });
    video.addEventListener('ended', function () {
        state = null;
        clearStorage();
        sync();
    });

    stage.addEventListener('click', function () {
        if (video.paused) attemptPlay();
        else video.pause();
    });

    playBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (video.paused) attemptPlay();
        else video.pause();
    });

    muteBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        video.muted = !video.muted;
        sync();
    });

    seek.addEventListener('input', function () {
        var d = video.duration || 0;
        if (!d) return;
        video.currentTime = Math.min(parseFloat(seek.value) || 0, d);
        sync();
    });

    expand.addEventListener('click', function (e) {
        persist(); // grava a posição exata antes de voltar ao player grande
    });

    closeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        video.pause();
        state = null;
        clearStorage();
        shell.remove();
    });

    // Ao trocar de página a partir do mini, a última posição já foi gravada
    // pelo timeupdate throttled; pagehide dá o último empurrão garantido.
    window.addEventListener('pagehide', persist);
})();