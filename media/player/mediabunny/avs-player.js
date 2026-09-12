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
    const playBtn = player.querySelector('.avs-controls-row [data-action="play"]');
    const muteBtn = player.querySelector('[data-action="volume"]');
    const fullBtn = player.querySelector('[data-action="fullscreen"]');
    const settingsBtn = player.querySelector('[data-action="settings"]');
    const volumeSlider = player.querySelector('.avs-volume-slider');
    const seekBar = player.querySelector('.avs-seek');
    const controlsBar = player.querySelector('.avs-controls');
    const seekFill = player.querySelector('.avs-seek-fill');
    const seekBuffer = player.querySelector('.avs-seek-buffer');
    const currentEl = player.querySelector('.avs-current');
    const durationEl = player.querySelector('.avs-duration');
    const qualitySel = player.querySelector('.avs-quality');
    const settingsPanel = player.querySelector('.avs-settings');
    const centerEl = player.querySelector('.avs-center');
    const centerToggle = player.querySelector('.avs-center-toggle');
    const centerRw = player.querySelector('.avs-center-rw');
    const centerFw = player.querySelector('.avs-center-fw');

    // Troca o ícone de um botão que envolve <svg><use>, apontando o <use>
    // para outro <symbol> do sprite.
    const setIcon = (el, id) => {
        if (!el) return;
        const use = el.querySelector('use');
        if (use) use.setAttribute('href', '#' + id);
    };

    const context2d = canvas.getContext('2d');
    const autoplay = player.dataset.autoplay === '1';
    const startMuted = (typeof window.player_start_muted !== 'undefined') ? window.player_start_muted === '1' : true;
    const poster = player.dataset.poster || '';
    if (poster) {
        posterImg.src = poster;
        posterImg.style.display = '';
    }

    // Prévia (miniclip das thumbs) + play central: o <video> vem renderizado no
    // template com autoplay mudo (o browser já baixa a mídia durante o parse),
    // então aqui só escondemos o poster quando ela tocar. O primeiro play
    // encerra a prévia e inicia o vídeo completo com som.
    let previewVideo = player.querySelector('.avs-preview-video');
    let bigPlayBtn = player.querySelector('.avs-big-play');

    const stopAndHidePreview = () => {
        if (previewVideo) {
            previewVideo.pause();
            previewVideo.remove();
            previewVideo = null;
        }
        if (bigPlayBtn) {
            bigPlayBtn.remove();
            bigPlayBtn = null;
        }
    };

    const setupPreview = () => {
        // Autoplay já inicia o vídeo completo: prévia e botão não fazem sentido.
        if (autoplay || !previewVideo) {
            stopAndHidePreview();
            return;
        }
        previewVideo.addEventListener('playing', () => {
            posterImg.style.display = 'none';
            markUiReady();
        });
        void previewVideo.play().catch(() => {});
    };

    const supportsWebCodecs = typeof window.VideoDecoder !== 'undefined';

    // Resolves once the Media Bunny pipeline (or the native fallback) is ready
    // to accept a play() call — guards manual clicks that arrive mid-init.
    let resolveReady = null;
    const readyPromise = new Promise((r) => { resolveReady = r; });
    // UI-ready: só liga o guard da página (esconde a mensagem de erro do tpl),
    // SEM liberar o readyPromise — a prévia tocando não significa que o
    // pipeline do mediabunny terminou o init (senão um play no clique vira
    // no-op e o vídeo não inicia).
    const markUiReady = () => { window.__avsReady = true; };
    // Playback-ready: o mediabunny (initMediaPlayer) ou o fallback nativo estão
    // prontos para aceitar play() — libera os cliques que chegaram no meio.
    const markPlaybackReady = () => { window.__avsReady = true; if (resolveReady) { resolveReady(); resolveReady = null; } };

    // Native <video> fallback — used when WebCodecs is missing OR when Media
    // Bunny cannot decode/fetch the file in this browser (e.g. Firefox has no
    // H.264 in WebCodecs, or a GCS bucket without CORS blocks the signed-URL
    // fetch). Guarantees playback wherever the plain MP4 plays.
    let fallbackVideo = null;
    const useNativeFallback = (reason) => {
        if (fallbackVideo) return fallbackVideo;
        if (reason) console.warn('[AVS Mediabunny] fallback nativo:', reason);
        fallbackVideo = document.createElement('video');
        fallbackVideo.className = 'avs-fallback-video';
        // Native controls OFF — the custom control bar (play/seek/time/volume/
        // quality + sprite timeline) is wired to the <video> in
        // setupFallbackControls(), so the player keeps its own UI (and the
        // timeline/sprite preview) even in fallback mode.
        fallbackVideo.controls = false;
        fallbackVideo.playsInline = true;
        fallbackVideo.muted = startMuted;
        if (poster) fallbackVideo.poster = poster;
        player.insertBefore(fallbackVideo, player.firstChild);
        posterImg.style.display = 'none';
        player.classList.add('avs-fallback');
        setIcon(muteBtn, startMuted ? 'avs-i-vol-mute' : 'avs-i-vol-high');
        setupFallbackControls();
        markPlaybackReady();
        return fallbackVideo;
    };

    // WebCodecs decode failures surface on later iterator.next() calls (Media
    // Bunny creates its decoders lazily), which the fire-and-forget loops in
    // updateNextFrame()/runAudioIterator() would otherwise turn into unhandled
    // "EncodingError: Decoding error" rejections. Recover like startPlayer()
    // does: drop to the native <video> fallback and resume from the current
    // position so playback keeps working without a page reload.
    let activeSource = null;
    let decodeFailureHandled = false;
    const handleDecodeFailure = (err) => {
        if (decodeFailureHandled) return;
        decodeFailureHandled = true;
        console.warn('[AVS Mediabunny] falha de decodificação:', err);
        const wasPlaying = playing;
        const resumeAt = getPlaybackTime();
        disposePlayback();
        const fv = useNativeFallback(err && err.message ? err.message : String(err));
        fv.src = (activeSource || pickSource()).src;
        fv.load();
        fv.addEventListener('loadedmetadata', () => {
            try { fv.currentTime = Math.min(resumeAt, fv.duration || resumeAt); } catch (e) { /* keep 0 */ }
            if (wasPlaying) void fv.play().catch(() => {});
        }, { once: true });
    };

    // Keeps the custom control bar + timeline (and the sprite preview) working
    // on top of the native <video> fallback. Only registers listeners — the
    // callbacks run after the module has fully initialized.
    const setupFallbackControls = () => {
        const fb = fallbackVideo;
        if (!fb) return;

        const syncFromVideo = () => {
            renderTime(fb.currentTime || 0);
        };
        // Buffered bar: the native <video> exposes the real loaded ranges.
        const syncBufferFromVideo = () => {
            if (!fb.buffered || fb.buffered.length === 0) return;
            bufferedEnd = fb.buffered.end(fb.buffered.length - 1);
            renderBuffer();
        };

        fb.addEventListener('loadedmetadata', () => {
            if (fb.duration && isFinite(fb.duration)) {
                endTimestamp = fb.duration;
                firstTimestamp = 0;
                durationEl.textContent = formatSeconds(fb.duration);
            }
            // Match the player box to the real video ratio (vertical included).
            if (fb.videoWidth > 0 && fb.videoHeight > 0) {
                applyAspectRatio(fb.videoWidth, fb.videoHeight);
            }
            syncFromVideo();
        });
        fb.addEventListener('durationchange', () => {
            if (fb.duration && isFinite(fb.duration)) {
                endTimestamp = fb.duration;
                firstTimestamp = 0;
                durationEl.textContent = formatSeconds(fb.duration);
            }
        });
        fb.addEventListener('timeupdate', syncFromVideo);
        fb.addEventListener('seeked', syncFromVideo);
        fb.addEventListener('progress', syncBufferFromVideo);
        fb.addEventListener('loadedmetadata', syncBufferFromVideo);
        fb.addEventListener('play', () => {
            player.classList.add('avs-playing');
            hidePauseAd();
            updatePlayIcon(true);
            hideCenter();
            cancelIdle();
            armIdle();
        });
        fb.addEventListener('pause', () => {
            player.classList.remove('avs-playing');
            updatePlayIcon(false);
            if (!seeking) showCenter();
            cancelIdle();
            // Pause ad parity: only on a real user pause past 1s, never while
            // seeking or at the very end of the video.
            if (!seeking && fb.currentTime > 1 && fb.currentTime < (fb.duration || Infinity) - 0.5) {
                showPauseAd();
            }
        });
        fb.addEventListener('ended', () => {
            updatePlayIcon(false);
            renderTime(fb.duration || 0);
            onEnded();
        });
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
    // True depois que o usuário altera o mute manualmente: aí uma tentativa de
    // play/seek NÃO deve desmutar (respeita quem mutou de propósito).
    let muteTouched = false;
    let playbackRate = 1;

    // Start muted (configurable in admin playeredit)
    if (startMuted) {
        volumeMuted = true;
    }
    let seeking = false;
    let endedFired = false;
    const queuedAudioNodes = new Set();

    // Buffered indicator: `bufferedEnd` is the furthest loaded position (in
    // seconds). Fallback mode uses the native `buffered` ranges; Media Bunny
    // mode approximates it from the source's byte download progress.
    let bufferedEnd = 0;
    let bufferedBytesEnd = 0;
    let sourceSize = 0;
    // Downloaded byte ranges (Media Bunny mode), used to compute the contiguous
    // prefix from byte 0 — an MP4's moov atom often lives at the end of the
    // file and is fetched first, so a naive "max byte read" would over-report.
    const bufferedRanges = []; // sorted, disjoint [start, end)
    const addBufferedRange = (start, end) => {
        if (end <= start) return;
        let i = 0;
        while (i < bufferedRanges.length && bufferedRanges[i].end < start) i++;
        let merged = { start: start, end: end };
        while (i < bufferedRanges.length && bufferedRanges[i].start <= merged.end) {
            merged.start = Math.min(merged.start, bufferedRanges[i].start);
            merged.end = Math.max(merged.end, bufferedRanges[i].end);
            bufferedRanges.splice(i, 1);
        }
        bufferedRanges.splice(i, 0, merged);
        // Recompute the contiguous prefix from byte 0 (with a small tolerance
        // so tiny inter-read gaps don't stall the indicator).
        let contiguous = 0;
        const gapTolerance = 256 * 1024;
        for (const r of bufferedRanges) {
            if (r.start <= contiguous + gapTolerance) {
                contiguous = Math.max(contiguous, r.end);
            } else {
                break;
            }
        }
        bufferedBytesEnd = contiguous;
    };

    // ------------------------------------------------------------------
    // 4. Initialization
    // ------------------------------------------------------------------
    const pickSource = () => {
        return orderedSources()[0];
    };

    // Sources ordered by preference (resolution setting), highest first
    // unless the user prefers low. Used to retry with the next rendition
    // when the preferred file is corrupt/unreadable.
    const orderedSources = () => {
        if (sources.length <= 1) {
            return [...sources];
        }
        // Honor the player_settings.tpl resolution preference when present
        const pref = typeof window.player_resolution !== 'undefined' ? window.player_resolution : 'high';
        const sorted = [...sources].sort((a, b) => a.res - b.res);
        return pref === 'low' ? sorted : sorted.reverse();
    };

    // Keeps the quality selector in sync when playback auto-downgrades to a
    // lower rendition (e.g. the preferred file is corrupt).
    const syncQualitySel = (src) => {
        if (!qualitySel) return;
        const idx = sources.indexOf(src);
        if (idx >= 0) {
            qualitySel.value = String(idx);
            if (automaticQuality) markAutoQuality();
            else markQuality(idx);
        }
    };

    const initMediaPlayer = async (source) => {
        disposePlayback();
        asyncId++;
        decodeFailureHandled = false;
        fileLoaded = false;
        showError('');
        posterImg.style.display = poster ? '' : 'none';

        const urlSource = new UrlSource(source.src);
        // Media Bunny reads lazily (no buffered ranges API), so track the
        // source's download progress: onread fires with each fetched byte
        // range, and the contiguous downloaded prefix maps to a buffered
        // playback position.
        bufferedBytesEnd = 0;
        sourceSize = 0;
        bufferedRanges.length = 0;
        urlSource.onread = (start, end) => {
            addBufferedRange(start, end);
            if (sourceSize > 0) {
                bufferedEnd = firstTimestamp + (bufferedBytesEnd / sourceSize) * (endTimestamp - firstTimestamp);
                renderBuffer();
            }
        };
        void urlSource.getSizeOrNull().then((s) => { if (s) sourceSize = s; });

        const input = new Input({
            source: urlSource,
            formats: ALL_FORMATS
        });

        let videoTrack = await input.getPrimaryVideoTrack();
        let audioTrack = await input.getPrimaryAudioTrack();
        const tracks = [videoTrack, audioTrack].filter((t) => t !== null);

        firstTimestamp = Math.max(await input.getFirstTimestamp(tracks), 0);
        endTimestamp = await input.getDurationFromMetadata(tracks, { skipLiveWait: true })
            ?? await input.computeDuration(tracks, { skipLiveWait: true });
        bufferedEnd = firstTimestamp;
        renderBuffer();

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
            applyAspectRatio(canvas.width, canvas.height);
        } else {
            canvas.style.display = 'none';
            applyAspectRatio(0, 0);
        }

        fileLoaded = true;
        playbackTimeAtStart = firstTimestamp;
        await startVideoIterator();
        renderTime(playbackTimeAtStart);
        durationEl.textContent = formatSeconds(endTimestamp);
        markPlaybackReady();

        // Autoplay is handled by beginPlayback() (section 9.4) so a VAST
        // pre-roll can run before the content when enabled.
    };

    const beginPlayback = () => {
        if (!adPlayed) {
            void playAd().then(() => {
                void readyPromise.then(() => {
                    if (fallbackVideo) {
                        void fallbackVideo.play().catch(() => {});
                    } else {
                        void play();
                    }
                });
            });
        } else {
            void readyPromise.then(() => {
                if (fallbackVideo) {
                    void fallbackVideo.play().catch(() => {});
                } else {
                    void play();
                }
            });
        }
    };

    // Loads one source into the native <video> fallback, resolving true when
    // the file proves playable (loadedmetadata) or false on error/timeout.
    const loadNativeSource = (fv, src) => new Promise((resolve) => {
        const done = (ok) => {
            fv.removeEventListener('loadedmetadata', onOk);
            fv.removeEventListener('error', onFail);
            clearTimeout(timer);
            resolve(ok);
        };
        const onOk = () => done(true);
        const onFail = () => done(false);
        const timer = setTimeout(() => done(false), 12000);
        fv.addEventListener('loadedmetadata', onOk);
        fv.addEventListener('error', onFail);
        fv.src = src.src;
        fv.load();
    });

    const startPlayer = async () => {
        const order = orderedSources();
        let lastErr = null;
        // Try each rendition in preference order: a corrupt preferred file
        // (e.g. broken 720p) must not leave the player black when a lower
        // rendition of the same video is fine.
        for (const src of order) {
            try {
                await initMediaPlayer(src);
                // Guard: if initMediaPlayer succeeded but produced no video
                // sink (corrupt file → audio-only), skip to the next source.
                if (!videoSink) {
                    disposePlayback();
                    throw new Error('Fonte sem trilha de vídeo utilizável.');
                }
                syncQualitySel(src);
                activeSource = src;
                return;
            } catch (err) {
                lastErr = err;
                console.error('[AVS Mediabunny]', err);
            }
        }
        // Media Bunny failed on every source (unsupported browser or broken
        // files): fall back to the native <video>, also trying each source.
        showError('');
        disposePlayback();
        const fv = useNativeFallback(lastErr && lastErr.message ? lastErr.message : String(lastErr));
        for (const src of order) {
            if (await loadNativeSource(fv, src)) {
                syncQualitySel(src);
                activeSource = src;
                return;
            }
        }
        showError('Não foi possível carregar o vídeo. Tente outra qualidade ou recarregue a página.');
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
                endedFired = true;
                onEnded();
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
    setInterval(() => { render(false); renderBuffer(); }, 500);

    const updateNextFrame = async () => {
        const id = asyncId;
        try {
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
        } catch (err) {
            if (id === asyncId) handleDecodeFailure(err);
        }
    };

    // ------------------------------------------------------------------
    // 6. Audio loop
    // ------------------------------------------------------------------
    const runAudioIterator = async () => {
        if (!audioSink) return;
        try {
            for await (const { buffer, timestamp } of audioBufferIterator) {
                const node = audioContext.createBufferSource();
                node.buffer = buffer;
                node.connect(gainNode);
                node.playbackRate.value = playbackRate;
                let start = audioContextStartTime + (timestamp - playbackTimeAtStart) / playbackRate;
                start = Math.round(audioContext.sampleRate * start) / audioContext.sampleRate;
                if (start >= audioContext.currentTime) {
                    node.start(start);
                } else {
                    node.start(audioContext.currentTime, (audioContext.currentTime - start) * playbackRate);
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
        } catch (err) {
            handleDecodeFailure(err);
        }
    };

    // ------------------------------------------------------------------
    // 7. Controls
    // ------------------------------------------------------------------
    const getPlaybackTime = () => {
        if (playing) {
            return playbackTimeAtStart + (audioContext.currentTime - audioContextStartTime) * playbackRate;
        }
        return playbackTimeAtStart;
    };

    const play = async () => {
        hidePauseAd();
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
        updatePlayIcon(true);
        hideCenter();
        cancelIdle();
        armIdle();
    };

    const pause = () => {
        playbackTimeAtStart = getPlaybackTime();
        playing = false;
        if (audioBufferIterator) audioBufferIterator.return();
        audioBufferIterator = null;
        for (const node of queuedAudioNodes) { node.stop(); }
        queuedAudioNodes.clear();
        player.classList.remove('avs-playing');
        updatePlayIcon(false);
        if (!seeking) showCenter();
        cancelIdle();
        // Pause ad (parity with video-js-events.js): only on a real user pause
        // past 1s, never while seeking or at the very end of the video.
        if (!seeking && getPlaybackTime() > 1 && getPlaybackTime() < endTimestamp - 0.5) {
            showPauseAd();
        }
    };

    const togglePlay = () => {
        if (previewVideo) {
            stopAndHidePreview();
        }
        if (fallbackVideo) {
            if (fallbackVideo.paused) {
                ensureAudible();
                fallbackVideo.muted = volumeMuted;
                void fallbackVideo.play().catch(() => {});
            } else {
                fallbackVideo.pause();
            }
            return;
        }
        if (playing) { pause(); } else { ensureAudible(); beginPlayback(); }
    };

    const seekToTime = async (seconds, forcePlay = false) => {
        if (fallbackVideo) {
            const d = fallbackVideo.duration || 0;
            fallbackVideo.currentTime = Math.max(0, Math.min(seconds, d));
            renderTime(fallbackVideo.currentTime);
            if (forcePlay) {
                fallbackVideo.muted = volumeMuted;
                void fallbackVideo.play().catch(() => {});
            }
            return;
        }
        seeking = true;
        const wasPlaying = playing;
        if (wasPlaying) pause();
        playbackTimeAtStart = Math.max(firstTimestamp, Math.min(seconds, endTimestamp));
        await startVideoIterator();
        renderTime(playbackTimeAtStart);
        if ((wasPlaying || forcePlay) && playbackTimeAtStart < endTimestamp) void play();
        seeking = false;
    };

    const updateVolume = () => {
        if (fallbackVideo) {
            fallbackVideo.muted = volumeMuted;
            fallbackVideo.volume = Math.max(0, Math.min(1, volume));
        }
        const actual = volumeMuted ? 0 : volume;
        if (gainNode) gainNode.gain.value = actual * actual;
        updateVolumeUi();
    };

    // Atualiza o slider e o ícone de volume. Separada para ser chamada de
    // outros pontos (updateVolume, slider input, teclado) sem re-aplicar o
    // ganho de áudio.
    const updateVolumeUi = () => {
        if (volumeSlider) volumeSlider.value = String(Math.round(volume * 100));
        const actual = volumeMuted ? 0 : volume;
        setIcon(muteBtn, actual === 0 ? 'avs-i-vol-mute' : (actual < 0.5 ? 'avs-i-vol-low' : 'avs-i-vol-high'));
    };

    // Play/seek disparados por gesto do usuário tocam COM SOM: desmuda quando o
    // estado mudo ainda é o inicial (start_muted da config), não quando o
    // usuário mutou manualmente.
    const ensureAudible = () => {
        if (volumeMuted && !muteTouched) {
            volumeMuted = false;
            updateVolume();
        }
    };

    const updatePlayIcon = (isPlaying) => {
        setIcon(playBtn, isPlaying ? 'avs-i-pause' : 'avs-i-play');
        setIcon(centerToggle, isPlaying ? 'avs-i-pause' : 'avs-i-play');
    };

    const renderTime = (seconds) => {
        currentEl.textContent = formatSeconds(seconds);
        const range = (endTimestamp - firstTimestamp) || 1;
        const pct = Math.max(0, Math.min(100, ((seconds - firstTimestamp) / range) * 100));
        seekFill.style.width = `${pct}%`;
        if (seekBar) seekBar.setAttribute('aria-valuenow', String(Math.round(pct)));
    };

    const renderBuffer = () => {
        if (!seekBuffer) return;
        const range = (endTimestamp - firstTimestamp) || 1;
        seekBuffer.style.width = `${Math.max(0, Math.min(100, ((bufferedEnd - firstTimestamp) / range) * 100))}%`;
    };

    // Player box SEMPRE 16:9 (padrão de layout do site), independente da
    // proporção real do vídeo (vertical ou 4:3 letterboxa dentro do box).
    const applyAspectRatio = () => {
        player.style.aspectRatio = '16 / 9';
        player.classList.remove('avs-vertical');
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
        bufferedEnd = 0;
        bufferedBytesEnd = 0;
        sourceSize = 0;
        // Back to the default box while the next source loads.
        applyAspectRatio(0, 0);
    };

    const showError = (msg) => {
        errorBox.textContent = msg || '';
        errorBox.style.display = msg ? '' : 'none';
        // Erro real: esconde o play central para não ficar por cima da mensagem.
        if (bigPlayBtn) bigPlayBtn.style.display = msg ? 'none' : '';
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
    if (bigPlayBtn) {
        bigPlayBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            togglePlay();
        });
    }
    muteBtn.addEventListener('click', () => { muteTouched = true; volumeMuted = !volumeMuted; updateVolume(); });
    const toggleFullscreen = () => {
        if (document.fullscreenElement) {
            void document.exitFullscreen();
        } else {
            player.requestFullscreen().catch(() => {});
        }
    };
    fullBtn.addEventListener('click', toggleFullscreen);
    // Duplo clique = fullscreen (YouTube). O clique do player suprime o toggle
    // de play quando vem logo atrás de outro (parte de um dblclick), senão o
    // dblclick pausaria o vídeo por engano.
    let lastPlayerClick = 0;
    player.addEventListener('dblclick', (e) => {
        if (e.target.closest('.avs-controls, .avs-settings, .avs-center, .avs-ad, .avs-pause-ad, #autoplay-overlay, .avs-error')) return;
        toggleFullscreen();
    });
    player.addEventListener('click', (e) => {
        // Clicks on the control bar or on any overlay (VAST ad, pause ad,
        // autoplay-next, logo, big play, settings, volume, center overlay) must
        // NOT toggle the content — their own handlers deal with them (avoids a
        // double-toggle that would pause playback right after resume/skip).
        if (e.target.closest('.avs-controls, .avs-ad, .avs-pause-ad, .avs-logo, #autoplay-overlay, .avs-error, .avs-big-play, .avs-settings, .avs-volume-wrap, .avs-center')) return;
        const now = Date.now();
        if (now - lastPlayerClick < 350) { lastPlayerClick = now; return; }
        lastPlayerClick = now;
        togglePlay();
    });

    seekBar.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        player.classList.add('avs-dragging');
        const rect = seekBar.getBoundingClientRect();
        const ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        renderTime(firstTimestamp + ratio * (endTimestamp - firstTimestamp));
        const onUp = (ev) => {
            player.classList.remove('avs-dragging');
            const r = seekBar.getBoundingClientRect();
            const ratio2 = Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width));
            // Click/largar na linha = ir para o tempo E tocar (com som).
            ensureAudible();
            void seekToTime(firstTimestamp + ratio2 * (endTimestamp - firstTimestamp), true);
            window.removeEventListener('pointerup', onUp);
        };
        window.addEventListener('pointerup', onUp, { once: true });
    });

    // Quality selector
    if (sources.length > 1) {
        sources.forEach((s, i) => {
            const opt = document.createElement('option');
            opt.value = i;
            opt.textContent = s.res ? `${s.res}p` : (s.label || `Qualidade ${i + 1}`);
            qualitySel.appendChild(opt);
        });
        qualitySel.addEventListener('change', () => {
            const src = sources[parseInt(qualitySel.value, 10)];
            if (!src) return;
            // Troca de resolução preserva a posição de reprodução e o estado
            // (tocando/pausado): captura ANTES de reinicializar o pipeline.
            const resumeAt = Math.max(0, Math.min(getPlaybackTime(), endTimestamp || getPlaybackTime()));
            const wasPlaying = playing;
            activeSource = src;
            if (fallbackVideo) {
                const t = fallbackVideo.currentTime || 0;
                fallbackVideo.addEventListener('loadedmetadata', () => {
                    fallbackVideo.currentTime = Math.max(0, Math.min(t, fallbackVideo.duration || t));
                    void fallbackVideo.play().catch(() => {});
                }, { once: true });
                fallbackVideo.src = src.src;
                fallbackVideo.load();
                return;
            }
            void initMediaPlayer(src).then(() => {
                if (!videoSink) {
                    disposePlayback();
                    showError('Esta qualidade falhou. Escolha outra qualidade.');
                    return;
                }
                void seekToTime(resumeAt, wasPlaying);
            }).catch((err) => {
                console.error('[AVS Mediabunny]', err);
                disposePlayback();
                showError('Esta qualidade falhou. Escolha outra qualidade.');
            });
        });
    }

    // Keyboard shortcuts (space/k, arrows, m, f)
    window.addEventListener('keydown', (e) => {
        if (!fileLoaded && !fallbackVideo) return;
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON')) return;
        const code = e.code;
        const seek = (delta) => {
            if (fallbackVideo) {
                fallbackVideo.currentTime = Math.max(0, Math.min(fallbackVideo.duration || 0, fallbackVideo.currentTime + delta));
            } else {
                void seekToTime(getPlaybackTime() + delta);
            }
            flashCenter();
        };
        const changeVolume = (delta) => {
            volumeMuted = false;
            volume = Math.max(0, Math.min(1, volume + delta));
            updateVolume();
        };
        let handled = false;
        if (code === 'Space' || code === 'KeyK') {
            togglePlay(); handled = true;
        } else if (code === 'KeyJ') {
            seek(-10); handled = true;
        } else if (code === 'KeyL') {
            seek(10); handled = true;
        } else if (code === 'ArrowLeft') {
            seek(-5); handled = true;
        } else if (code === 'ArrowRight') {
            seek(5); handled = true;
        } else if (code === 'ArrowUp') {
            changeVolume(0.1); handled = true;
        } else if (code === 'ArrowDown') {
            changeVolume(-0.1); handled = true;
        } else if (code === 'KeyM') {
            volumeMuted = !volumeMuted;
            updateVolume(); handled = true;
        } else if (code === 'KeyF') {
            toggleFullscreen(); handled = true;
        } else if (code === 'Home') {
            seek(-getPlaybackTime()); handled = true;
        } else if (code === 'End') {
            seek(endTimestamp - getPlaybackTime()); handled = true;
        } else if (code === 'Escape') {
            closeSettings();
            hideCenter(); handled = true;
        } else if (/^Digit[0-9]$/.test(code)) {
            const pct = parseInt(code.slice(5), 10) / 10;
            seek((endTimestamp * pct) - getPlaybackTime()); handled = true;
        }
        if (!handled) return;
        e.preventDefault();
    });

    // ------------------------------------------------------------------
    // 8.1 Playback helpers (velocidade / seek) — usados pelo menu de
    //     configurações, teclado e overlay central.
    // ------------------------------------------------------------------
    const applyFallbackRate = (rate) => {
        if (fallbackVideo) fallbackVideo.playbackRate = rate;
    };

    const applyRate = (rate) => {
        playbackRate = rate;
        applyFallbackRate(rate);
        markSpeed(rate);
        // Re-sync the audio pipeline when the rate changes mid-playback
        if (playing && !fallbackVideo && audioSink) {
            if (audioBufferIterator) audioBufferIterator.return();
            audioBufferIterator = audioSink.buffers(getPlaybackTime());
            void runAudioIterator();
        }
    };

    const seekBy = (delta) => {
        if (fallbackVideo) {
            fallbackVideo.currentTime = Math.max(0, Math.min(
                fallbackVideo.duration || 0,
                fallbackVideo.currentTime + delta
            ));
            return;
        }
        void seekToTime(getPlaybackTime() + delta);
    };

    // ------------------------------------------------------------------
    // 8.2 YouTube-style overlays: volume slider, settings menu, center
    //     overlay (pausa / seek), idle auto-hide, dblclick fullscreen
    // ------------------------------------------------------------------
    const adBreakActive = () => player.classList.contains('avs-ad-playing');

    // Center overlay [‹10][play][10›] — aparece na pausa e por um instante
    // quando o usuário busca pelo teclado.
    let centerFlashTimer = null;
    const canShowCenter = () => !adBreakActive() && fileLoaded && !endedFired
        && getPlaybackTime() < (endTimestamp - 0.1);
    const showCenter = () => {
        if (!canShowCenter()) return hideCenter();
        player.classList.add('avs-center-visible');
    };
    const hideCenter = () => {
        clearTimeout(centerFlashTimer);
        player.classList.remove('avs-center-visible');
    };
    const flashCenter = () => {
        if (!canShowCenter()) return;
        player.classList.add('avs-center-visible');
        clearTimeout(centerFlashTimer);
        centerFlashTimer = setTimeout(hideCenter, 600);
    };
    if (centerToggle) {
        centerToggle.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); togglePlay(); });
        centerRw.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); seekBy(-10); flashCenter(); });
        centerFw.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); seekBy(10); flashCenter(); });
    }

    // Idle auto-hide (só desktop com hover): esconde controles e cursor
    // enquanto toca, reaparece ao mexer o mouse.
    const idleSupported = window.matchMedia && window.matchMedia('(hover: hover)').matches;
    let idleTimer = null;
    const cancelIdle = () => {
        clearTimeout(idleTimer);
        idleTimer = null;
        player.classList.remove('avs-idle');
    };
    const armIdle = () => {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
            if (playing && settingsPanel && !settingsPanel.classList.contains('avs-settings-open')) {
                player.classList.add('avs-idle');
            }
        }, 3000);
    };
    if (idleSupported) {
        player.addEventListener('mousemove', () => {
            if (!playing) return;
            cancelIdle();
            armIdle();
        });
    }

    // Volume slider (YouTube-style)
    if (volumeSlider) {
        volumeSlider.addEventListener('input', () => {
            volumeMuted = false;
            volume = Math.max(0, Math.min(1, (parseInt(volumeSlider.value, 10) || 0) / 100));
            updateVolume();
        });
    }

    // Settings menu (velocidade + qualidade). O <select> nativo permanece
    // oculto como driver: seus itens gravam nele e disparam change — o resto
    // do pipeline de troca de resolução fica intacto.
    const RATES = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2];
    // Qualidade "Automática": acompanha a preferência do usuário
    // (player_resolution) e a troca automática de rendição por arquivo
    // corrompido no startPlayer.
    let automaticQuality = true;

    const qualityName = (s, i) => (s.res ? s.res + 'p' : (s.label || 'Qualidade ' + (i + 1)));

    const buildSettingsItem = (label, value, onClick, sub) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'avs-settings-item';
        btn.dataset.value = String(value);

        const text = document.createElement('span');
        text.className = 'avs-settings-item-text';
        text.textContent = label;
        if (sub) {
            const subEl = document.createElement('span');
            subEl.className = 'avs-settings-item-sub';
            subEl.textContent = sub;
            text.appendChild(subEl);
        }
        btn.appendChild(text);

        const check = document.createElement('span');
        check.className = 'material-symbols-rounded avs-settings-check';
        check.setAttribute('aria-hidden', 'true');
        check.textContent = 'check';
        btn.appendChild(check);

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            onClick();
        });
        return btn;
    };

    const markSpeed = (rate) => {
        if (!settingsPanel) return;
        settingsPanel.querySelectorAll('[data-settings-group="speed"] .avs-settings-item').forEach((el) => {
            el.classList.toggle('avs-settings-active', parseFloat(el.dataset.value) === rate);
        });
    };

    const markQuality = (idx) => {
        if (!settingsPanel) return;
        settingsPanel.querySelectorAll('[data-settings-group="quality"] .avs-settings-item').forEach((el) => {
            el.classList.toggle('avs-settings-active', el.dataset.value !== 'auto' && parseInt(el.dataset.value, 10) === idx);
        });
    };

    const markAutoQuality = () => {
        if (!settingsPanel) return;
        settingsPanel.querySelectorAll('[data-settings-group="quality"] .avs-settings-item').forEach((el) => {
            el.classList.toggle('avs-settings-active', el.dataset.value === 'auto');
        });
    };

    const openSettings = () => {
        cancelIdle();
        player.classList.add('avs-settings-open');
    };
    const closeSettings = () => {
        player.classList.remove('avs-settings-open');
        armIdle();
    };
    const toggleSettings = () => {
        if (player.classList.contains('avs-settings-open')) closeSettings();
        else openSettings();
    };

    // Abas do menu: cada grupo (Qualidade / Velocidade) vira uma tab M3.
    const initSettingsTabs = () => {
        if (!settingsPanel) return;
        const tabs = settingsPanel.querySelectorAll('.avs-settings-tab');
        const panes = settingsPanel.querySelectorAll('.avs-settings-pane');
        const activate = (name) => {
            tabs.forEach((t) => {
                const on = t.dataset.settingsTab === name;
                t.classList.toggle('avs-settings-tab-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panes.forEach((p) => {
                p.classList.toggle('avs-settings-pane-active', p.dataset.settingsGroup === name);
            });
        };
        tabs.forEach((t) => {
            t.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                activate(t.dataset.settingsTab);
            });
        });
    };
    initSettingsTabs();

    const switchQualitySource = (i) => {
        qualitySel.value = String(i);
        qualitySel.dispatchEvent(new Event('change'));
    };

    const populateSettings = () => {
        if (!settingsPanel) return;
        const speedGroup = settingsPanel.querySelector('[data-settings-group="speed"]');
        const qualityGroup = settingsPanel.querySelector('[data-settings-group="quality"]');
        if (speedGroup) {
            speedGroup.textContent = '';
            RATES.forEach((rate) => {
                speedGroup.appendChild(buildSettingsItem(
                    rate === 1 ? 'Normal' : rate + 'x',
                    rate,
                    () => applyRate(rate)
                ));
            });
        }
        if (qualityGroup) {
            qualityGroup.textContent = '';
            if (sources.length > 1) {
                const auto = pickSource();
                qualityGroup.appendChild(buildSettingsItem(
                    'Automática',
                    'auto',
                    () => {
                        automaticQuality = true;
                        markAutoQuality();
                        const idx = sources.indexOf(auto);
                        if (idx >= 0 && auto !== activeSource) switchQualitySource(idx);
                    },
                    auto && (auto.res ? auto.res + 'p' : (auto.label || ''))
                ));
            }
            sources.forEach((s, i) => {
                qualityGroup.appendChild(buildSettingsItem(
                    qualityName(s, i),
                    i,
                    () => {
                        automaticQuality = false;
                        switchQualitySource(i);
                        markQuality(i);
                    }
                ));
            });
        }
        markSpeed(playbackRate);
        if (automaticQuality) markAutoQuality();
        else markQuality(qualitySel ? (parseInt(qualitySel.value, 10) || 0) : 0);
    };
    if (settingsBtn) {
        settingsBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleSettings();
        });
    }
    document.addEventListener('click', (e) => {
        if (!settingsPanel || !player.classList.contains('avs-settings-open')) return;
        if (!e.target.closest('.avs-settings') && !e.target.closest('[data-action="settings"]')) {
            closeSettings();
        }
    });

    // Fullscreen icon sync
    document.addEventListener('fullscreenchange', () => {
        setIcon(fullBtn, document.fullscreenElement ? 'avs-i-fs-exit' : 'avs-i-fs-enter');
    });

    // ------------------------------------------------------------------
    // 9. Player profile features (parity with the original Video.js player)
    // ------------------------------------------------------------------
    // Reads the same globals the site already emits via player_settings.tpl
    // (player_pause_adv, aid, player_logo*, player_sprite, player_timeline_preview,
    // video_duration, base_url, related_videos_data) plus the VAST data-* attrs.
    const cfg = {
        pauseAdv:    player.dataset.pauseAdv === '1' || window.player_pause_adv === '1',
        aid:         (typeof window.aid !== 'undefined' && window.aid && window.aid !== 'false') ? window.aid : '',
        vastEnabled: player.dataset.vastEnabled === '1',
        vastUrl:     player.dataset.vastUrl || '',
        vastCancel:  parseInt(player.dataset.vastCancel || '5000', 10) || 5000,
        logo:        window.player_logo === '1',
        logoImage:   window.player_logo_image || (base_url + '/media/player/logo/logo.png'),
        logoLink:    (window.player_logo_link && window.player_logo_link !== '') ? window.player_logo_link : (base_url + '/video/' + video_id + '/' + (window.location.pathname.split('/').pop() || '')),
        logoPosition: window.player_logo_position || 'top-right',
        logoOpacity: parseFloat(window.player_logo_opacity || '40') / 100 || 0.4,
        timelinePreview: window.player_timeline_preview === '1',
        sprite:      window.player_sprite || '',
        sourceW:     parseInt(player.dataset.sourceW || '0', 10) || 0,
        sourceH:     parseInt(player.dataset.sourceH || '0', 10) || 0,
        duration:    parseFloat(window.video_duration || 0) || 0,
        related:     (typeof window.related_videos_data !== 'undefined' && Array.isArray(window.related_videos_data)) ? window.related_videos_data : [],
        baseUrl:     (typeof window.base_url !== 'undefined') ? window.base_url : '',
        videoId:     (typeof window.video_id !== 'undefined') ? window.video_id : '',
    };

    // --- 9.1 Logo overlay ------------------------------------------------
    const setupLogo = () => {
        if (!cfg.logo || !cfg.logoImage) return;
        const wrap = document.createElement('a');
        wrap.className = 'avs-logo avs-logo-' + (cfg.logoPosition || 'top-right');
        wrap.href = cfg.logoLink;
        wrap.target = '_blank';
        wrap.rel = 'noopener';
        const img = document.createElement('img');
        img.src = cfg.logoImage;
        img.alt = '';
        wrap.style.opacity = String(cfg.logoOpacity);
        wrap.appendChild(img);
        player.appendChild(wrap);
    };
    setupLogo();

    // --- 9.2 Pause ad (adv_pause) ---------------------------------------
    // Mirrors video-js-events.js: when the user pauses (past 1s, not seeking)
    // an iframe to ads.php?id=aid is centered over the player with RESUME/CLOSE.
    let adPauseEl = null;
    let adIframe = null;

    const showPauseAd = () => {
        if (adPauseEl) return;
        if (!cfg.pauseAdv || !cfg.aid) return;

        adPauseEl = document.createElement('div');
        adPauseEl.className = 'avs-pause-ad';
        adPauseEl.style.position = 'absolute';
        adPauseEl.style.left = '50%';
        adPauseEl.style.top = '50%';
        adPauseEl.style.transform = 'translate(-50%, -50%)';
        adPauseEl.style.zIndex = '6';
        adPauseEl.style.textAlign = 'center';
        adPauseEl.style.lineHeight = 'normal';

        adIframe = document.createElement('iframe');
        adIframe.className = 'ad-iframe';
        adIframe.src = cfg.baseUrl + '/ads.php?id=' + cfg.aid;
        adIframe.setAttribute('marginwidth', '0');
        adIframe.setAttribute('marginheight', '0');
        adIframe.setAttribute('frameborder', '0');
        adIframe.setAttribute('scrolling', 'no');
        adIframe.style.border = '0';
        adIframe.style.display = 'block';

        const controls = document.createElement('div');
        controls.className = 'ad-controls';
        const resume = document.createElement('button');
        resume.id = 'ad-resume';
        resume.className = 'ad-resume';
        resume.title = 'Resume';
        resume.innerHTML = '&#9654;&nbsp;&nbsp;RESUME';
        const close = document.createElement('button');
        close.id = 'ad-close';
        close.className = 'ad-close';
        close.title = 'Close Ad';
        close.innerHTML = '&#10005;&nbsp;&nbsp;CLOSE';
        controls.appendChild(resume);
        controls.appendChild(close);

        resume.addEventListener('click', (e) => {
            e.preventDefault();
            hidePauseAd();
            if (fallbackVideo) {
                void fallbackVideo.play().catch(() => {});
            } else {
                void play();
            }
        });
        close.addEventListener('click', (e) => {
            e.preventDefault();
            hidePauseAd();
        });

        // Size iframe after load (like resizeIframe in the original)
        adIframe.addEventListener('load', () => {
            try {
                const doc = adIframe.contentWindow.document;
                const w = doc.body.scrollWidth;
                const h = doc.body.scrollHeight;
                if (w > 0 && h > 0) {
                    adIframe.style.width = w + 'px';
                    adIframe.style.height = h + 'px';
                }
            } catch (e) { /* cross-origin: keep defaults */ }
        });

        adPauseEl.appendChild(controls);
        adPauseEl.appendChild(adIframe);
        player.appendChild(adPauseEl);
    };

    const hidePauseAd = () => {
        if (adPauseEl && adPauseEl.parentNode) {
            adPauseEl.parentNode.removeChild(adPauseEl);
        }
        adPauseEl = null;
        adIframe = null;
    };

    // --- 9.3 Timeline: hover time tooltip + sprite preview ---------------
    // The sprite (sprite.class.php) is a single row of 320px-wide tiles, one
    // per available frame — missing frames are skipped, so the real frame count
    // is measured from the image at runtime. Tiles são sempre 320x180 (16:9):
    // para vídeos verticais o frame vem esticado no tile, então a largura do
    // slot é derivada do ASPECTO REAL da fonte (canvas/vídeo/clip), não do
    // tile — e a tira é exibida com width explícito, revertendo o esmagamento.
    // Preview exibido a 180px de altura (1:1 com o tile de 320x180 do sprite)
    // — landscape 320x180, vertical ~104x180.
    let previewEl = null;
    let previewImg = null;
    let seekTipEl = null;

    const setupTimelinePreview = () => {
        console.info('[AVS Mediabunny] timeline preview:', cfg.timelinePreview ? 'ON' : 'OFF', '| sprite:', cfg.sprite || '(ausente)');

        // Hover time label — always available on the seek bar.
        seekTipEl = document.createElement('div');
        seekTipEl.className = 'avs-seek-tip';
        seekTipEl.style.display = 'none';
        player.appendChild(seekTipEl);

        const hasSprite = cfg.timelinePreview && !!cfg.sprite;
        let thumbW = 0;
        let thumbH = 0;
        let frameCount = 0;
        let spriteTileH = 0;

        const buildPreview = () => {
            previewEl = document.createElement('div');
            previewEl.className = 'avs-preview';
            previewEl.style.position = 'absolute';
            previewEl.style.pointerEvents = 'none';
            previewEl.style.display = 'none';
            previewEl.style.zIndex = '5';
            previewEl.style.overflow = 'hidden';
            previewEl.style.width = thumbW + 'px';
            previewEl.style.height = thumbH + 'px';
            previewEl.style.border = '1px solid rgba(255,255,255,0.6)';
            previewEl.style.background = '#000';

            previewImg = document.createElement('img');
            previewImg.src = cfg.sprite;
            previewImg.style.position = 'absolute';
            previewImg.style.top = '0';
            previewImg.style.maxWidth = 'none';
            previewImg.style.width = (frameCount * thumbW) + 'px';
            previewImg.style.height = thumbH + 'px';
            previewEl.appendChild(previewImg);
            player.appendChild(previewEl);
        };

        // Largura do slot: altura fixa 180 × ASPECTO REAL DA FONTE (nunca o tile
        // do sprite — tiles são 320x180 fixos, então frames verticais chegam
        // esmagados). A tira é exibida com width = frameCount × thumbW e height
        // = thumbH: quando os dois não batem com o tile, a re-escala não-uniforme
        // desesmaga o conteúdo e restaura a proporção correta.
        // Ordem de confiança: vídeo fallback > clip de preview (aspecto real do
        // arquivo) > canvas (só se já dimensionado, >300px). O canvas é 16:9
        // fixo — não serve para vídeos verticais.
        const sourceAspectKnown = () => {
            // Dimensões reais da fonte (DB: width_sd/height_sd) são a verdade —
            // o canvas é 16:9 fixo e o transcod de preview chega letterboxed
            // (960x540) mesmo para vídeo vertical.
            if (cfg.sourceW > 0 && cfg.sourceH > 0) return cfg.sourceW / cfg.sourceH;
            if (fallbackVideo && fallbackVideo.videoWidth > 0) return fallbackVideo.videoWidth / fallbackVideo.videoHeight;
            if (previewVideo && previewVideo.videoWidth > 0) return previewVideo.videoWidth / previewVideo.videoHeight;
            if (canvas.width > 300 && canvas.height > 0) return canvas.width / canvas.height;
            return 0;
        };

        const recomputeThumb = () => {
            if (!frameCount) return;
            let aspect = sourceAspectKnown();
            if (!(aspect > 0)) aspect = spriteTileH > 0 ? (320 / spriteTileH) : (16 / 9);
            aspect = Math.max(0.2, Math.min(5, aspect));
            const w = Math.max(1, Math.round(thumbH * aspect));
            if (w === thumbW) return; // já dimensionado — evita rewrites a cada pointermove
            thumbW = w;
            if (previewEl) {
                previewEl.style.width = thumbW + 'px';
                previewEl.style.height = thumbH + 'px';
            }
            if (previewImg) {
                previewImg.style.width = (frameCount * thumbW) + 'px';
                previewImg.style.height = thumbH + 'px';
            }
        };

        if (hasSprite) {
            // Measure the sprite's real geometry: tiles are 320px wide and the
            // strip is one tile tall, so frameCount = width / 320. O <link
            // rel=preload> do template já dispara o download na parse; aqui a
            // prioridade alta garante slot antes das dezenas de thumbs.
            const probe = new Image();
            probe.fetchPriority = 'high';
            probe.onload = () => {
                const tileW = 320; // sprite.class.php SPRITE_TILE_W
                const nw = probe.naturalWidth;
                const nh = probe.naturalHeight;
                if (nw > 0 && nh > 0) {
                    frameCount = Math.max(1, Math.round(nw / tileW));
                    spriteTileH = nh;
                    thumbH = 180;
                    recomputeThumb();
                    buildPreview();
                    recomputeThumb();
                }
            };
            probe.src = cfg.sprite;
        }

        const durationAt = () => (cfg.duration > 0 ? cfg.duration : (endTimestamp > 0 ? endTimestamp : 0));

        const hideTimeline = () => {
            if (previewEl) previewEl.style.display = 'none';
            if (seekTipEl) seekTipEl.style.display = 'none';
        };

        // Hit zone ampliada: além dos 14px do seek, a prévia também aparece com
        // o cursor na barra de controles (até ~12px acima/abaixo da tira) — não
        // precisa acertar exatamente a linha vermelha.
        const hoverPadY = 12;
        const hoverPadX = 10;
        const onTimelineMove = (clientX, clientY) => {
            const rect = seekBar.getBoundingClientRect();
            if (clientX < rect.left - hoverPadX || clientX > rect.right + hoverPadX ||
                clientY < rect.top - hoverPadY || clientY > rect.bottom + hoverPadY) {
                hideTimeline();
                return;
            }
            const ratio = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            const dur = durationAt() || 1;
            const t = ratio * dur;
            const pRect = player.getBoundingClientRect();
            const seekTop = rect.top - pRect.top;

            // Time tooltip, centered on the pointer (clamped to the player).
            seekTipEl.textContent = formatSeconds(t);
            seekTipEl.style.display = 'block';
            let tipLeft = rect.left - pRect.left + (rect.width * ratio) - (seekTipEl.offsetWidth / 2);
            tipLeft = Math.max(0, Math.min(pRect.width - seekTipEl.offsetWidth, tipLeft));
            seekTipEl.style.left = tipLeft + 'px';
            let tipBottom = (player.offsetHeight - seekTop) + 14;
            if (hasSprite && previewEl) tipBottom += thumbH + 6;
            seekTipEl.style.bottom = tipBottom + 'px';

            // Sprite frame preview (frame count measured from the sprite).
            if (hasSprite && previewEl && previewImg && frameCount > 0) {
                recomputeThumb(); // corrige tamanho assim que o aspecto da fonte é conhecido
                const step = dur / frameCount;
                const frame = Math.min(frameCount - 1, Math.max(0, Math.round(t / step)));
                previewImg.style.left = (-(frame * thumbW)) + 'px';
                // Show FIRST so offsetWidth is measured while visible.
                previewEl.style.display = 'block';
                const previewW = previewEl.offsetWidth;
                let left = rect.left - pRect.left + (rect.width * ratio) - (previewW / 2);
                left = Math.max(0, Math.min(pRect.width - previewW, left));
                previewEl.style.left = left + 'px';
                previewEl.style.bottom = (player.offsetHeight - seekTop) + 12 + 'px';
            }
        };

        seekBar.addEventListener('pointermove', (e) => onTimelineMove(e.clientX, e.clientY));
        seekBar.addEventListener('pointerleave', hideTimeline);
        if (controlsBar) {
            controlsBar.addEventListener('pointermove', (e) => onTimelineMove(e.clientX, e.clientY));
            controlsBar.addEventListener('pointerleave', hideTimeline);
        }
    };
    setupTimelinePreview();

    // --- 9.4 VAST linear pre-roll ---------------------------------------
    // Lightweight VAST 2/3/4 client: fetch adTagUrl, parse XML, play the first
    // playable MP4/WebM MediaFile in a <video> overlay with a skip button,
    // click-through and impression/error beacons. Any failure -> skip ad.
    let adPlayed = false;
    let adOverlay = null;

    const parseVast = (xmlText) => {
        const doc = new DOMParser().parseFromString(xmlText, 'text/xml');
        if (doc.querySelector('parsererror')) return null;
        const linear = doc.querySelector('Linear');
        if (!linear) return null;

        const mediaFiles = Array.from(linear.querySelectorAll('MediaFile'));
        const media = mediaFiles.find((m) => {
            const type = (m.getAttribute('type') || '').toLowerCase();
            const txt = (m.textContent || '').trim();
            return txt && (/mp4|webm|video\//.test(type) || /\.(mp4|webm)(\?|$)/i.test(txt));
        }) || mediaFiles[0];
        if (!media || !(media.textContent || '').trim()) return null;

        const parseDur = (txt) => {
            if (!txt) return 0;
            const m = txt.trim().match(/(\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?/);
            if (m) return parseInt(m[1], 10) * 3600 + parseInt(m[2], 10) * 60 + parseInt(m[3], 10);
            return parseFloat(txt.trim()) || 0;
        };

        const track = (ev) => Array.from(linear.querySelectorAll('TrackingEvents Tracking[event="' + ev + '"]'))
            .map((t) => (t.textContent || '').trim()).filter(Boolean);

        return {
            mediaUrl: media.textContent.trim(),
            duration: parseDur((linear.querySelector('Duration') || {}).textContent),
            skipOffset: parseDur((linear.querySelector('SkipOffset') || {}).textContent),
            clickThrough: (linear.querySelector('VideoClicks ClickThrough') || {}).textContent || '',
            impression: Array.from(doc.querySelectorAll('Impression')).map((t) => (t.textContent || '').trim()).filter(Boolean),
            trackers: {
                start: track('start'),
                firstQuartile: track('firstQuartile'),
                midpoint: track('midpoint'),
                thirdQuartile: track('thirdQuartile'),
                complete: track('complete'),
            },
        };
    };

    const fireBeacons = (urls) => {
        (urls || []).forEach((u) => {
            try {
                const img = new Image();
                img.src = u;
            } catch (e) { /* ignore */ }
        });
    };

    const loadAdTag = (url, timeoutMs) => {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), timeoutMs || 5000);
        return fetch(url, { signal: ctrl.signal, credentials: 'omit' })
            .then((r) => {
                if (!r.ok) throw new Error('VAST HTTP ' + r.status);
                return r.text();
            })
            .finally(() => clearTimeout(timer));
    };

    const playAd = () => new Promise((resolve) => {
        adPlayed = true;
        if (!cfg.vastEnabled || !cfg.vastUrl) return resolve();

        loadAdTag(cfg.vastUrl, cfg.vastCancel).then((xml) => {
            const ad = parseVast(xml);
            if (!ad || !ad.mediaUrl) return resolve();

            adOverlay = document.createElement('div');
            adOverlay.className = 'avs-ad';
            adOverlay.style.position = 'absolute';
            adOverlay.style.inset = '0';
            adOverlay.style.zIndex = '7';
            adOverlay.style.background = '#000';
            adOverlay.style.display = 'flex';
            adOverlay.style.alignItems = 'center';
            adOverlay.style.justifyContent = 'center';

            const adVideo = document.createElement('video');
            adVideo.src = ad.mediaUrl;
            adVideo.autoplay = true;
            adVideo.playsInline = true;
            adVideo.controls = true;
            adVideo.style.maxWidth = '100%';
            adVideo.style.maxHeight = '100%';
            adVideo.style.width = '100%';
            adVideo.style.height = '100%';
            adVideo.style.objectFit = 'contain';
            adVideo.style.background = '#000';

            const skipBtn = document.createElement('button');
            skipBtn.className = 'avs-ad-skip';
            skipBtn.textContent = 'Pular anúncio';
            skipBtn.style.position = 'absolute';
            skipBtn.style.right = '12px';
            skipBtn.style.bottom = '48px';
            skipBtn.style.zIndex = '8';
            skipBtn.style.display = 'none';

            const clickBox = document.createElement('a');
            clickBox.className = 'avs-ad-click';
            clickBox.style.position = 'absolute';
            clickBox.style.inset = '0';
            clickBox.style.zIndex = '7';
            clickBox.style.display = 'block';
            if (ad.clickThrough) {
                clickBox.href = ad.clickThrough;
                clickBox.target = '_blank';
                clickBox.rel = 'noopener';
            }

            fireBeacons(ad.impression);
            let started = false;
            const onTime = () => {
                const d = ad.duration;
                const t = adVideo.currentTime;
                if (!started && t > 0) { started = true; fireBeacons(ad.trackers.start); }
                if (d > 0 && t >= d * 0.25) fireBeacons(ad.trackers.firstQuartile);
                if (d > 0 && t >= d * 0.5) fireBeacons(ad.trackers.midpoint);
                if (d > 0 && t >= d * 0.75) fireBeacons(ad.trackers.thirdQuartile);
            };
            adVideo.addEventListener('timeupdate', onTime);
            adVideo.addEventListener('ended', () => {
                fireBeacons(ad.trackers.complete);
                cleanupAd();
                resolve();
            });
            adVideo.addEventListener('error', () => {
                cleanupAd();
                resolve();
            });
            adVideo.addEventListener('pause', () => {
                // If the ad ends and the browser fires pause, treat as done when duration reached
                if (ad.duration > 0 && adVideo.currentTime >= ad.duration - 0.3) {
                    fireBeacons(ad.trackers.complete);
                    cleanupAd();
                    resolve();
                }
            });

            skipBtn.addEventListener('click', () => { cleanupAd(); resolve(); });

            // Show skip after VAST skipoffset, or after adCancelTimeout as fallback
            const skipAt = ad.skipOffset > 0 ? ad.skipOffset : (cfg.vastCancel / 1000);
            if (skipAt > 0 && (!ad.duration || skipAt < ad.duration)) {
                setTimeout(() => { if (adOverlay) skipBtn.style.display = ''; }, Math.min(skipAt, 30) * 1000);
            }

            adOverlay.appendChild(adVideo);
            adOverlay.appendChild(skipBtn);
            if (ad.clickThrough) adOverlay.appendChild(clickBox);
            player.insertBefore(adOverlay, player.firstChild);
            player.classList.add('avs-ad-playing');

            const p = adVideo.play();
            if (p) p.catch(() => { cleanupAd(); resolve(); });

            // Safety net: never let an ad block content forever
            setTimeout(() => {
                if (adOverlay) { cleanupAd(); resolve(); }
            }, 45000);
        }).catch(() => resolve());
    });

    const cleanupAd = () => {
        if (adOverlay && adOverlay.parentNode) {
            adOverlay.parentNode.removeChild(adOverlay);
        }
        adOverlay = null;
        player.classList.remove('avs-ad-playing');
    };

    // --- 9.5 Autoplay next overlay + sidebar card ------------------------
    // Port of the video-js-events.js block: track watched videos, populate the
    // autoplay-card and show a 3s countdown overlay on ended.
    const watchedKey = 'watched_videos';
    const maxWatched = 50;

    const getWatched = () => {
        try { return JSON.parse(localStorage.getItem(watchedKey)) || []; }
        catch (e) { return []; }
    };

    const markWatched = () => {
        if (!cfg.videoId) return;
        const watched = getWatched();
        if (watched.indexOf(cfg.videoId) === -1) {
            watched.push(cfg.videoId);
            if (watched.length > maxWatched) watched.shift();
            localStorage.setItem(watchedKey, JSON.stringify(watched));
        }
    };

    const getNextVideo = () => {
        if (!cfg.related || cfg.related.length === 0) return null;
        const watched = getWatched();
        for (let i = 0; i < cfg.related.length; i++) {
            if (watched.indexOf(String(cfg.related[i].vid)) === -1) {
                return cfg.related[i];
            }
        }
        localStorage.removeItem(watchedKey);
        return cfg.related[0];
    };

    const populateAutoplayCard = () => {
        const nextVideo = getNextVideo();
        if (!nextVideo) return;
        const card = document.getElementById('autoplay-card');
        if (!card) return;
        const cardLink = card.querySelector('.autoplay-card-body');
        const cardImg = card.querySelector('.autoplay-card-thumb img');
        const cardDur = card.querySelector('.autoplay-card-duration');
        const cardTitle = card.querySelector('.autoplay-card-title');
        const cardViews = card.querySelector('.autoplay-card-views');
        const cardRate = card.querySelector('.autoplay-card-rate');
        const cardRateWrap = card.querySelector('.autoplay-card-rate-wrap');
        if (cardLink) cardLink.href = cfg.baseUrl + '/video/' + nextVideo.vid + '/' + nextVideo.slug;
        if (cardImg) cardImg.src = nextVideo.thumb;
        if (cardImg) cardImg.alt = nextVideo.title;
        if (cardDur) cardDur.textContent = nextVideo.duration;
        if (cardTitle) cardTitle.textContent = nextVideo.title;
        if (cardViews) cardViews.textContent = nextVideo.views;
        if (cardRate) cardRate.textContent = nextVideo.rate != 0 ? nextVideo.rate + '%' : '';
        if (cardRateWrap) cardRateWrap.style.display = (nextVideo.rate != 0) ? '' : 'none';
        card.style.display = '';
    };

    let autoplayTimer = null;
    let autoplayOverlay = null;

    const showAutoplayNext = () => {
        if (!getNextVideo()) return;
        if (localStorage.getItem('autoplayNext') === 'false') return;
        const nextVideo = getNextVideo();

        let overlay = document.getElementById('autoplay-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'autoplay-overlay';
            overlay.style.position = 'absolute';
            overlay.style.inset = '0';
            overlay.style.zIndex = '10';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.background = 'rgba(0,0,0,0.88)';
            overlay.innerHTML =
                '<div class="autoplay-overlay-content">' +
                '  <div class="autoplay-next-count" id="autoplay-count">3</div>' +
                '  <div class="autoplay-next-thumb">' +
                '    <img id="autoplay-overlay-thumb" src="" alt="">' +
                '    <div class="autoplay-next-duration" id="autoplay-overlay-dur"></div>' +
                '  </div>' +
                '  <div class="autoplay-next-title" id="autoplay-overlay-title"></div>' +
                '  <div class="autoplay-overlay-buttons">' +
                '    <button id="autoplay-cancel" class="autoplay-btn-cancel">CANCELAR</button>' +
                '    <button id="autoplay-skip" class="autoplay-btn-skip">PRÓXIMO <i class="fas fa-forward"></i></button>' +
                '  </div>' +
                '</div>';
            player.appendChild(overlay);
        }

        const thumb = document.getElementById('autoplay-overlay-thumb');
        const dur = document.getElementById('autoplay-overlay-dur');
        const title = document.getElementById('autoplay-overlay-title');
        if (thumb) thumb.src = nextVideo.thumb;
        if (thumb) thumb.alt = nextVideo.title;
        if (dur) dur.textContent = nextVideo.duration;
        if (title) title.textContent = nextVideo.title;
        overlay.style.display = 'flex';

        const nextUrl = cfg.baseUrl + '/video/' + nextVideo.vid + '/' + nextVideo.slug + '?autoplay=1';
        let count = 3;
        const countEl = document.getElementById('autoplay-count');
        if (countEl) countEl.textContent = count;

        if (autoplayTimer) clearInterval(autoplayTimer);
        autoplayTimer = setInterval(() => {
            count--;
            if (count <= 0) {
                clearInterval(autoplayTimer);
                window.location.href = nextUrl;
            } else if (countEl) {
                countEl.textContent = count;
            }
        }, 1000);

        const cancel = document.getElementById('autoplay-cancel');
        if (cancel) cancel.onclick = (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearInterval(autoplayTimer);
            overlay.style.display = 'none';
        };
        const skip = document.getElementById('autoplay-skip');
        if (skip) skip.onclick = (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearInterval(autoplayTimer);
            window.location.href = nextUrl;
        };
    };

    const onEnded = () => {
        markWatched();
        showAutoplayNext();
    };

    // ------------------------------------------------------------------
    // 10. Go
    // ------------------------------------------------------------------
    markWatched();
    populateAutoplayCard();
    populateSettings();
    updateVolumeUi();

    // Prévia muda + play central antes do vídeo completo.
    setupPreview();

    if (supportsWebCodecs) {
        void startPlayer().then(() => {
            if (autoplay) beginPlayback();
        });
    } else {
        // Native fallback: plain <video> with the same (signed) URL. Controls
        // and ended handling are wired by setupFallbackControls().
        fallbackVideo.src = pickSource().src;
        fallbackVideo.load();
        if (autoplay) beginPlayback();
    }
})();