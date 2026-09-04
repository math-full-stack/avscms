/**
 * AVS Media Bunny player.
 *
 * Replaces Video.js on pages where the player profile engine is 'mediabunny'.
 * It reuses the exact same `vid_files` (JSON sources) + `vitem` globals the
 * templates already emit, and plays the (short-lived signed) URLs with the
 * Media Bunny toolkit (UrlSource + CanvasSink + AudioBufferSink).
 *
 * Security note: on Media Bunny pages the PHP layer emits the raw signed URLs
 * (expiring, private bucket). The legacy AES `vitem` obfuscation is therefore
 * not needed — it is only decrypted as a fallback when CryptoJS is present.
 *
 * Fallback: browsers without WebCodecs get a plain <video> element with the
 * same source, so playback never hard-fails on old browsers.
 */
import {
    AudioBufferSink,
    CanvasSink,
    Input,
    UrlSource,
    ALL_FORMATS
} from 'https://cdn.jsdelivr.net/npm/mediabunny@1.55.6/dist/bundles/mediabunny.min.mjs';

(() => {
    'use strict';

    const player = document.getElementById('avs-player');

    // The templates declare vid_files with `let` in a classic <script>, so it
    // lives in the global *lexical* environment and is NOT reachable via
    // window.vid_files. Resolve it by name first, then fall back to window.
    const vidFiles = (typeof vid_files !== 'undefined')
        ? vid_files
        : (typeof window !== 'undefined' ? window.vid_files : null);

    if (!player || !vidFiles) {
        return;
    }

    // ------------------------------------------------------------------
    // 1. Parse sources from the existing vid_files JSON
    // ------------------------------------------------------------------
    // The templates build the JSON by string concatenation, so there are
    // trailing commas before ']' — clean them exactly like player-init did.
    let data = null;
    try {
        const json = String(vidFiles).replace(/,(?!\s*[{\["'\\w])/g, '');
        data = JSON.parse(json);
    } catch (e) {
        return showError('Falha ao interpretar a lista de fontes de vídeo.');
    }

    const sources = (data && Array.isArray(data.vid_files) ? data.vid_files : [])
        .map((s) => ({ src: s.src, type: s.type || 'video/mp4', label: s.label || '', res: parseInt(s.res, 10) || 0 }))
        .filter((s) => s.src);

    if (sources.length === 0) {
        return showError('Nenhuma fonte de vídeo disponível.');
    }

    // Optional legacy decryption fallback (CryptoJS present on videojs pages).
    // Mirrors PHP encryptPhp(): sha256(secret_key) as Utf8 key (AES uses the
    // first 32 bytes), sha256(secret_iv) first 16 chars as Utf8 IV, and the
    // ciphertext is double-base64 encoded by PHP.
    const decryptLegacy = (cipher, ivHex, keyHex) => {
        const key = CryptoJS.enc.Utf8.parse(keyHex);
        const iv = CryptoJS.enc.Utf8.parse(ivHex);
        const inner = CryptoJS.enc.Base64.parse(cipher).toString(CryptoJS.enc.Latin1);
        const decrypted = CryptoJS.AES.decrypt(inner, key, { iv: iv });
        return decrypted.toString(CryptoJS.enc.Utf8);
    };

    if (window.CryptoJS && window.vitem && String(window.vitem).indexOf('.') !== -1) {
        const parts = String(window.vitem).split('.');
        for (const s of sources) {
            if (!/^https?:\/\//i.test(s.src)) {
                try {
                    s.src = decryptLegacy(s.src, parts[1], parts[0]);
                } catch (e) { /* keep whatever we had */ }
            }
        }
    }

    // ------------------------------------------------------------------
    // 2. DOM wiring
    // ------------------------------------------------------------------
    const canvas = player.querySelector('canvas');
    const posterImg = player.querySelector('.avs-poster');
    const errorBox = player.querySelector('.avs-error');
    const playBtn = player.querySelector('[data-action="play"]');
    const muteBtn = player.querySelector('[data-action="volume"]');
    const fullBtn = player.querySelector('[data-action="fullscreen"]');
    const seekBar = player.querySelector('.avs-seek');
    const seekFill = player.querySelector('.avs-seek-fill');
    const currentEl = player.querySelector('.avs-current');
    const durationEl = player.querySelector('.avs-duration');
    const qualitySel = player.querySelector('.avs-quality');

    const context2d = canvas.getContext('2d');
    const autoplay = player.dataset.autoplay === '1';
    const poster = player.dataset.poster || '';
    if (poster) {
        posterImg.src = poster;
        posterImg.style.display = '';
    }

    const supportsWebCodecs = typeof window.VideoDecoder !== 'undefined';

    // Native <video> fallback — used when WebCodecs is missing OR when Media
    // Bunny cannot decode the file in this browser (e.g. Firefox has no H.264
    // in WebCodecs). Guarantees playback wherever the plain MP4 plays.
    let fallbackVideo = null;
    const useNativeFallback = (reason) => {
        if (fallbackVideo) return fallbackVideo;
        if (reason) console.warn('[AVS Mediabunny] fallback nativo:', reason);
        fallbackVideo = document.createElement('video');
        fallbackVideo.className = 'avs-fallback-video';
        fallbackVideo.controls = true;
        fallbackVideo.playsInline = true;
        if (poster) fallbackVideo.poster = poster;
        player.insertBefore(fallbackVideo, player.firstChild);
        posterImg.style.display = 'none';
        player.classList.add('avs-fallback');
        return fallbackVideo;
    };

    if (!supportsWebCodecs) {
        useNativeFallback('WebCodecs indisponível neste navegador');
    }

    // ------------------------------------------------------------------
    // 3. Media Bunny playback state (mirrors the official media-player example)
    // ------------------------------------------------------------------
    let audioContext = null;
    let gainNode = null;
    let videoSink = null;
    let audioSink = null;
    let videoFrameIterator = null;
    let audioBufferIterator = null;
    let nextFrame = null;
    let firstTimestamp = 0;
    let endTimestamp = 0;
    let playbackTimeAtStart = 0;
    let audioContextStartTime = null;
    let playing = false;
    let fileLoaded = false;
    let asyncId = 0;
    let volume = 0.8;
    let volumeMuted = false;
    const queuedAudioNodes = new Set();

    // ------------------------------------------------------------------
    // 4. Initialization
    // ------------------------------------------------------------------
    const pickSource = () => {
        if (sources.length === 1) {
            return sources[0];
        }
        // Honor the player_settings.tpl resolution preference when present
        const pref = typeof window.player_resolution !== 'undefined' ? window.player_resolution : 'high';
        const sorted = [...sources].sort((a, b) => a.res - b.res);
        return pref === 'low' ? sorted[0] : sorted[sorted.length - 1];
    };

    const initMediaPlayer = async (source) => {
        disposePlayback();
        asyncId++;
        fileLoaded = false;
        showError('');
        posterImg.style.display = poster ? '' : 'none';

        const input = new Input({
            source: new UrlSource(source.src),
            formats: ALL_FORMATS
        });

        let videoTrack = await input.getPrimaryVideoTrack();
        let audioTrack = await input.getPrimaryAudioTrack();
        const tracks = [videoTrack, audioTrack].filter((t) => t !== null);

        firstTimestamp = Math.max(await input.getFirstTimestamp(tracks), 0);
        endTimestamp = await input.getDurationFromMetadata(tracks, { skipLiveWait: true })
            ?? await input.computeDuration(tracks, { skipLiveWait: true });

        // Codec sanity checks
        let problem = '';
        if (videoTrack) {
            if (await videoTrack.getCodec() === null || !(await videoTrack.canDecode())) {
                videoTrack = null;
                problem += 'Codec de vídeo não suportado pelo navegador. ';
            }
        }
        if (audioTrack) {
            if (await audioTrack.getCodec() === null || !(await audioTrack.canDecode())) {
                audioTrack = null;
                problem += 'Codec de áudio não suportado pelo navegador. ';
            }
        }
        if (!videoTrack && !audioTrack) {
            throw new Error(problem || 'Nenhuma trilha de vídeo ou áudio encontrada.');
        }

        // Audio context (matching sample rate)
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        audioContext = new AudioCtx({ sampleRate: await audioTrack?.getSampleRate() });
        gainNode = audioContext.createGain();
        gainNode.connect(audioContext.destination);
        updateVolume();

        videoSink = videoTrack && new CanvasSink(videoTrack, { poolSize: 2, fit: 'contain' });
        audioSink = audioTrack && new AudioBufferSink(audioTrack);

        if (videoTrack) {
            canvas.width = await videoTrack.getDisplayWidth();
            canvas.height = await videoTrack.getDisplayHeight();
            canvas.style.display = '';
        } else {
            canvas.style.display = 'none';
        }

        fileLoaded = true;
        playbackTimeAtStart = firstTimestamp;
        await startVideoIterator();
        renderTime(playbackTimeAtStart);
        durationEl.textContent = formatSeconds(endTimestamp);

        if (autoplay) {
            void play();
        }
    };

    const startPlayer = async () => {
        try {
            await initMediaPlayer(pickSource());
        } catch (err) {
            console.error('[AVS Mediabunny]', err);
            // Se o Media Bunny não conseguir inicializar/decodificar neste
            // navegador, cai para o <video> nativo — o MP4 toca igual.
            showError('');
            disposePlayback();
            const fv = useNativeFallback(err && err.message ? err.message : String(err));
            const src = pickSource().src;
            if (src) {
                fv.src = src;
                fv.load();
            }
        }
    };

    // ------------------------------------------------------------------
    // 5. Video rendering loop
    // ------------------------------------------------------------------
    const startVideoIterator = async () => {
        if (!videoSink) return;
        asyncId++;
        if (videoFrameIterator) await videoFrameIterator.return();
        videoFrameIterator = videoSink.canvases(getPlaybackTime());
        const firstFrame = (await videoFrameIterator.next()).value ?? null;
        nextFrame = (await videoFrameIterator.next()).value ?? null;
        if (firstFrame) {
            context2d.clearRect(0, 0, canvas.width, canvas.height);
            context2d.drawImage(firstFrame.canvas, 0, 0);
            posterImg.style.display = 'none';
        }
    };

    const render = (requestFrame = true) => {
        if (fileLoaded) {
            const t = getPlaybackTime();
            if (t >= endTimestamp) {
                pause();
                playbackTimeAtStart = endTimestamp;
            }
            if (nextFrame && nextFrame.timestamp <= t) {
                context2d.clearRect(0, 0, canvas.width, canvas.height);
                context2d.drawImage(nextFrame.canvas, 0, 0);
                nextFrame = null;
                void updateNextFrame();
            }
            renderTime(t);
        }
        if (requestFrame) {
            requestAnimationFrame(() => render());
        }
    };
    render();
    setInterval(() => render(false), 500);

    const updateNextFrame = async () => {
        const id = asyncId;
        while (true) {
            const frame = (await videoFrameIterator.next()).value ?? null;
            if (!frame || id !== asyncId) break;
            if (frame.timestamp <= getPlaybackTime()) {
                context2d.clearRect(0, 0, canvas.width, canvas.height);
                context2d.drawImage(frame.canvas, 0, 0);
            } else {
                nextFrame = frame;
                break;
            }
        }
    };

    // ------------------------------------------------------------------
    // 6. Audio loop
    // ------------------------------------------------------------------
    const runAudioIterator = async () => {
        if (!audioSink) return;
        for await (const { buffer, timestamp } of audioBufferIterator) {
            const node = audioContext.createBufferSource();
            node.buffer = buffer;
            node.connect(gainNode);
            let start = audioContextStartTime + timestamp - playbackTimeAtStart;
            start = Math.round(audioContext.sampleRate * start) / audioContext.sampleRate;
            if (start >= audioContext.currentTime) {
                node.start(start);
            } else {
                node.start(audioContext.currentTime, audioContext.currentTime - start);
            }
            queuedAudioNodes.add(node);
            node.onended = () => queuedAudioNodes.delete(node);
            if (timestamp - getPlaybackTime() >= 1) {
                await new Promise((resolve) => {
                    const id = setInterval(() => {
                        if (timestamp - getPlaybackTime() < 1) { clearInterval(id); resolve(); }
                    }, 100);
                });
            }
        }
    };

    // ------------------------------------------------------------------
    // 7. Controls
    // ------------------------------------------------------------------
    const getPlaybackTime = () => {
        if (playing) {
            return audioContext.currentTime - audioContextStartTime + playbackTimeAtStart;
        }
        return playbackTimeAtStart;
    };

    const play = async () => {
        if (!fileLoaded) return;
        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }
        if (getPlaybackTime() === endTimestamp) {
            playbackTimeAtStart = firstTimestamp;
            await startVideoIterator();
        }
        audioContextStartTime = audioContext.currentTime;
        playing = true;
        if (audioSink) {
            if (audioBufferIterator) await audioBufferIterator.return();
            audioBufferIterator = audioSink.buffers(getPlaybackTime());
            void runAudioIterator();
        }
        posterImg.style.display = 'none';
        player.classList.add('avs-playing');
    };

    const pause = () => {
        playbackTimeAtStart = getPlaybackTime();
        playing = false;
        if (audioBufferIterator) audioBufferIterator.return();
        audioBufferIterator = null;
        for (const node of queuedAudioNodes) { node.stop(); }
        queuedAudioNodes.clear();
        player.classList.remove('avs-playing');
    };

    const togglePlay = () => {
        if (playing) { pause(); } else { void play(); }
    };

    const seekToTime = async (seconds) => {
        const wasPlaying = playing;
        if (wasPlaying) pause();
        playbackTimeAtStart = Math.max(firstTimestamp, Math.min(seconds, endTimestamp));
        await startVideoIterator();
        renderTime(playbackTimeAtStart);
        if (wasPlaying && playbackTimeAtStart < endTimestamp) void play();
    };

    const updateVolume = () => {
        const actual = volumeMuted ? 0 : volume;
        if (gainNode) gainNode.gain.value = actual * actual;
        muteBtn.textContent = actual === 0 ? '🔇' : (actual < 0.5 ? '🔉' : '🔊');
    };

    const renderTime = (seconds) => {
        currentEl.textContent = formatSeconds(seconds);
        const range = (endTimestamp - firstTimestamp) || 1;
        seekFill.style.width = `${Math.max(0, Math.min(100, ((seconds - firstTimestamp) / range) * 100))}%`;
    };

    const disposePlayback = () => {
        playing = false;
        fileLoaded = false;
        asyncId++;
        if (videoFrameIterator) videoFrameIterator.return();
        videoFrameIterator = null;
        if (audioBufferIterator) audioBufferIterator.return();
        audioBufferIterator = null;
        for (const node of queuedAudioNodes) { node.stop(); }
        queuedAudioNodes.clear();
        if (audioContext && audioContext.state !== 'closed') {
            void audioContext.close();
        }
        audioContext = null;
        gainNode = null;
        videoSink = null;
        audioSink = null;
        nextFrame = null;
    };

    const showError = (msg) => {
        errorBox.textContent = msg || '';
        errorBox.style.display = msg ? '' : 'none';
    };

    const formatSeconds = (seconds) => {
        seconds = Math.max(0, Math.round(seconds * 1000) / 1000);
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        const pad = (n) => String(n).padStart(2, '0');
        return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
    };

    // ------------------------------------------------------------------
    // 8. Event listeners
    // ------------------------------------------------------------------
    playBtn.addEventListener('click', togglePlay);
    muteBtn.addEventListener('click', () => { volumeMuted = !volumeMuted; updateVolume(); });
    fullBtn.addEventListener('click', () => {
        if (document.fullscreenElement) {
            void document.exitFullscreen();
        } else {
            player.requestFullscreen().catch(() => {});
        }
    });
    player.addEventListener('click', (e) => {
        if (e.target.closest('.avs-controls')) return;
        togglePlay();
    });

    seekBar.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        const rect = seekBar.getBoundingClientRect();
        const ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        renderTime(firstTimestamp + ratio * (endTimestamp - firstTimestamp));
        const onUp = (ev) => {
            const r = seekBar.getBoundingClientRect();
            const ratio2 = Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width));
            void seekToTime(firstTimestamp + ratio2 * (endTimestamp - firstTimestamp));
            window.removeEventListener('pointerup', onUp);
        };
        window.addEventListener('pointerup', onUp, { once: true });
    });

    // Quality selector
    if (sources.length > 1) {
        qualitySel.style.display = '';
        sources.forEach((s, i) => {
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = s.res ? `${s.res}p` : (s.label || `Qualidade ${i + 1}`);
            qualitySel.appendChild(opt);
        });
        qualitySel.addEventListener('change', () => {
            const src = sources[parseInt(qualitySel.value, 10)];
            if (src) void initMediaPlayer(src);
        });
    }

    // Keyboard shortcuts (space/k, arrows, m, f)
    window.addEventListener('keydown', (e) => {
        if (!fileLoaded) return;
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA')) return;
        if (e.code === 'Space' || e.code === 'KeyK') {
            togglePlay();
        } else if (e.code === 'ArrowLeft') {
            void seekToTime(getPlaybackTime() - 5);
        } else if (e.code === 'ArrowRight') {
            void seekToTime(getPlaybackTime() + 5);
        } else if (e.code === 'KeyM') {
            volumeMuted = !volumeMuted;
            updateVolume();
        } else if (e.code === 'KeyF') {
            fullBtn.click();
        } else {
            return;
        }
        e.preventDefault();
    });

    // ------------------------------------------------------------------
    // 9. Go
    // ------------------------------------------------------------------
    if (supportsWebCodecs) {
        void startPlayer();
    } else {
        // Native fallback: plain <video> with the same (signed) URL
        fallbackVideo.src = pickSource().src;
        fallbackVideo.load();
    }
})();