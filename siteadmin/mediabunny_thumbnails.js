/*
 * Mediabunny — Fase 6: thumbnails e vthumbs (preview de hover).
 *
 * Espelha function_video.php:
 *  - extract_video_thumbs(): default.jpg (seek aleatório) + 1..20.jpg (seeks
 *    regulares), detecção/remoção de barras pretas (min entre frames, pads de
 *    +5/+5/+7/+7), dimensões CONTIDAS (sem upscale, pares) e sharpen;
 *  - extract_video_vthumbs_hq(): montagem de até 8 cortes de 2 s espalhados
 *    pelo vídeo, 30 fps CFR, cobertura center-crop (landscape) ou composição
 *    com backdrop desfocado (portrait), saídas video.mp4 (avc) e video.webm
 *    (vp9) sem áudio.
 *
 * A montagem é reconstruída quadro a quadro (CanvasSource) porque a API de
 * Conversion do mediabunny não concatena janelas não contíguas; a timeline de
 * SAÍDA permanece contígua, com cada quadro buscado na janela de origem certa.
 */
import {
  ALL_FORMATS,
  BlobSource,
  BufferTarget,
  CanvasSink,
  CanvasSource,
  Input,
  Mp4OutputFormat,
  Output,
  Quality,
  WebMOutputFormat,
} from 'https://cdn.jsdelivr.net/npm/mediabunny@1.55.6/dist/bundles/mediabunny.min.mjs';

// ---------------------------------------------------------------- seeks

/**
 * Plano de seeks dos 21 frames — espelha extract_video_thumbs():
 *  se = banda(duração) + rand(0, floor(dur/10)); seconds = dur - 2*se;
 *  default.jpg em rand(0,seconds)+se; 1..20.jpg em i*timeseg+se.
 * @param {number} duration
 * @param {() => number} rnd fonte aleatória (default Math.random)
 */
export function planFrameSeeks(duration, rnd = Math.random) {
  const d = Math.max(0.5, duration);
  let se = d > 5 ? 2 : d > 3 ? 1 : d > 2 ? 0.5 : 0;
  se += Math.floor(rnd() * (Math.floor(d / 10) + 1)); // rand(0, floor(dur/10)) inclusive
  const seconds = Math.max(0.1, d - 2 * se);
  const timeseg = seconds / 20;
  const seeks = { 0: rnd() * seconds + se };
  for (let i = 1; i <= 20; i++) {
    seeks[i] = i * timeseg + se;
  }
  return seeks;
}

// ---------------------------------------------------------------- dims

/**
 * Dimensões CONTIDAS no box, sem upscale, pares e >= 2 — thumb_target_dims().
 */
export function thumbTargetDims(srcW, srcH, boxW, boxH) {
  srcW = Math.max(2, srcW);
  srcH = Math.max(2, srcH);
  boxW = Math.max(2, boxW);
  boxH = Math.max(2, boxH);
  let w = srcW, h = srcH;
  if (!(srcW <= boxW && srcH <= boxH)) {
    const s = Math.min(boxW / srcW, boxH / srcH);
    w = srcW * s;
    h = srcH * s;
  }
  w = Math.max(2, Math.floor(w / 2) * 2);
  h = Math.max(2, Math.floor(h / 2) * 2);
  return { w, h };
}

// ---------------------------------------------------------------- black bars

function isNearBlack(px, idx, tol) {
  return Math.abs(px[idx] - 0) <= tol
    && Math.abs(px[idx + 1] - 0) <= tol
    && Math.abs(px[idx + 2] - 0) <= tol;
}

/**
 * Detecção de barras pretas — mesma varredura de detect_black_bars() (tol 30,
 * coef limita a profundidade de varredura; pads +5/+5/+7/+7 aplicados depois).
 */
export function detectBlackBars(canvas, coef) {
  const ctx = canvas.getContext('2d');
  const w = canvas.width;
  const h = canvas.height;
  const img = ctx.getImageData(0, 0, w, h);
  const px = img.data;
  const tol = 30;

  const colBlack = (x) => {
    for (let y = 0; y < h; y++) {
      const i = (y * w + x) * 4;
      if (!isNearBlack(px, i, tol)) return false;
    }
    return true;
  };
  const rowBlack = (y) => {
    for (let x = 0; x < w; x++) {
      const i = (y * w + x) * 4;
      if (!isNearBlack(px, i, tol)) return false;
    }
    return true;
  };

  let left = 0;
  const xLim = Math.floor(w * coef);
  for (let x = 0; x < xLim; x++) { if (!colBlack(x)) break; left++; }
  let right = 0;
  for (let x = w - 1; x > w - 1 - xLim; x--) { if (!colBlack(x)) break; right++; }
  let top = 0;
  const yLim = Math.floor(h * coef);
  for (let y = 0; y < yLim; y++) { if (!rowBlack(y)) break; top++; }
  let bottom = 0;
  for (let y = h - 1; y > h - 1 - yLim; y--) { if (!rowBlack(y)) break; bottom++; }

  return { left: left + 5, right: right + 5, top: top + 7, bottom: bottom + 7 };
}

