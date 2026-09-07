/*
 * PoC Fase 7 — wiring da página siteadmin/mediabunny_poc.php.
 *
 * A main thread ficou FINA: só UI/DOM e o protocolo com o worker. Todo o
 * trabalho pesado (escada + thumbs + vthumbs) roda dentro de
 * mediabunny_worker.js, que importa mediabunny_engine.js (regras de paridade)
 * e mediabunny_thumbnails.js (Fase 6).
 *
 * Protocolo (ver mediabunny_worker.js):
 *   → { type:'run', jobId, job }
 *   → { type:'cancel' }
 *   ← START / PHASE / PROGRESS / LOG / COMPLETE / CANCELLED / ERROR
 */
import {
  ALL_FORMATS,
  BlobSource,
  Input,
} from 'https://cdn.jsdelivr.net/npm/mediabunny@1.55.6/dist/bundles/mediabunny.min.mjs';

import {
  normalizeLadder,
  normalizeWmSchedule,
  probeInput,
  rowEligibility,
  WM_POSITIONS,
} from './mediabunny_engine.js';

const $ = (id) => document.getElementById(id);

const els = {
  fVideo: $('fVideo'),
  srcInfo: $('srcInfo'),
  ladderBody: $('ladderBody'),
  selQuality: $('selQuality'),
  selAudio: $('selAudio'),
  chkWM: $('chkWM'),
  wmOpts: $('wmOpts'),
  fLogo: $('fLogo'),
  wmW: $('wmW'),
  wmOp: $('wmOp'),
  wmOpVal: $('wmOpVal'),
  wmMg: $('wmMg'),
  wmRows: $('wmRows'),
  btnWmAdd: $('btnWmAdd'),
  chkThumbs: $('chkThumbs'),
  btnRun: $('btnRun'),
  btnCancel: $('btnCancel'),
  status: $('status'),
  barFill: $('barFill'),
  log: $('log'),
  results: $('results'),
  thumbsCard: $('thumbsCard'),
  thumbsStatus: $('thumbsStatus'),
  thumbsOut: $('thumbsOut'),
};

let thumbCfg = window.AVS_THUMB_CFG || {};

let ladder = [];
let srcMeta = null;         // probe do arquivo escolhido
let fileBlob = null;        // File/Blob atual (referência p/ o job)
let fileStem = 'video';
let logoImage = null;       // HTMLImageElement (visualização) + dims do logo
let logoDims = null;        // {w,h} do logo (enviado ao worker com o blob)
let resultUrls = [];
let thumbUrls = [];
let lastResults = [];       // rungs com {label, blob, meta}
let lastThumbs = null;      // {default:{seek,blob}, frames:[{n,seek,blob}], dims, srcLabel}
let lastPreview = null;     // {mp4:Blob|null, webm:Blob|null}
let busy = false;

// ---------------------------------------------------------------- worker
let jobSeq = 0;

const worker = new Worker('./mediabunny_worker.js', { type: 'module' });

// (Re)construída a cada job; armazena o valor de value por fase para a barra.
let phaseValue = { convert: 0, thumbs: 0, vthumbs: 0 };
let phaseLabel = { convert: 'Convertendo escada', thumbs: 'Gerando thumbs', vthumbs: 'Gerando previews' };

