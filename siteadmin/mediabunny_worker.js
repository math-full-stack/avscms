/*
 * Mediabunny — Fase 7: Web Worker ESM que executa o pipeline pesado
 * (escada de conversão + thumbs + vthumbs) fora da main thread.
 *
 * Protocolo (postMessage com objetos simples; nenhuma função atravessa):
 *
 *   → { type:'run', jobId, job }            roda o pipeline (escada → thumbs → vthumbs)
 *   → { type:'cancel' }                     cancela o job em andamento
 *
 *   ← { type:'START',   jobId }
 *   ← { type:'PHASE',   jobId, phase, message }        fase trocada (convert|thumbs|vthumbs)
 *   ← { type:'PROGRESS', jobId, phase, value }         value 0..1 dentro da fase
 *   ← { type:'LOG',     jobId, message }
 *   ← { type:'COMPLETE', jobId, results, thumbs, preview, src }  (blobs: buffers transferidos)
 *   ← { type:'CANCELLED', jobId, message }
 *   ← { type:'ERROR',   jobId, message, stack }
 *
 * job = {
 *   file: Blob,                       // arquivo de vídeo (structured clone)
 *   fileName: string,
 *   ladder: [encoding rows…],         // normalizadas via normalizeLadder no main
 *   opts: { qualityMode, audioMode },
 *   watermark: {                     // opcional; logo enviado como Blob (não DOM)
 *     logoBlob: Blob, logoW: number, logoH: number,
 *     width: number, opacity: number, margin: number,
 *     positions: [{pos, dur}…]
 *   },
 *   thumbs: {                        // config vinda do servidor (window.AVS_THUMB_CFG)
 *     playerW, playerH, gridW, gridH,
 *     removeBb, keepAr, sharpen, vthumbs, clipW, clipH
 *   }
 * }
 *
 * As classes do mediabunny vêm do mesmo bundle CDN usado pela UI/player
 * (importmap não se aplica a worker — import direto, mesma URL pinada 1.55.6).
 */
import {
  ALL_FORMATS,
  BlobSource,
  Input,
} from 'https://cdn.jsdelivr.net/npm/mediabunny@1.55.6/dist/bundles/mediabunny.min.mjs';

import {
  runLadder,
} from './mediabunny_engine.js';

import {
  buildVthumbs,
  extractFrameSet,
} from './mediabunny_thumbnails.js';

// ---------------------------------------------------------------- estado
let current = null; // { jobId, token, phase }

function post(msg, transfer) {
  const full = { ...msg, jobId: current && current.jobId };
  if (transfer && transfer.length) {
    self.postMessage(full, transfer);
  } else {
    self.postMessage(full);
  }
}

// ---------------------------------------------------------------- helpers

/** Blob → ArrayBuffer transferível; nulo vira null (sem transfer). */
async function toTransferable(blob) {
  if (!blob) return null;
  return { buffer: await blob.arrayBuffer(), type: blob.type || 'application/octet-stream' };
}

/** Converte resultado por rung: Blob → {buffer, type} transferível. */
async function serializeResult(r) {
  return {
    label: r.label,
    row: r.row,
    meta: r.meta,
    blob: await toTransferable(r.blob),
  };
}

// ---------------------------------------------------------------- pipeline

