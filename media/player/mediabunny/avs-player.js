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
    const controlsBar = player.querySelector('.avs-controls');
    const seekFill = player.querySelector('.avs-seek-fill');
    const seekBuffer = player.querySelector('.avs-seek-buffer');
    const currentEl = player.querySelector('.avs-current');
    const durationEl = player.querySelector('.avs-duration');
    const qualitySel = player.querySelector('.avs-quality');

    const context2d = canvas.getContext('2d');
    const autoplay = player.dataset.autoplay === '1';
    const startMuted = (typeof window.player_start_muted !== 'undefined') ? window.player_start_muted === '1' : true;
    const quickControls = (typeof window.player_quick_controls !== 'undefined') ? window.player_quick_controls === '1' : true;
    const poster = player.dataset.poster || '';
    if (poster) {
        posterImg.src = poster;
        posterImg.style.display = '';
    }

    const supportsWebCodecs = typeof window.VideoDecoder !== 'undefined';

    // Resolves once the Media Bunny pipeline (or the native fallback) is ready
    // to accept a play() call — guards manual clicks that arrive mid-init.
    let resolveReady = null;
    const readyPromise = new Promise((r) => { resolveReady = r; });
    const markReady = () => { window.__avsReady = true; if (resolveReady) { resolveReady(); resolveReady = null; } };

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
        muteBtn.textContent = startMuted ? '🔇' : '🔊';
        setupFallbackControls();
        markReady();
        return fallbackVideo;
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
        });
        fb.addEventListener('pause', () => {
            player.classList.remove('avs-playing');
            // Pause ad parity: only on a real user pause past 1s, never while
            // seeking or at the very end of the video.
            if (!seeking && fb.currentTime > 1 && fb.currentTime < (fb.duration || Infinity) - 0.5) {
                showPauseAd();
            }
        });
        fb.addEventListener('ended', () => {
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
        markReady();

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
    };

    const pause = () => {
        playbackTimeAtStart = getPlaybackTime();
        playing = false;
        if (audioBufferIterator) audioBufferIterator.return();
        audioBufferIterator = null;
        for (const node of queuedAudioNodes) { node.stop(); }
        queuedAudioNodes.clear();
        player.classList.remove('avs-playing');
        // Pause ad (parity with video-js-events.js): only on a real user pause
        // past 1s, never while seeking or at the very end of the video.
        if (!seeking && getPlaybackTime() > 1 && getPlaybackTime() < endTimestamp - 0.5) {
            showPauseAd();
        }
    };

    const togglePlay = () => {
        if (fallbackVideo) {
            if (fallbackVideo.paused) {
                void fallbackVideo.play().catch(() => {});
            } else {
                fallbackVideo.pause();
            }
            return;
        }
        if (playing) { pause(); } else { beginPlayback(); }
    };

    const seekToTime = async (seconds) => {
        if (fallbackVideo) {
            const d = fallbackVideo.duration || 0;
            fallbackVideo.currentTime = Math.max(0, Math.min(seconds, d));
            renderTime(fallbackVideo.currentTime);
            return;
        }
        seeking = true;
        const wasPlaying = playing;
        if (wasPlaying) pause();
        playbackTimeAtStart = Math.max(firstTimestamp, Math.min(seconds, endTimestamp));
        await startVideoIterator();
        renderTime(playbackTimeAtStart);
        if (wasPlaying && playbackTimeAtStart < endTimestamp) void play();
        seeking = false;
    };

    const updateVolume = () => {
        if (fallbackVideo) {
            fallbackVideo.muted = volumeMuted;
            fallbackVideo.volume = Math.max(0, Math.min(1, volume));
            const actual = volumeMuted ? 0 : volume;
            muteBtn.textContent = actual === 0 ? '🔇' : (actual < 0.5 ? '🔉' : '🔊');
            return;
        }
        const actual = volumeMuted ? 0 : volume;
        if (gainNode) gainNode.gain.value = actual * actual;
        muteBtn.textContent = actual === 0 ? '🔇' : (actual < 0.5 ? '🔉' : '🔊');
    };

    const renderTime = (seconds) => {
        currentEl.textContent = formatSeconds(seconds);
        const range = (endTimestamp - firstTimestamp) || 1;
        seekFill.style.width = `${Math.max(0, Math.min(100, ((seconds - firstTimestamp) / range) * 100))}%`;
    };

    const renderBuffer = () => {
        if (!seekBuffer) return;
        const range = (endTimestamp - firstTimestamp) || 1;
        seekBuffer.style.width = `${Math.max(0, Math.min(100, ((bufferedEnd - firstTimestamp) / range) * 100))}%`;
    };

    // Adapts the player box to the video's real aspect ratio (vertical videos
    // included). The CSS default is 16:9; this overrides it once the true
    // dimensions are known, with sane clamps so a 0/faulty size never blows up
    // the layout.
    const applyAspectRatio = (w, h) => {
        if (w > 0 && h > 0) {
            const ratio = Math.max(0.4, Math.min(3.5, w / h));
            player.style.aspectRatio = String(ratio);
            player.style.setProperty('--avs-ratio', String(ratio));
            player.classList.toggle('avs-vertical', ratio < 1);
        } else {
            player.style.aspectRatio = '16 / 9';
            player.style.removeProperty('--avs-ratio');
            player.classList.remove('avs-vertical');
        }
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
        // Clicks on the control bar or on any overlay (VAST ad, pause ad,
        // autoplay-next, logo) must NOT toggle the content — their own
        // handlers deal with them (avoids a double-toggle that would pause
        // playback right after resume/skip).
        if (e.target.closest('.avs-controls, .avs-ad, .avs-pause-ad, .avs-logo, #autoplay-overlay, .avs-error, .avs-quick-controls')) return;
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
            if (!src) return;
            if (fallbackVideo) {
                fallbackVideo.src = src.src;
                void fallbackVideo.play().catch(() => {});
                return;
            }
            void initMediaPlayer(src);
        });
    }

    // Keyboard shortcuts (space/k, arrows, m, f)
    window.addEventListener('keydown', (e) => {
        if (!fileLoaded && !fallbackVideo) return;
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA')) return;
        if (e.code === 'Space' || e.code === 'KeyK') {
            togglePlay();
        } else if (e.code === 'ArrowLeft') {
            if (fallbackVideo) {
                fallbackVideo.currentTime = Math.max(0, fallbackVideo.currentTime - 5);
            } else {
                void seekToTime(getPlaybackTime() - 5);
            }
        } else if (e.code === 'ArrowRight') {
            if (fallbackVideo) {
                fallbackVideo.currentTime = Math.min(fallbackVideo.duration || 0, fallbackVideo.currentTime + 5);
            } else {
                void seekToTime(getPlaybackTime() + 5);
            }
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
    // 8.1 Quick controls card (play / speed / +5s) in the bottom-right corner
    // ------------------------------------------------------------------
    const applyFallbackRate = (rate) => {
        if (fallbackVideo) fallbackVideo.playbackRate = rate;
    };

    const applyRate = (rate) => {
        playbackRate = rate;
        applyFallbackRate(rate);
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

    let quickControlsEl = null;
    if (quickControls) {
        quickControlsEl = document.createElement('div');
        quickControlsEl.className = 'avs-quick-controls';
        quickControlsEl.innerHTML =
            '<button type="button" class="avs-qc-btn avs-qc-speed" title="Playback speed">1x</button>' +
            '<button type="button" class="avs-qc-btn avs-qc-back" title="-5 seconds">-5</button>' +
            '<button type="button" class="avs-qc-btn avs-qc-forward" title="+5 seconds">+5</button>';
        player.appendChild(quickControlsEl);

        const qcSpeed = quickControlsEl.querySelector('.avs-qc-speed');
        const qcBack = quickControlsEl.querySelector('.avs-qc-back');
        const qcFwd = quickControlsEl.querySelector('.avs-qc-forward');

        const qcSpeeds = [1, 1.25, 1.5, 2, 0.75, 0.5];
        let qcSpeedIndex = 0;

        qcSpeed.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            qcSpeedIndex = (qcSpeedIndex + 1) % qcSpeeds.length;
            applyRate(qcSpeeds[qcSpeedIndex]);
            qcSpeed.textContent = qcSpeeds[qcSpeedIndex] + 'x';
        });

        qcBack.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            seekBy(-5);
        });

        qcFwd.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            seekBy(5);
        });
    }

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
    // The sprite (sprite.class.php) is a single row of 320x180 tiles, one per
    // available frame — missing frames are skipped, so the real frame count is
    // measured from the image at runtime instead of assuming the old video-js
    // constants (20 frames, thumb 256x144 at 0.6).
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
            previewImg.style.height = thumbH + 'px';
            previewEl.appendChild(previewImg);
            player.appendChild(previewEl);
        };

        if (hasSprite) {
            // Measure the sprite's real geometry: tiles are 320px wide and the
            // strip is one tile tall, so frameCount = width / 320. Displayed at
            // the same ~86px height as before, the thumb width scales with the
            // sprite's actual aspect ratio.
            const probe = new Image();
            probe.onload = () => {
                const tileW = 320; // sprite.class.php SPRITE_TILE_W
                const nw = probe.naturalWidth;
                const nh = probe.naturalHeight;
                if (nw > 0 && nh > 0) {
                    frameCount = Math.max(1, Math.round(nw / tileW));
                    thumbH = 86;
                    thumbW = Math.round(thumbH * (tileW / nh));
                    buildPreview();
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