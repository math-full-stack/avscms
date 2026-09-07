/*
 * Mediabunny engine — Fase 4 (paridade com o pipeline FFmpeg do AVS).
 *
 * Espelha, no navegador, as decisões do pipeline descritas em
 * especificacao_pipeline_conversao.md:
 *
 *  1. Escada: tabela `encoding` (status=1, ORDER BY height DESC), última linha
 *     com flag `lq` — mesmas regras de elegibilidade (nunca upscale, exceto lq)
 *     e re-rotulagem lq (HD/SD) de function_conversion_fp.php.
 *  2. Enquadramento: regra DAR>4/3 (width fixa; senão height fixa, proporção
 *     preservada) equivalente a scale=...if(gt(dar,4/3),W,-2)... e à
 *     normalização de SAR via display dimensions (sem upscale).
 *  3. Áudio: copiar trilha quando o container/codec aceitar; transcodar AAC
 *     128 kbps quando necessário/forçado; descartar (com aviso) se o ambiente
 *     não puder codificar.
 *  4. Codec/faststart: saída avc + MP4 (Mp4OutputFormat com relocação do moov
 *     — validar equivalência ao -movflags +faststart no player).
 *  5. Watermark com AGENDA de posições: perfil {opacity, size(px fixo), margin,
 *     positions[{pos,dur}]}, ciclo C=Σdur e troca instantânea no timestamp da
 *     amostra — mesmo resultado das expressões if(lt(mod(t,C),acc)) do AVS.
 *
 * A engine não toca DOM nem fila; recebe encodings do servidor (PHP) e devolve
 * resultados por formato. Cada formato é produzido numa execução própria de
 * Conversion (re-decode por rung; otimização single-decode fica p/ fases de
 * worker/integração).
 */
import {
  ALL_FORMATS,
  BlobSource,
  BufferTarget,
  Conversion,
  Input,
  Mp4OutputFormat,
  Output,
  Quality,
} from 'https://cdn.jsdelivr.net/npm/mediabunny@1.55.6/dist/bundles/mediabunny.min.mjs';

// ---------------------------------------------------------------- util

/**
 * Probe mínimo de entrada — campos equivalentes ao ffprobe do AVS.
 * @param {Input} input
 */
export async function probeInput(input) {
  const out = {
    duracao: await input.computeDuration().catch(() => null),
    video: null,
    audio: null,
  };
  const v = await input.getPrimaryVideoTrack();
  if (v) {
    const [w, h] = await Promise.all([v.getDisplayWidth(), v.getDisplayHeight()]);
    let rot = 0;
    let fps = null;
    try { rot = await v.getRotation(); } catch { /* sem rotação */ }
    try { fps = (await v.computeFrameRateMetrics()).bestGuessFrameRate; } catch { /* VFR */ }
    out.video = {
      codec: v.codec, w, h, rot, fps,
      decodavel: await v.canDecode(),
    };
  }
  const a = await input.getPrimaryAudioTrack();
  if (a) {
    out.audio = {
      codec: a.codec,
      canais: await a.getNumberOfChannels().catch(() => null),
      taxa: await a.getSampleRate().catch(() => null),
      decodavel: await a.canDecode(),
    };
  }
  return out;
}

// ---------------------------------------------------------------- escada

/**
 * Normaliza as linhas vindas do servidor (mesma semântica de getEncodings()):
 * marca a última linha com lq=true.
 * @param {Array<object>} encodings linhas da tabela `encoding` (status=1, já ordenadas)
 */
export function normalizeLadder(encodings) {
  const rows = (encodings || []).map((r) => ({ ...r }));
  if (rows.length) {
    rows[rows.length - 1].lq = true;
  }
  return rows;
}

/**
 * Elegibilidade por formato — espelha
 * `(e.height <= srcH || e.width <= srcW) || e.lq` e a re-rotulagem lq
 * (HD quando height >= 480, senão SD) quando o alvo lq é maior que a fonte.
 * @param {object} e linha de encoding
 * @param {number} srcW
 * @param {number} srcH
 * @returns {{eligible:boolean, label:string}}
 */
export function rowEligibility(e, srcW, srcH) {
  const lq = !!e.lq;
  let eligible = lq || (Number(e.height) <= srcH || Number(e.width) <= srcW);
  let label = String(e.label || '');
  if (eligible && lq && (Number(e.height) > srcH || Number(e.width) > srcW)) {
    label = (Number(e.height) >= 480) ? 'HD' : 'SD';
  }
  return { eligible, label };
}