// ---------------------------------------------------------------- imagens

/** Convolução 3x3 (mesmo kernel/divisor do sharp_image do AVS). */
function sharpenCanvas(canvas) {
  const ctx = canvas.getContext('2d');
  const { width: w, height: h } = canvas;
  const src = ctx.getImageData(0, 0, w, h);
  const out = ctx.createImageData(w, h);
  const s = src.data;
  const d = out.data;
  const kernel = [0, -1, 0, -1, 28, -1, 0, -1, 0];
  const divisor = 24;
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      let r = 0, g = 0, b = 0;
      let k = 0;
      for (let oy = -1; oy <= 1; oy++) {
        const yy = Math.min(h - 1, Math.max(0, y + oy));
        for (let ox = -1; ox <= 1; ox++) {
          const xx = Math.min(w - 1, Math.max(0, x + ox));
          const si = (yy * w + xx) * 4;
          r += kernel[k] * s[si];
          g += kernel[k] * s[si + 1];
          b += kernel[k] * s[si + 2];
          k++;
        }
      }
      const di = (y * w + x) * 4;
      d[di] = Math.max(0, Math.min(255, r / divisor));
      d[di + 1] = Math.max(0, Math.min(255, g / divisor));
      d[di + 2] = Math.max(0, Math.min(255, b / divisor));
      d[di + 3] = 255;
    }
  }
  ctx.putImageData(out, 0, 0);
}

async function canvasToJpeg(canvas, quality = 0.93) {
  // OffscreenCanvas → Blob JPEG (roda na UI e no worker; sem dependência de DOM).
  return canvas.convertToBlob({ type: 'image/jpeg', quality });
}

/**
 * Constrói os 21 JPEGs a partir de um arquivo processado (Blob MP4).
 * @param {Blob} blob MP4 processado (fonte das thumbs — o AVS usa o maior/1º formato)
 * @param {object} cfg
 * @param {number} cfg.playerW player box (default.jpg)
 * @param {number} cfg.playerH
 * @param {number} cfg.gridW  grid box (1..20.jpg)
 * @param {number} cfg.gridH
 * @param {boolean} cfg.removeBb
 * @param {boolean} cfg.sharpen
 * @returns {Promise<{seek:number, blob:Blob}|Array<{n:number,seek:number,blob:Blob}>>}
 */