worker.addEventListener('message', (ev) => {
  const msg = ev.data;
  if (!msg || typeof msg !== 'object' || !msg.type) return;

  switch (msg.type) {
    case 'START':
      setStatus('Iniciando pipeline no worker…', '');
      break;

    case 'PHASE':
      phaseValue[msg.phase] = 0;
      setStatus(`${phaseLabel[msg.phase] || msg.phase}…`, '');
      if (msg.message) log(msg.message);
      break;

    case 'PROGRESS': {
      phaseValue[msg.phase] = msg.value;
      setProgress(overallProgress());
      setStatus(`${phaseLabel[msg.phase] || msg.phase}… ${(overallProgress() * 100).toFixed(1)}%`, '');
      break;
    }

    case 'LOG':
      log(msg.message);
      break;

    case 'COMPLETE':
      onComplete(msg);
      break;

    case 'CANCELLED':
      setStatus(msg.message || 'Pipeline cancelado.', 'warn');
      log('Pipeline cancelado pelo usuário.');
      break;

    case 'ERROR':
      console.error('Worker error:', msg);
      setStatus(`Erro no worker: ${msg.message || 'desconhecido'}`, 'err');
      log(`ERRO (worker): ${msg.stack || msg.message}`);
      break;

    default:
      break;
  }
  if (['COMPLETE', 'CANCELLED', 'ERROR'].includes(msg.type)) {
    busy = false;
    els.btnRun.disabled = false;
    els.btnCancel.style.display = 'none';
  }
});

function overallProgress() {
  // Pesos fixos por fase (1 rung por fase convert já vem normalizado 0..1).
  const w = { convert: 0.5, thumbs: 0.25, vthumbs: 0.25 };
  let p = 0;
  for (const [k, v] of Object.entries(w)) p += (phaseValue[k] || 0) * v;
  return p;
}

function sendJob(job) {
  if (busy) return;
  busy = true;
  els.btnRun.disabled = true;
  els.btnCancel.style.display = 'inline-block';
  setProgress(0);
  phaseValue = { convert: 0, thumbs: 0, vthumbs: 0 };
  clearOutputs();
  const jobId = ++jobSeq;
  worker.postMessage({ type: 'run', jobId, job });
}

function cancelJob() {
  worker.postMessage({ type: 'cancel' });
  setStatus('Cancelando… (aguardando o worker abortar a conversão)', 'warn');
}

// ---------------------------------------------------------------- util
function log(msg) {
  const t = new Date().toLocaleTimeString('pt-BR');
  els.log.textContent += `[${t}] ${msg}\n`;
  els.log.scrollTop = els.log.scrollHeight;
}

function setStatus(html, cls = '') {
  els.status.innerHTML = html;
  els.status.className = cls;
}

function setProgress(pct) {
  els.barFill.style.width = `${Math.max(0, Math.min(1, pct)) * 100}%`;
}