/**
 * Dimensões-alvo "esperadas" do enquadramento AVS (sem upscale e com a regra
 * DAR>4/3), p/ exibição/comparação. Altura/largura complementar é proporcional
 * e arredondada p/ par (o encoder WebCodecs/avc também entrega pares).
 * @param {object} e linha de encoding (height/width)
 * @param {number} srcW
 * @param {number} srcH
 */
export function expectedBox(e, srcW, srcH) {
  if (!srcW || !srcH) return { w: Number(e.width) || 0, h: Number(e.height) || 0 };
  const W = Number(e.width), H = Number(e.height);
  const dar = srcW / srcH;
  let outW, outH;
  if (dar > 4 / 3) {      // largura fixa W, altura proporcional
    outW = W;
    outH = Math.round(W / dar);
  } else {                // altura fixa H, largura proporcional
    outH = H;
    outW = Math.round(H * dar);
  }
  const even = (n) => (n % 2 ? n - 1 : n);
  return { w: Math.max(2, even(outW)), h: Math.max(2, even(outH)) };
}

// ---------------------------------------------------------------- options

/**
 * Opções de vídeo da Conversion p/ um rung da escada, com enquadramento AVS.
 * @param {object} e linha elegível
 * @param {number} srcW
 * @param {number} srcH
 * @param {{high:'high'|'medium'|'low'}} qualityMode qualidade base (crf->quality é Fase 5+)
 */
export function videoOptionsForRow(e, srcW, srcH, qualityMode = 'high') {
  const opts = {
    codec: 'avc',
    quality: new Quality(qualityMode),
    // Espelha o autorotate do ffmpeg: converte a orientação em pixels em vez
    // de manter a tag de rotação no MP4 de saída. Sem rotação = sem efeito.
    allowRotationMetadata: false,
  };
  const { w: boxW, h: boxH } = expectedBox(e, srcW, srcH);
  const dar = srcW / srcH;
  if (dar > 4 / 3) {
    opts.width = boxW;
  } else {
    opts.height = boxH;
  }
  return opts;
}

/**
 * Política de áudio:
 *  - 'copy':    deixa a engine copiar/transcodar (default) — nada a forçar;
 *  - 'aac128':  força transcode AAC 128 kbps (equivale ao retry da SP);
 *  - 'discard': remove a trilha de áudio.
 * Sempre retorna undefined p/ 'copy' (engine decide: copia se possível).
 * @param {string} mode
 * @param {{codec?:string}} audioInfo trilha de áudio da entrada (opcional)
 */
export function audioOptionsFor(mode, audioInfo) {
  if (mode === 'discard') return { discard: true };
  if (mode === 'aac128') return { codec: 'aac', quality: new Quality({ bitrate: 128000 }) };
  // copy: AAC existente → cópia pura (nada a forçar). Qualquer outro codec é
  // transcodado p/ AAC 128 kbps — codec-alvo do MP4 no pipeline AVS (SP usa
  // -c:a copy p/ AAC e aac 128k no retry). Se o ambiente não codificar AAC, a
  // trilha é descartada e aparece em conversion.discardedTracks.
  if (audioInfo && audioInfo.codec === 'aac') return undefined;
  return { codec: 'aac', quality: new Quality({ bitrate: 128000 }) };
}

/**
 * Posições válidas — mesma lista de wm_valid_positions() do AVS.
 */
export const WM_POSITIONS = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'];

/**
 * x/y de uma posição — espelha wm_xy() do AVS (margem nas bordas, centro
 * exato). As coordenadas podem ser fracionárias (centro); o canvas lida bem.
 * @param {string} pos
 * @param {number} mainW
 * @param {number} mainH
 * @param {number} ovW
 * @param {number} ovH
 * @param {number} margin
 * @returns {[number, number]}
 */
export function wmPositionXY(pos, mainW, mainH, ovW, ovH, margin) {
  switch (pos) {
    case 'top-left':    return [margin, margin];
    case 'top-right':   return [mainW - ovW - margin, margin];
    case 'bottom-left': return [margin, mainH - ovH - margin];
    case 'center':      return [(mainW - ovW) / 2, (mainH - ovH) / 2];
    case 'bottom-right':
    default:            return [mainW - ovW - margin, mainH - ovH - margin];
  }
}