export async function extractFrameSet(blob, cfg) {
  const input = new Input({ formats: ALL_FORMATS, source: new BlobSource(blob) });
  const track = await input.getPrimaryVideoTrack();
  if (!track) throw new Error('Sem trilha de vídeo para gerar thumbs.');
  const decodable = await track.canDecode();
  if (!decodable) throw new Error('Trilha não decodificável para thumbs.');

  const sink = new CanvasSink(track); // canvas no tamanho display (sem resize)
  const duration = await input.computeDuration();
  const srcW = await track.getDisplayWidth();
  const srcH = await track.getDisplayHeight();
  const seeks = planFrameSeeks(duration);
  const clamp = (t) => Math.max(0, Math.min(Math.max(0, duration - 0.05), t));

  // 1) captura dos 21 quadros (canvas em resolução nativa)
  const raws = [];
  for (const [key, rawSeek] of Object.entries(seeks)) {
    const n = key === '0' ? 0 : Number(key);
    const result = await sink.getCanvas(clamp(rawSeek));
    raws.push({ n, seek: clamp(rawSeek), canvas: result.canvas });
  }

  // 2) barras pretas: coef .20 no default, .25 nos demais; agrega por MIN (como o AVS)
  let l = 0, r = 0, t = 0, b = 0;
  if (cfg.removeBb) {
    for (const raw of raws) {
      const bb = detectBlackBars(raw.canvas, raw.n === 0 ? 0.2 : 0.25);
      l = l === 0 ? bb.left : Math.min(l, bb.left);
      r = r === 0 ? bb.right : Math.min(r, bb.right);
      t = t === 0 ? bb.top : Math.min(t, bb.top);
      b = b === 0 ? bb.bottom : Math.min(b, bb.bottom);
    }
  }
  const cropAll = (l + r + t + b) > 0;

  // 3) recorte, redimensionamento (contido ou stretch p/ box exato), sharpen, JPEG
  const out = { default: null, frames: [] };
  for (const raw of raws) {
    const cw = raw.canvas.width;
    const ch = raw.canvas.height;
    const isDef = raw.n === 0;
    // Pisos de qualidade do AVS (config abaixo do mínimo é ignorado):
    // player >= 1920x1080, grade >= 960x540.
    const boxW = isDef ? Math.max(1920, cfg.playerW || 1920) : Math.max(960, cfg.gridW || 960);
    const boxH = isDef ? Math.max(1080, cfg.playerH || 1080) : Math.max(540, cfg.gridH || 540);
    const final = cfg.keepAr
      ? thumbTargetDims(srcW, srcH, boxW, boxH)
      : { w: Math.max(2, boxW), h: Math.max(2, boxH) };

    let src = raw.canvas;
    if (cropAll) {
      const cropW = Math.max(2, Math.min(cw, cw - l - r));
      const cropH = Math.max(2, Math.min(ch, ch - t - b));
      const work = new OffscreenCanvas(cropW, cropH);
      work.getContext('2d').drawImage(src, Math.min(l, cw - 2), Math.min(t, ch - 2), cropW, cropH, 0, 0, cropW, cropH);
      src = work;
    }

    const dstCanvas = new OffscreenCanvas(final.w, final.h);
    const dctx = dstCanvas.getContext('2d');
    dctx.drawImage(src, 0, 0, final.w, final.h);
    if (cfg.sharpen) sharpenCanvas(dstCanvas);
    const jpg = await canvasToJpeg(dstCanvas);
    if (isDef) {
      out.default = { seek: raw.seek, blob: jpg };
    } else {
      out.frames.push({ n: raw.n, seek: raw.seek, blob: jpg });
    }
  }
  out.src = { w: srcW, h: srcH, duration };
  const pw = Math.max(1920, cfg.playerW || 1920);
  const ph = Math.max(1080, cfg.playerH || 1080);
  const gw = Math.max(960, cfg.gridW || 960);
  const gh = Math.max(540, cfg.gridH || 540);
  out.dims = {
    player: thumbTargetDims(srcW, srcH, pw, ph),
    grid: thumbTargetDims(srcW, srcH, gw, gh),
  };
  return out;
}

// ---------------------------------------------------------------- vthumbs

/**
 * Plano da montagem — mesmas regras do extract_video_vthumbs_hq():
 * skip 1 s de cada ponta, cortes de 2 s, até 8, espaçados igualmente.
 */
export function planVthumbsMontage(duration) {
  const segLen = 2.0;
  const skip = 1.0;
  const usable = Math.max(duration - skip * 2, 0.1);
  let segs = Math.min(8, Math.max(1, Math.floor(usable / segLen)));
  let starts = [];
  if (segs <= 1) {
    segs = 1;
    const len = Math.max(0.5, duration - skip * 2);
    starts = [Math.max(0, (duration - len) / 2)];
    return { segs, segLen: len, starts };
  }
  const spacing = (usable - segLen) / (segs - 1);
  for (let i = 0; i < segs; i++) starts.push(skip + i * spacing);
  return { segs, segLen, starts };
}

/**
 * Composição do quadro da montagem (landscape: cover center-crop; portrait:
 * cena central nítida sobre backdrop full-bleed desfocado) — espelha o filtro.
 */
function composePreviewFrame(srcCanvas, clipW, clipH, portrait) {
  const out = new OffscreenCanvas(clipW, clipH);
  const ctx = out.getContext('2d');
  const sw = srcCanvas.width;
  const sh = srcCanvas.height;
  if (portrait) {
    // bg: cover full-bleed + blur
    const bgs = Math.max(clipW / sw, clipH / sh);
    ctx.save();
    ctx.filter = 'blur(20px)';
    ctx.drawImage(srcCanvas, (clipW - sw * bgs) / 2, (clipH - sh * bgs) / 2, sw * bgs, sh * bgs);
    ctx.restore();
    // fg: contain, centralizado
    const fgs = Math.min(clipW / sw, clipH / sh);
    ctx.drawImage(srcCanvas, (clipW - sw * fgs) / 2, (clipH - sh * fgs) / 2, sw * fgs, sh * fgs);
  } else {
    const s = Math.max(clipW / sw, clipH / sh);
    ctx.drawImage(srcCanvas, (clipW - sw * s) / 2, (clipH - sh * s) / 2, sw * s, sh * s);
  }
  return out;
}