async function runJob(jobId, job) {
  const token = { cancelled: false, _conversion: null };
  current = { jobId, token, phase: 'convert' };

  post({ type: 'START' });

  const log = (m) => post({ type: 'LOG', message: m });
  const phase = (p, message) => {
    current.phase = p;
    post({ type: 'PHASE', phase: p, message: message || '' });
  };

  try {
    // ---- escada (decode → encode → MP4 por rung) ------------------------
    phase('convert', `Convertendo ${job.fileName}…`);
    const ladderRun = normalizeLadderFrom(job.ladder);

    const watermark = await buildWatermark(job.watermark, log);

    const results = await runLadder(job.file, ladderRun, {
      qualityMode: job.opts.qualityMode,
      audioMode: job.opts.audioMode,
      watermark,
    }, token, {
      log,
      progress: (p) => post({ type: 'PROGRESS', phase: 'convert', value: p }),
    });

    // ---- thumbs (default.jpg + 1..20.jpg) -------------------------------
    let thumbs = null;
    if (results.length && job.thumbs && job.thumbs.enabled) {
      phase('thumbs', 'Gerando thumbs (default.jpg + 1..20.jpg)…');
      const srcBlob = results[0].blob; // maior/1º formato gerado, como o AVS postThumbs
      const cfg = {
        playerW: job.thumbs.playerW || 1920,
        playerH: job.thumbs.playerH || 1080,
        gridW: job.thumbs.gridW || 960,
        gridH: job.thumbs.gridH || 540,
        removeBb: !!job.thumbs.removeBb,
        keepAr: !!job.thumbs.keepAr,
        sharpen: true,
        vthumbs: !!job.thumbs.vthumbs,
        clipW: job.thumbs.clipW || 960,
        clipH: job.thumbs.clipH || 540,
      };
      const frames = await extractFrameSet(srcBlob, cfg);
      thumbs = {
        default: { seek: frames.default.seek, jpeg: await toTransferable(frames.default.blob) },
        frames: await Promise.all(frames.frames.map(async (f) => ({
          n: f.n, seek: f.seek, jpeg: await toTransferable(f.blob),
        }))),
        src: frames.src,
        dims: frames.dims,
        srcLabel: results[0].label,
      };
      log(`Thumbs prontas: default em ${frames.default.seek.toFixed(2)}s + ${frames.frames.length} frames (${frames.dims.grid.w}x${frames.dims.grid.h}).`);
    }

    // ---- vthumbs (video.mp4 / video.webm) -------------------------------
    let preview = null;
    if (results.length && job.thumbs && job.thumbs.vthumbs) {
      phase('vthumbs', 'Gerando video.mp4 / video.webm (montagem de cortes)…');
      const srcBlob = results[0].blob;
      const p = await buildVthumbs(srcBlob, { clipW: job.thumbs.clipW, clipH: job.thumbs.clipH }, {
        cancelled: token.cancelled,
        onLog: (m) => log(m),
        onProgress: (v) => post({ type: 'PROGRESS', phase: 'vthumbs', value: v }),
      });
      if (token.cancelled) throw makeCancelError();
      preview = {
        mp4: await toTransferable(p.mp4),
        webm: await toTransferable(p.webm),
        plan: p.plan,
        portrait: p.portrait,
        src: p.src,
      };
    }

    // ---- resposta: converte Blobs em buffers transferíveis --------------
    const transfer = [];
    const serialized = await Promise.all(results.map(async (r) => {
      const sr = await serializeResult(r);
      if (sr.blob) transfer.push(sr.blob.buffer);
      return sr;
    }));

    const defaultJpeg = thumbs ? thumbs.default.jpeg : null;
    if (defaultJpeg) transfer.push(defaultJpeg);
    const jpegs = thumbs ? thumbs.frames.map((f) => f.jpeg) : [];
    jpegs.forEach((j) => transfer.push(j));
    const mp4Buf = preview ? preview.mp4.buffer : null;
    if (mp4Buf) transfer.push(mp4Buf);
    const webmBuf = preview ? preview.webm.buffer : null;
    if (webmBuf) transfer.push(webmBuf);

    post({
      type: 'COMPLETE',
      results: serialized,
      thumbs: thumbs ? {
        default: { seek: thumbs.default.seek, jpeg: defaultJpeg },
        frames: thumbs.frames.map((f) => ({ n: f.n, seek: f.seek, jpeg: f.jpeg })),
        src: thumbs.src,
        dims: thumbs.dims,
        srcLabel: thumbs.srcLabel,
      } : null,
      preview,
    }, transfer);
  } catch (err) {
    const cancelled = err && (err.name === 'ConversionCanceledError' || /cancel/i.test(String(err && err.message)));
    if (cancelled) {
      post({ type: 'CANCELLED', message: 'Pipeline cancelado.' });
    } else {
      post({ type: 'ERROR', message: err && err.message ? err.message : String(err), stack: err && err.stack });
    }
  } finally {
    current = null;
  }
}

function normalizeLadderFrom(ladder) {
  // O main já envia linhas da escada com a última marcada como lq; re-normaliza
  // para garantir (mesma semântica de normalizeLadder da engine).
  const rows = (ladder || []).map((r) => ({ ...r }));
  if (rows.length) rows[rows.length - 1].lq = true;
  return rows;
}

/** Converte o logo (Blob) em ImageBitmap dentro do worker + monta o perfil. */
async function buildWatermark(wm, log) {
  if (!wm || !wm.logoBlob) return null;
  try {
    const bitmap = await createImageBitmap(wm.logoBlob);
    log(`Logo decodificado no worker: ${bitmap.width}x${bitmap.height}.`);
    return {
      image: bitmap,
      width: wm.width,
      opacity: wm.opacity,
      margin: wm.margin,
      positions: wm.positions,
    };
  } catch (err) {
    log(`Logo inválido no worker, prosseguindo sem marca d'água: ${err && err.message ? err.message : err}`);
    return null;
  }
}

function makeCancelError() {
  const e = new Error('Pipeline cancelado.');
  e.name = 'ConversionCanceledError';
  return e;
}

// ---------------------------------------------------------------- messages

self.addEventListener('message', (ev) => {
  const msg = ev.data;
  if (!msg || typeof msg !== 'object') return;

  if (msg.type === 'run') {
    if (current) {
      // A main thread serializa jobs (nunca envia run com um em andamento).
      // Se chegar um segundo run, recusa em vez de corromper o estado (o job
      // anterior ainda poderia emitir CANCELLED sob o jobId do novo).
      post({ type: 'ERROR', message: 'Worker ocupado com outro job — recarregue a página e rode de novo.' });
      return;
    }
    runJob(msg.jobId, msg.job);
  } else if (msg.type === 'cancel') {
    if (current) {
      current.token.cancelled = true;
      if (current.token._conversion) {
        current.token._conversion.cancel().catch(() => {});
      }
    }
  }
});