/**
 * Normaliza a agenda de posições com a MESMA semântica de wm_normalize():
 *  - 1 posição (qualquer dur, inclusive 0) → fixa o vídeo todo;
 *  - várias posições com dur>0 → ciclo C=Σdur, troca instantânea a cada dur;
 *  - várias posições com ciclo total 0 → inválido (null), como no AVS;
 *  - posições desconhecidas são ignoradas.
 * @param {Array<{pos?:string, dur?:number}>} positions
 * @returns {Array<{pos:string, dur:number}>|null}
 */
export function normalizeWmSchedule(positions) {
  if (!Array.isArray(positions) || !positions.length) return null;
  const list = [];
  for (const row of positions) {
    if (!row || typeof row !== 'object') continue;
    const pos = String(row.pos || '').trim();
    if (!WM_POSITIONS.includes(pos)) continue;
    const dur = Math.max(0, Math.round(Number(row.dur) || 0));
    list.push({ pos, dur });
  }
  if (!list.length) return null;
  const cycle = list.reduce((acc, r) => acc + r.dur, 0);
  if (list.length > 1 && cycle <= 0) return null;
  return list;
}

/**
 * Índice ativo no instante t — equivale às expressões aninhadas do AVS
 * if(lt(mod(t,C),acc_i), pos_i, ...): posição i vale em [acc_{i-1}, acc_i).
 * Linhas com dur=0 (janela vazia) nunca são escolhidas, como no filtro.
 * @param {Array<{pos:string,dur:number}>} schedule
 * @param {number} cycle
 * @param {number} t segundos (timestamp da amostra)
 */
export function wmActiveIndex(schedule, cycle, t) {
  if (schedule.length === 1 || cycle <= 0) return 0;
  const m = ((t % cycle) + cycle) % cycle;
  let acc = 0;
  for (let i = 0; i < schedule.length; i++) {
    acc += schedule[i].dur;
    if (m < acc) return i;
  }
  return schedule.length - 1;
}

/**
 * Largura/altura intrínsecas de uma imagem — aceita HTMLImageElement (DOM,
 * via naturalWidth/naturalHeight) ou ImageBitmap (worker, via width/height).
 * @param {HTMLImageElement|ImageBitmap} img
 */
export function intrinsicSize(img) {
  if (img.naturalWidth) return [img.naturalWidth, img.naturalHeight];
  return [img.width, img.height];
}

/**
 * Cria o process() de overlay da marca d'água (perfil AVS): largura FIXA em px
 * (par), margem, opacidade e AGENDA de posições com ciclo. O canvas recorta
 * sozinho overlays que invadam as bordas — mesmo efeito do overlay do ffmpeg.
 * @param {HTMLImageElement|ImageBitmap} logo aceito tanto na UI (Image) quanto no worker (ImageBitmap)
 * @param {{width?:number, opacity?:number, margin?:number, positions?:Array<{pos?:string,dur?:number}>}} cfg
 */
export function watermarkProcessFor(logo, cfg) {
  if (!logo || !cfg) return null;
  let lw = Math.max(8, Math.round(cfg.width || 120));
  if (lw % 2) lw++; // dimensão par, como o AVS garante
  const opacity = Math.max(0, Math.min(100, cfg.opacity == null ? 60 : cfg.opacity)) / 100;
  const margin = Math.max(0, cfg.margin == null ? 12 : cfg.margin);
  const schedule = normalizeWmSchedule(cfg.positions) || [{ pos: 'bottom-right', dur: 0 }];
  const cycle = schedule.reduce((acc, r) => acc + r.dur, 0);
  const multi = schedule.length > 1 && cycle > 0;
  const [logoW, logoH] = intrinsicSize(logo);
  return (sample) => {
    const cw = sample.displayWidth;
    const ch = sample.displayHeight;
    const canvas = new OffscreenCanvas(cw, ch);
    const ctx = canvas.getContext('2d');
    sample.draw(ctx, 0, 0);
    const lh = Math.round((logoH / logoW) * lw);
    const idx = multi ? wmActiveIndex(schedule, cycle, sample.timestamp || 0) : 0;
    const [x, y] = wmPositionXY(schedule[idx].pos, cw, ch, lw, lh, margin);
    ctx.globalAlpha = opacity;
    ctx.drawImage(logo, x, y, lw, lh);
    ctx.globalAlpha = 1;
    return canvas;
  };
}

// ---------------------------------------------------------------- runner

/**
 * Token de cancelamento compartilhado com a UI.
 */
export function makeCancelToken() {
  return { cancelled: false, _conversion: null };
}