/**
 * Codifica a montagem num formato (avc/mp4 ou vp9/webm), 30 fps CFR, sem áudio.
 */
async function encodeMontage(blob, plan, clipW, clipH, portrait, codec, outFormatCls, hook) {
  const input = new Input({ formats: ALL_FORMATS, source: new BlobSource(blob) });
  const track = await input.getPrimaryVideoTrack();
  const sink = new CanvasSink(track); // resolução nativa
  const fps = 30;
  const totalDur = plan.segs * plan.segLen;
  const framesN = Math.max(1, Math.round(totalDur * fps));
  const canvas = new OffscreenCanvas(clipW, clipH);

  const output = new Output({ format: new outFormatCls(), target: new BufferTarget() });
  const source = new CanvasSource(canvas, { codec, quality: new Quality('high') });
  output.addVideoTrack(source, { frameRate: fps });
  await output.start();

  const sourceOf = (outIdx) => {
    const t = outIdx / fps; // timeline de saída contígua
    const segIdx = Math.min(plan.segs - 1, Math.floor(t / plan.segLen));
    const inner = t - segIdx * plan.segLen;
    return plan.starts[segIdx] + inner;
  };

  for (let i = 0; i < framesN; i++) {
    if (hook && hook.cancelled) throw makeCancelError();
    const r = await sink.getCanvas(sourceOf(i));
    const composed = composePreviewFrame(r.canvas, clipW, clipH, portrait);
    // desenha o quadro composto no canvas da fonte (conteúdo no add())
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, clipW, clipH);
    ctx.drawImage(composed, 0, 0);
    await source.add(i / fps, 1 / fps);
    if (hook && hook.onProgress) hook.onProgress((i + 1) / framesN);
  }
  await output.finalize();
  if (!output.target.buffer || output.target.buffer.byteLength === 0) return null;
  return new Blob([output.target.buffer], { type: codec === 'avc' ? 'video/mp4' : 'video/webm' });
}

function makeCancelError() {
  const e = new Error('Cancelado');
  e.name = 'ConversionCanceledError';
  return e;
}

/**
 * Gera video.mp4 (avc) e video.webm (vp9) a partir do arquivo processado.
 * @param {Blob} blob
 * @param {{clipW:number, clipH:number}} cfg
 * @param {{cancelled?:boolean,onProgress?:Function,onLog?:Function}} hook
 */
export async function buildVthumbs(blob, cfg, hook = {}) {
  const input = new Input({ formats: ALL_FORMATS, source: new BlobSource(blob) });
  const track = await input.getPrimaryVideoTrack();
  const duration = await input.computeDuration();
  const srcW = await track.getDisplayWidth();
  const srcH = await track.getDisplayHeight();
  const portrait = srcH > srcW;
  const plan = planVthumbsMontage(duration);
  // Piso do clip de hover (960x540) — mesmo floor do AVS.
  cfg = {
    clipW: Math.max(960, Math.floor((cfg.clipW || 960) / 2) * 2),
    clipH: Math.max(540, Math.floor((cfg.clipH || 540) / 2) * 2),
  };

  if (hook.onLog) hook.onLog(`vthumbs: montagem ${plan.segs} corte(s) de ${plan.segLen}s (total ~${(plan.segs * plan.segLen).toFixed(1)}s) · fonte ${srcW}x${srcH}${portrait ? ' (retrato)' : ''}`);

  let mp4 = null;
  let webm = null;
  try {
    mp4 = await encodeMontage(blob, plan, cfg.clipW, cfg.clipH, portrait, 'avc', Mp4OutputFormat, hook);
  } catch (err) {
    if (hook.onLog) hook.onLog(`vthumbs mp4 falhou: ${err && err.message ? err.message : err}`);
  }
  try {
    webm = await encodeMontage(blob, plan, cfg.clipW, cfg.clipH, portrait, 'vp9', WebMOutputFormat, hook);
  } catch (err) {
    if (hook.onLog) hook.onLog(`vthumbs webm(vp9) falhou: ${err && err.message ? err.message : err}`);
  }

  return {
    mp4,
    webm,
    plan,
    portrait,
    src: { w: srcW, h: srcH, duration },
  };
}