function fmtTime(sec) {
  if (!Number.isFinite(sec)) return '—';
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = Math.floor(sec % 60);
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function fmtMB(bytes) {
  return `${(bytes / 1048576).toFixed(2)} MB`;
}

function fmtBool(v, okTxt = 'OK', noTxt = 'indisponível') {
  return v ? `<span class="ok">${okTxt}</span>` : `<span class="err">${noTxt}</span>`;
}

// ---------------------------------------------------------------- ladder UI
function renderLadder() {
  if (!ladder.length) {
    els.ladderBody.innerHTML = '<tr><td colspan="6" class="warn">Nenhum encoding ativo na tabela `encoding`.</td></tr>';
    return;
  }
  els.ladderBody.innerHTML = '';
  ladder.forEach((row, idx) => {
    const tr = document.createElement('tr');
    tr.dataset.idx = idx;
    const check = document.createElement('input');
    check.type = 'checkbox';
    check.checked = true;
    check.dataset.idx = idx;
    tr.appendChild(cell(check));

    const lq = !!row.lq;
    const labelTd = cell(document.createTextNode(row.label));
    if (lq) labelTd.className = 'lq';
    tr.appendChild(labelTd);
    tr.appendChild(cell(document.createTextNode(`${row.width}x${row.height}`)));
    tr.appendChild(cell(document.createTextNode(row.crf)));
    tr.appendChild(cell(document.createTextNode(row.preset)));
    const sit = document.createElement('td');
    sit.dataset.role = 'sit';
    sit.textContent = '—';
    tr.appendChild(sit);
    els.ladderBody.appendChild(tr);
  });
}

function cell(child) {
  const td = document.createElement('td');
  td.appendChild(child);
  return td;
}

function refreshSituation() {
  if (!srcMeta || !srcMeta.video) {
    els.srcInfo.innerHTML = 'Escolha o arquivo para sondar a fonte.';
    els.srcInfo.className = 'warn';
    return;
  }
  const v = srcMeta.video;
  els.srcInfo.innerHTML =
    `Fonte: <b>${v.codec}</b> ${v.w}x${v.h}${v.rot ? ` (rotação ${v.rot}°)` : ''}${v.fps ? `, ~${v.fps.toFixed(3)} fps` : ''} · duração ${fmtTime(srcMeta.duracao)} · decode ${fmtBool(v.decodavel)}` +
    (srcMeta.audio ? ` · áudio <b>${srcMeta.audio.codec}</b>${srcMeta.audio.canais ? ` ${srcMeta.audio.canais}ch` : ''}${srcMeta.audio.taxa ? ` ${srcMeta.audio.taxa} Hz` : ''} decode ${fmtBool(srcMeta.audio.decodavel)}` : ' · <span class="warn">sem áudio</span>');
  els.srcInfo.className = '';

  ladder.forEach((row, idx) => {
    const tr = els.ladderBody.querySelector(`tr[data-idx="${idx}"]`);
    if (!tr) return;
    const check = tr.querySelector('input[type=checkbox]');
    const sit = tr.querySelector('td[data-role=sit]');
    const elig = rowEligibility(row, v.w, v.h);
    if (elig.eligible) {
      sit.innerHTML = `<span class="ok">gerar</span> · ${elig.label} → aprox. ${expectedBoxLabel(row, v.w, v.h)}`;
      check.disabled = false;
    } else {
      sit.innerHTML = '<span class="skip">pula (upscale — só o último lq roda)</span>';
      check.checked = false;
      check.disabled = true;
    }
  });
}

function expectedBoxLabel(row, w, h) {
  const dar = w / h;
  const W = Number(row.width), H = Number(row.height);
  let ow, oh;
  if (dar > 4 / 3) { ow = W; oh = Math.round(W / dar); }
  else { oh = H; ow = Math.round(H * dar); }
  const even = (n) => (n % 2 ? n - 1 : n);
  return `${Math.max(2, even(ow))}x${Math.max(2, even(oh))}`;
}

// ---------------------------------------------------------------- UI events
els.fVideo.addEventListener('change', async () => {
  const f = els.fVideo.files[0];
  if (!f) return;
  if (busy) cancelJob();
  fileBlob = f;
  fileStem = f.name.replace(/\.[^.]+$/, '');
  clearOutputs();
  try {
    log(`Arquivo: ${f.name} (${fmtMB(f.size)})`);
    const input = new Input({ formats: ALL_FORMATS, source: new BlobSource(f) });
    srcMeta = await probeInput(input);
    refreshSituation();
    log('Fonte sondada. Escada recalculada.');
  } catch (err) {
    srcMeta = null;
    log(`ERRO na sondagem: ${err && err.message ? err.message : err}`);
    setStatus('Não foi possível sondar o arquivo.', 'err');
  }
});

const WM_POS_LABELS = {
  'top-left': 'Superior esquerda',
  'top-right': 'Superior direita',
  'bottom-left': 'Inferior esquerda',
  'bottom-right': 'Inferior direita',
  center: 'Centro',
};

function addWmRow(pos = 'bottom-right', dur = 5) {
  const div = document.createElement('div');
  div.className = 'wmrow';

  const sel = document.createElement('select');
  WM_POSITIONS.forEach((p) => {
    const opt = document.createElement('option');
    opt.value = p;
    opt.textContent = WM_POS_LABELS[p] || p;
    if (p === pos) opt.selected = true;
    sel.appendChild(opt);
  });

  const inp = document.createElement('input');
  inp.type = 'number';
  inp.min = 0;
  inp.step = 1;
  inp.value = dur;
  inp.title = 'Duração em segundos (0 = até o fim do ciclo; 1 linha = fixa)';

  const rm = document.createElement('button');
  rm.type = 'button';
  rm.className = 'rm';
  rm.textContent = '✕';
  rm.title = 'Remover posição';
  rm.addEventListener('click', () => div.remove());

  div.append(sel, inp, rm);
  els.wmRows.appendChild(div);
}

function readWmRows() {
  const rows = [];
  els.wmRows.querySelectorAll('.wmrow').forEach((div) => {
    const sel = div.querySelector('select');
    const inp = div.querySelector('input');
    if (sel && inp) {
      rows.push({ pos: sel.value, dur: parseInt(inp.value, 10) || 0 });
    }
  });
  return rows;
}

els.chkWM.addEventListener('change', () => {
  els.wmOpts.style.display = els.chkWM.checked ? 'block' : 'none';
  if (els.chkWM.checked && !els.wmRows.children.length) {
    addWmRow('bottom-right', 5);
  }
});
els.btnWmAdd.addEventListener('click', () => addWmRow('top-right', 5));
els.wmOp.addEventListener('input', () => {
  els.wmOpVal.textContent = els.wmOp.value;
});
els.fLogo.addEventListener('change', async () => {
  const f = els.fLogo.files[0];
  if (!f) return;
  try {
    const img = new Image();
    const url = URL.createObjectURL(f);
    await new Promise((res, rej) => { img.onload = res; img.onerror = () => rej(new Error('Logo inválido')); img.src = url; });
    logoImage = img;
    logoDims = { w: img.naturalWidth, h: img.naturalHeight };
    log(`Logo carregado: ${img.naturalWidth}x${img.naturalHeight}`);
    URL.revokeObjectURL(url);
  } catch (err) {
    log(`ERRO no logo: ${err.message}`);
  }
});

// ---------------------------------------------------------------- run
function buildJob() {
  const selected = [];
  els.ladderBody.querySelectorAll('input[type=checkbox]:checked').forEach((c) => {
    const row = ladder[Number(c.dataset.idx)];
    if (row) selected.push(row);
  });
  if (!selected.length) throw new Error('Marque ao menos um formato da escada.');
  const ladderRun = normalizeLadder(selected);

  let watermark = null;
  if (els.chkWM.checked) {
    if (!logoImage || !logoDims) throw new Error("Marca d'água marcada, mas nenhum logo foi carregado.");
    const positions = normalizeWmSchedule(readWmRows());
    if (!positions) throw new Error('Agenda de posições inválida: adicione ao menos uma posição e, com várias, durações com soma > 0 (como no AVS).');
    watermark = {
      logoBlob: els.fLogo.files[0],
      logoW: logoDims.w,
      logoH: logoDims.h,
      width: parseInt(els.wmW.value, 10) || 120,
      opacity: parseInt(els.wmOp.value, 10) || 60,
      margin: parseInt(els.wmMg.value, 10) || 12,
      positions,
    };
  }

  return {
    file: fileBlob,
    fileName: fileBlob ? fileBlob.name : 'video',
    ladder: ladderRun,
    opts: {
      qualityMode: els.selQuality.value,
      audioMode: els.selAudio.value,
    },
    watermark,
    thumbs: els.chkThumbs.checked ? {
      enabled: true,
      playerW: thumbCfg.playerW || 1920,
      playerH: thumbCfg.playerH || 1080,
      gridW: thumbCfg.gridW || 960,
      gridH: thumbCfg.gridH || 540,
      removeBb: thumbCfg.removeBb !== 0,
      keepAr: thumbCfg.keepAr !== 0,
      vthumbs: thumbCfg.vthumbs !== 0,
      clipW: thumbCfg.clipW || 960,
      clipH: thumbCfg.clipH || 540,
    } : { enabled: false },
  };
}

function run() {
  const file = els.fVideo.files[0];
  if (!file) { setStatus('Escolha um arquivo de vídeo primeiro.', 'err'); return; }
  if (!srcMeta || !srcMeta.video) { setStatus('Aguardando sondagem do arquivo…', 'warn'); return; }
  if (!('VideoEncoder' in window) || !('VideoDecoder' in window)) {
    setStatus('WebCodecs indisponível neste navegador. Em produção o FFmpeg cobriria (fallback).', 'warn');
  }
  if (els.chkThumbs.checked && (!thumbCfg || Object.keys(thumbCfg).length === 0)) {
    setStatus('Config de thumbs ausente no servidor (window.AVS_THUMB_CFG vazio).', 'err');
    return;
  }
  try {
    const job = buildJob();
    sendJob(job);
    setStatus('Enviando job ao worker…', '');
    log(`Job ${jobSeq} → worker: ${job.ladder.length} formato(s)${job.watermark ? ' + marca d\u2019água' : ''}${job.thumbs.enabled ? ' + thumbs/vthumbs' : ''}.`);
  } catch (err) {
    setStatus(err && err.message ? err.message : String(err), 'err');
  }
}

// ---------------------------------------------------------------- output
function blobFromTransferable(t) {
  if (!t) return null;
  return new Blob([t.buffer], { type: t.type || 'application/octet-stream' });
}

function onComplete(msg) {
  // Reconstrói Blobs a partir dos buffers transferidos.
  const results = (msg.results || []).map((r) => ({
    label: r.label,
    row: r.row,
    meta: r.meta,
    blob: blobFromTransferable(r.blob),
  }));
  lastResults = results;

  let thumbs = null;
  if (msg.thumbs) {
    thumbs = {
      default: { seek: msg.thumbs.default.seek, blob: blobFromTransferable(msg.thumbs.default.jpeg) },
      frames: (msg.thumbs.frames || []).map((f) => ({ n: f.n, seek: f.seek, blob: blobFromTransferable(f.jpeg) })),
      src: msg.thumbs.src,
      dims: msg.thumbs.dims,
      srcLabel: msg.thumbs.srcLabel,
    };
  }
  lastThumbs = thumbs;

  lastPreview = null;
  if (msg.preview) {
    lastPreview = {
      mp4: blobFromTransferable(msg.preview.mp4),
      webm: blobFromTransferable(msg.preview.webm),
      plan: msg.preview.plan,
      portrait: msg.preview.portrait,
    };
  }

  renderResults(results);
  renderThumbs(thumbs, lastPreview);

  if (!results.length) {
    setStatus('Nenhum formato foi produzido (veja o log).', 'warn');
    return;
  }
  const parts = [`Concluído — ${results.length} formato(s) gerado(s).`];
  if (thumbs) parts.push(`Thumbs prontas (default em ${thumbs.default.seek.toFixed(2)}s + ${thumbs.frames.length} frames).`);
  if (lastPreview) {
    const segs = [];
    if (lastPreview.mp4) segs.push(`mp4 ${fmtMB(lastPreview.mp4.size)}`);
    if (lastPreview.webm) segs.push(`webm ${fmtMB(lastPreview.webm.size)}`);
    if (segs.length) parts.push(`Previews: ${segs.join(' · ')}.`);
  }
  parts.push('Reproduza e compare com o FFmpeg.');
  setProgress(1);
  setStatus(parts.join(' '), 'ok');
}

function clearOutputs() {
  resultUrls.forEach((u) => URL.revokeObjectURL(u));
  resultUrls = [];
  thumbUrls.forEach((u) => URL.revokeObjectURL(u));
  thumbUrls = [];
  els.results.innerHTML = '<span class="hint">—</span>';
  els.thumbsCard.style.display = 'none';
  els.thumbsOut.innerHTML = '<span class="hint">—</span>';
  els.thumbsStatus.innerHTML = '';
  lastResults = [];
  lastThumbs = null;
  lastPreview = null;
}

function renderResults(results) {
  els.results.innerHTML = '';
  if (!results.length) {
    els.results.innerHTML = '<span class="warn">Nenhum formato foi produzido.</span>';
    return;
  }
  results.forEach((r) => {
    const div = document.createElement('div');
    div.className = 'res';

    const url = URL.createObjectURL(r.blob);
    resultUrls.push(url);

    const v = r.meta.video;
    const head = document.createElement('div');
    head.innerHTML =
      `<b>${r.label}</b> (target ${r.row.width}x${r.row.height}, crf ${r.row.crf}) · ` +
      `${fmtMB(r.blob.size)} · ` +
      `real ${v ? `${v.codec} ${v.w}x${v.h}${v.fps ? ` ~${v.fps.toFixed(3)} fps` : ''}` : 'n/a'} · ` +
      `áudio ${r.meta.audio ? `<b>${r.meta.audio.codec}</b>` : '<span class="warn">sem trilha</span>'}`;
    div.appendChild(head);

    const video = document.createElement('video');
    video.controls = true;
    video.preload = 'metadata';
    video.src = url;
    div.appendChild(video);

    const dl = document.createElement('a');
    dl.href = url;
    dl.download = `${fileStem}_${r.label}.mp4`;
    dl.textContent = `⬇ Baixar ${r.label}.mp4`;
    div.appendChild(document.createElement('br'));
    div.appendChild(dl);

    els.results.appendChild(div);
  });
}

// ---------------------------------------------------------------- Fase 6 output (do worker)
function thumbLink(name, url) {
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  a.textContent = `⬇ ${name}`;
  a.style.marginRight = '10px';
  return a;
}

function renderThumbs(thumbs, preview) {
  if (!thumbs || !thumbs.default) {
    els.thumbsCard.style.display = 'none';
    return;
  }
  els.thumbsCard.style.display = 'block';
  els.thumbsOut.innerHTML = '';
  els.thumbsStatus.className = 'ok';
  els.thumbsStatus.textContent =
    `${thumbs.srcLabel ? thumbs.srcLabel + ' → ' : ''}default.jpg em ${thumbs.default.seek.toFixed(2)}s + ${thumbs.frames.length} frames · ` +
    `dims ${thumbs.dims.player.w}x${thumbs.dims.player.h} (player) / ${thumbs.dims.grid.w}x${thumbs.dims.grid.h} (grade).`;

  const bl = (b) => {
    const u = URL.createObjectURL(b);
    thumbUrls.push(u);
    return u;
  };

  // default.jpg + galeria de frames
  const defUrl = bl(thumbs.default.blob);
  const dlRow = document.createElement('div');
  dlRow.style.margin = '6px 0';
  dlRow.appendChild(thumbLink(`${fileStem}_default.jpg`, defUrl));
  els.thumbsOut.appendChild(dlRow);

  const defImg = document.createElement('img');
  defImg.src = defUrl;
  defImg.alt = 'default.jpg';
  defImg.style.maxWidth = '100%';
  defImg.style.borderRadius = '6px';
  els.thumbsOut.appendChild(defImg);

  const strip = document.createElement('div');
  strip.style.cssText = 'display:flex; gap:4px; overflow-x:auto; margin-top:8px;';
  thumbs.frames.forEach((fr) => {
    const u = bl(fr.blob);
    const img = document.createElement('img');
    img.src = u;
    img.alt = `${fr.n}.jpg`;
    img.title = `${fr.n}.jpg — seek ${fr.seek.toFixed(2)}s`;
    img.style.cssText = 'height:90px; border-radius:4px; flex:0 0 auto;';
    const wrap = document.createElement('div');
    wrap.style.textAlign = 'center';
    wrap.appendChild(img);
    const cap = document.createElement('div');
    cap.style.cssText = 'font-size:11px; color:var(--muted);';
    cap.textContent = `${fr.n}.jpg`;
    wrap.appendChild(cap);
    strip.appendChild(wrap);
  });
  els.thumbsOut.appendChild(strip);
  const dlAll = document.createElement('div');
  dlAll.style.marginTop = '6px';
  thumbs.frames.forEach((fr) => {
    dlAll.appendChild(thumbLink(`${fileStem}_${fr.n}.jpg`, bl(fr.blob)));
  });
  els.thumbsOut.appendChild(dlAll);

  // previews de hover (video.mp4 / video.webm)
  if (preview && (preview.mp4 || preview.webm)) {
    const title = document.createElement('div');
    title.style.cssText = 'font-weight:600; margin:12px 0 4px;';
    title.textContent = 'Preview de hover (vthumbs) — 30 fps, sem áudio, montagem de cortes';
    els.thumbsOut.appendChild(title);
    const pl = document.createElement('div');
    pl.style.cssText = 'display:grid; grid-template-columns:1fr 1fr; gap:8px;';
    for (const [kind, blob] of [['video.mp4', preview.mp4], ['video.webm', preview.webm]]) {
      if (!blob) continue;
      const box = document.createElement('div');
      const v = document.createElement('video');
      v.controls = true;
      v.preload = 'metadata';
      v.src = bl(blob);
      box.appendChild(v);
      box.appendChild(thumbLink(`${fileStem}_${kind}`, bl(blob)));
      pl.appendChild(box);
    }
    els.thumbsOut.appendChild(pl);
  }
}

// ---------------------------------------------------------------- events
els.btnRun.addEventListener('click', run);
els.btnCancel.addEventListener('click', cancelJob);

// ---------------------------------------------------------------- auto-poll
let pollTimer = null;
let pollRunning = false;

const elsPoll = {
  chkAutoPoll: $('chkAutoPoll'),
  pollInterval: $('pollInterval'),
  pollStatus: $('pollStatus'),
  btnPollOnce: $('btnPollOnce'),
  pollLog: $('pollLog'),
};

function pollLog(msg) {
  const t = new Date().toLocaleTimeString('pt-BR');
  elsPoll.pollLog.textContent = `[${t}] ${msg}`;
}

function pollSetStatus(html, cls) {
  elsPoll.pollStatus.innerHTML = html;
  elsPoll.pollStatus.className = cls || '';
}

async function fetchPendingJob() {
  try {
    const res = await fetch('ajax.php?module=mediabunny_pending');
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (data.status !== 1 || !data.pending) return null;
    return data;
  } catch (err) {
    pollLog(`Erro ao buscar job: ${err.message}`);
    return null;
  }
}

async function fetchVideoBlob(videoUrl) {
  try {
    const res = await fetch(videoUrl);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.blob();
  } catch (err) {
    pollLog(`Erro ao baixar vídeo: ${err.message}`);
    return null;
  }
}

async function sendResultToServer(vid, label, height, blob) {
  const fd = new FormData();
  fd.append('vid', vid);
  fd.append('format_label', label);
  fd.append('height', height);
  fd.append('video_file', blob, `${vid}_${label}.mp4`);
  try {
    const res = await fetch('ajax.php?module=mediabunny_complete', { method: 'POST', body: fd });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  } catch (err) {
    pollLog(`Erro ao enviar resultado: ${err.message}`);
    return null;
  }
}

async function pollAndProcess() {
  if (pollRunning || busy) return;
  pollRunning = true;
  pollSetStatus('Buscando job pendente…', '');

  const jobData = await fetchPendingJob();
  if (!jobData || !jobData.pending) {
    pollSetStatus('Nenhum job pendente', '');
    pollRunning = false;
    return;
  }

  const v = jobData.video;
  pollSetStatus(`VID ${v.VID}: baixando ${v.vdoname}…`, '');

  const videoBlob = await fetchVideoBlob(jobData.video_url);
  if (!videoBlob || videoBlob.size < 100) {
    pollLog(`Vídeo inválido para VID ${v.VID}`);
    pollSetStatus('Falha ao baixar vídeo', 'err');
    pollRunning = false;
    return;
  }

  pollSetStatus(`VID ${v.VID}: processando no worker…`, '');
  log(`[Auto-poll] VID ${v.VID} — ${v.vdoname} (${(videoBlob.size / 1048576).toFixed(2)} MB) —${v.cut ? ` cut ${v.cut}s` : ''}${v.cut_out ? ` cut_out ${v.cut_out}s` : ''}`);

  // Build job for worker using server encodings.
  const ladderRun = normalizeLadder(jobData.encodings || window.AVS_ENCODINGS);
  let watermark = null;
  if (jobData.watermark) {
    pollLog(`Watermark: ${JSON.stringify(jobData.watermark)}`);
  }

  const fileForWorker = videoBlob;
  const job = {
    file: fileForWorker,
    fileName: v.vdoname || 'video.mp4',
    ladder: ladderRun,
    opts: {
      qualityMode: 'high',
      audioMode: 'copy',
    },
    watermark,
    thumbs: {
      enabled: true,
      playerW: thumbCfg.playerW || 1920,
      playerH: thumbCfg.playerH || 1080,
      gridW: thumbCfg.gridW || 960,
      gridH: thumbCfg.gridH || 540,
      removeBb: thumbCfg.removeBb !== 0,
      keepAr: thumbCfg.keepAr !== 0,
      vthumbs: thumbCfg.vthumbs !== 0,
      clipW: thumbCfg.clipW || 960,
      clipH: thumbCfg.clipH || 540,
    },
  };

  // Send to worker.
  sendJob(job);
  log(`[Auto-poll] Job ${jobSeq} enviado ao worker para VID ${v.VID}.`);

  // Wait for completion (worker events handle the rest).
  // After worker finishes, upload the first result to server.
  const origOnComplete = worker.onmessage;
  const pollCompleteHandler = (ev) => {
    const msg = ev.data;
    if (msg && msg.type === 'COMPLETE' && lastResults.length > 0) {
      // Upload the first (best) format to server.
      const best = lastResults[0];
      sendResultToServer(v.VID, best.label, best.row.height, best.blob).then((res) => {
        if (res && res.status === 1) {
          pollSetStatus(`VID ${v.VID}: convertido e salvo (${best.label}, ${res.width}x${res.height})`, 'ok');
          log(`[Auto-poll] VID ${v.VID} — resultado enviado: ${res.format}`);
        } else {
          pollSetStatus(`VID ${v.VID}: conversão ok, upload falhou`, 'err');
          log(`[Auto-poll] VID ${v.VID} — upload falhou: ${res ? res.error : 'desconhecido'}`);
        }
      });
    }
    if (msg && (msg.type === 'COMPLETE' || msg.type === 'ERROR' || msg.type === 'CANCELLED')) {
      worker.removeEventListener('message', pollCompleteHandler);
      pollRunning = false;
    }
  };
  worker.addEventListener('message', pollCompleteHandler);
}

function startPoll() {
  if (pollTimer) return;
  const sec = Math.max(3, parseInt(elsPoll.pollInterval.value, 10) || 5);
  elsPoll.chkAutoPoll.checked = true;
  pollSetStatus(`Ativo — a cada ${sec}s`, 'ok');
  log(`[Auto-poll] Iniciado (intervalo ${sec}s).`);
  pollAndProcess();
  pollTimer = setInterval(pollAndProcess, sec * 1000);
}

function stopPoll() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
  elsPoll.chkAutoPoll.checked = false;
  pollSetStatus('Inativo', '');
  log('[Auto-poll] Parado.');
}

elsPoll.chkAutoPoll.addEventListener('change', () => {
  if (elsPoll.chkAutoPoll.checked) startPoll();
  else stopPoll();
});
elsPoll.btnPollOnce.addEventListener('click', () => {
  if (!pollRunning) pollAndProcess();
});

// ---------------------------------------------------------------- init
if (!window.AVS_ENCODINGS || !window.AVS_ENCODINGS.length) {
  setStatus('Escada vazia: a tabela `encoding` não retornou formatos ativos.', 'err');
} else {
  ladder = normalizeLadder(window.AVS_ENCODINGS);
  renderLadder();
  log(`Escada carregada do servidor: ${ladder.length} formato(s) (${ladder.map((r) => r.label).join(', ')}).`);
  setStatus('Escolha um arquivo para sondar e liberar o pipeline (rodará no Web Worker).');
}