/**
 * Executa a escada completa sobre um File.
 *
 * @param {File} file
 * @param {Array<object>} ladder linhas normalizadas (normalizeLadder)
 * @param {object} opts
 * @param {'high'|'medium'|'low'} [opts.qualityMode]
 * @param {'copy'|'aac128'|'discard'} [opts.audioMode]
 * @param {{image:HTMLImageElement,width:number,opacity:number,margin:number}|null} [opts.watermark]
 * @param {object} token cancelToken (makeCancelToken)
 * @param {(msg:string)=>void} [hooks.log]
 * @param {(overall:number)=>void} [hooks.progress] 0..1 global
 * @returns {Promise<Array<{row:object, meta:object, blob:Blob}>>}
 */
export async function runLadder(file, ladder, opts = {}, token, hooks = {}) {
  const log = hooks.log || (() => {});
  const progress = hooks.progress || (() => {});

  const probeInput_ = new Input({ formats: ALL_FORMATS, source: new BlobSource(file) });
  const src = await probeInput(probeInput_);
  if (!src.video || !src.video.decodavel) {
    throw new Error(`Trilha de vídeo não decodificável (${src.video ? src.video.codec : 'sem vídeo'}). O FFmpeg cobriria (fallback).`);
  }

  const rows = ladder
    .map((e) => ({ e, elig: rowEligibility(e, src.video.w, src.video.h) }))
    .filter((r) => r.elig.eligible);

  if (!rows.length) {
    throw new Error('Nenhum formato da escada é elegível para esta fonte.');
  }
  log(`Escada: ${rows.length} formato(s) elegível(is) de ${ladder.length} — fonte ${src.video.w}x${src.video.h} ${src.video.codec}${src.video.rot ? ` rot ${src.video.rot}°` : ''}.`);

  const results = [];
  for (let i = 0; i < rows.length; i++) {
    if (token.cancelled) throw makeCancelled();
    const { e, elig } = rows[i];
    const tag = `${elig.label}`;
    log(`[${i + 1}/${rows.length}] ${tag}: iniciando (target ${e.width}x${e.height}, crf ${e.crf}, preset ${e.preset})…`);

    // Input fresco por rung (leitura sequencial independente; a probe acima é
    // descartada). Cada formato = decode completo, como uma execução FP/SP.
    const input = new Input({ formats: ALL_FORMATS, source: new BlobSource(file) });
    const output = new Output({ format: new Mp4OutputFormat(), target: new BufferTarget() });
    const convOpts = { input, output };

    const vOpts = videoOptionsForRow(e, src.video.w, src.video.h, opts.qualityMode);
    if (opts.watermark) {
      const proc = watermarkProcessFor(opts.watermark.image, opts.watermark);
      if (proc) vOpts.process = proc;
    }
    convOpts.video = vOpts;

    const aOpts = audioOptionsFor(opts.audioMode || 'copy', src.audio);
    if (aOpts) convOpts.audio = aOpts;

    const conversion = await Conversion.init(convOpts);
    token._conversion = conversion;

    if (!conversion.isValid) {
      const reasons = (conversion.discardedTracks || [])
        .map((d) => `${d.type} '${d.codec || ''}': ${d.reason || '?'}`)
        .join(' | ');
      log(`  ${tag}: trilhas descartadas → ${reasons || 'n/a'}`);
    }

    conversion.onProgress = (p) => {
      progress((i + p) / rows.length);
    };
    await conversion.execute();
    token._conversion = null;

    const buffer = output.target.buffer;
    if (!buffer || buffer.byteLength === 0) {
      log(`  ${tag}: saída vazia — formato ignorado.`);
      continue;
    }
    const blob = new Blob([buffer], { type: 'video/mp4' });

    // Metadados reais de saída (re-leitura).
    const outInput = new Input({ formats: ALL_FORMATS, source: new BlobSource(blob) });
    const meta = await probeInput(outInput);
    results.push({ row: e, label: elig.label, blob, meta });
    log(`  ${tag}: OK — ${(blob.size / 1048576).toFixed(2)} MB, vídeo ${meta.video ? `${meta.video.codec} ${meta.video.w}x${meta.video.h}` : 'n/a'}${meta.audio ? `, áudio ${meta.audio.codec}` : ', sem áudio'}.`);
    progress((i + 1) / rows.length);
  }

  return results;
}

function makeCancelled() {
  const err = new Error('Conversão cancelada.');
  err.name = 'ConversionCanceledError';
  return err;
}
