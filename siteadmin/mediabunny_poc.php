<?php
/*
 * PoC Fases 4–7 — Mediabunny (navegador): escada de conversão espelhando o AVS,
 * agora executada num Web Worker ESM (siteadmin/mediabunny_worker.js) com o
 * protocolo START/PHASE/PROGRESS/LOG/COMPLETE/ERROR/CANCELLED.
 *
 * A escada (tabela `encoding`), as regras de elegibilidade/enquadramento, a
 * política de áudio, a marca d'água com agenda e as thumbs/vthumbs reproduzem o
 * pipeline FFmpeg descrito em especificacao_pipeline_conversao.md. NÃO toca
 * fila/produção.
 *
 * Uso: autenticado no siteadmin, abrir siteadmin/mediabunny_poc.php.
 */
define('_VALID', true);
define('_ADMIN', true);
require '../include/config.php';
require '../classes/auth.class.php';

Auth::checkAdmin();

// Mesma fonte da conversão real: SELECT * FROM encoding WHERE status='1' ORDER BY height DESC
$rsEnc = $conn->execute("SELECT label, width, height, crf, preset, faststart, ios, format, copyonly, status FROM encoding WHERE status = '1' ORDER BY height DESC");
$encodings = $rsEnc ? $rsEnc->getrows() : array();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PoC Mediabunny — Escada AVS no worker (Fase 7)</title>
<style>
  :root { --accent:#4f46e5; --ok:#16a34a; --err:#dc2626; --warn:#b45309; --muted:#6b7280; }
  * { box-sizing:border-box; }
  body { font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; margin:0; background:#f4f4f6; color:#111; }
  .wrap { max-width:1024px; margin:0 auto; padding:24px; }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:13px; margin:0 0 20px; line-height:1.5; }
  .card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px; margin-bottom:16px; }
  .card h2 { font-size:13px; margin:0 0 12px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); }
  label { display:block; font-size:13px; margin:10px 0 4px; color:#374151; }
  input[type=file], select, input[type=number], input[type=range] { padding:7px 9px; font-size:14px; border:1px solid #d1d5db; border-radius:6px; background:#fff; }
  input[type=file] { width:100%; }
  .row { display:flex; gap:14px; flex-wrap:wrap; } .row > div { flex:1; min-width:170px; }
  .hint { font-size:12px; color:var(--muted); margin:6px 0 0; }
  button { cursor:pointer; border:0; border-radius:6px; padding:9px 16px; font-size:14px; font-weight:600; }
  #btnRun { background:var(--accent); color:#fff; }
  #btnCancel { background:#fff; color:var(--err); border:1px solid #fecaca; display:none; }
  #status { font-size:13px; margin:10px 0; min-height:18px; }
  #barTrack { height:10px; border-radius:999px; background:#e5e7eb; overflow:hidden; }
  #barFill { height:100%; width:0; background:var(--accent); transition:width .15s ease; }
  #log { background:#0b1021; color:#d6e2ff; font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace; padding:10px; border-radius:8px; height:170px; overflow:auto; white-space:pre-wrap; }
  table.ladder { width:100%; border-collapse:collapse; font-size:13px; }
  table.ladder th, table.ladder td { text-align:left; padding:6px 8px; border-bottom:1px solid #f0f0f2; }
  table.ladder th { color:var(--muted); font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.03em; }
  table.ladder .skip { color:var(--muted); text-decoration:line-through; }
  table.ladder .lq { color:var(--accent); font-weight:600; }
  #srcInfo { font-size:13px; margin:8px 0 0; }
  .res { border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px; margin:8px 0; font-size:13px; }
  .res b { color:#111; }
  .res video { width:100%; max-height:220px; background:#000; border-radius:6px; margin:8px 0; }
  #thumbsOut video { width:100%; max-height:200px; background:#000; border-radius:6px; }
  #thumbsCard { display:none; }
  .ok { color:var(--ok); } .err { color:var(--err); } .warn { color:var(--warn); }
  #wmOpts { display:none; border-top:1px dashed #e5e7eb; margin-top:8px; padding-top:4px; }
  .check { display:flex; align-items:center; gap:6px; margin:8px 0 0; }
  .check input { width:auto; }
  #wmRows .wmrow { display:flex; gap:8px; margin-top:6px; align-items:center; }
  #wmRows .wmrow select { flex:2; }
  #wmRows .wmrow input { flex:1; }
  #wmRows .wmrow .rm { flex:0 0 auto; color:var(--err); background:#fff; border:1px solid #fecaca; padding:5px 9px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>PoC Mediabunny — Escada AVS no Web Worker (Fase 7)</h1>
  <p class="sub">Espelha as regras do pipeline FFmpeg: escada da tabela <code>encoding</code> (servidor), elegibilidade sem upscale (+ <code>lq</code>), enquadramento DAR&gt;4/3, política de áudio e <b>agenda de posições da marca d'água</b> (ciclo com durações). A conversão, thumbs e previews rodam num <b>Web Worker ESM</b> com protocolo <code>START / PROGRESS / PHASE / LOG / COMPLETE / ERROR / CANCELLED</code>. <b>Não altera fila nem banco.</b></p>

  <div class="card">
    <h2>Entrada e escada</h2>
    <label for="fVideo">Arquivo de vídeo (raw do AVS em <code>media/videos/vid/</code>, ex.: <code>1234.mp4</code>)</label>
    <input type="file" id="fVideo" accept="video/*,.mkv,.mov,.webm,.ts,.flv,.avi,.m4v,.mp4">
    <div id="srcInfo" class="warn">Escolha o arquivo para sondar e marcar os formatos elegíveis.</div>
    <div style="overflow-x:auto; margin-top:8px;">
      <table class="ladder" id="tblLadder">
        <thead><tr><th>Gerar</th><th>Label</th><th>Alvo (WxH)</th><th>CRF</th><th>Preset</th><th>Situação</th></tr></thead>
        <tbody id="ladderBody"></tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Opções de encode</h2>
    <div class="row">
      <div>
        <label for="selQuality">Qualidade de vídeo (avc)</label>
        <select id="selQuality">
          <option value="high" selected>high</option>
          <option value="medium">medium</option>
          <option value="low">low</option>
        </select>
        <p class="hint">Mapping crf/preset → qualidade/bitrate entra em fases posteriores (paridade de tamanho/bytes).</p>
      </div>
      <div>
        <label for="selAudio">Política de áudio</label>
        <select id="selAudio">
          <option value="copy" selected>Auto (copiar AAC; transcodar se precisar)</option>
          <option value="aac128">Forçar AAC 128 kbps</option>
          <option value="discard">Descartar áudio</option>
        </select>
      </div>
    </div>
    <label class="check"><input type="checkbox" id="chkWM"> Aplicar marca d'água (perfil espelhando <code>video.watermark_cfg</code>)</label>
    <div id="wmOpts">
      <div class="row">
        <div>
          <label for="fLogo">Imagem do logo (PNG/JPG)</label>
          <input type="file" id="fLogo" accept="image/*">
        </div>
        <div>
          <label for="wmW">Largura do logo (px, fixa)</label>
          <input type="number" id="wmW" min="8" max="4096" step="1" value="120">
        </div>
      </div>
      <div class="row">
        <div>
          <label for="wmOp">Opacidade: <span id="wmOpVal">60</span>%</label>
          <input type="range" id="wmOp" min="0" max="100" value="60">
        </div>
        <div>
          <label for="wmMg">Margem (px)</label>
          <input type="number" id="wmMg" min="0" max="200" value="12">
        </div>
      </div>
      <div style="margin-top:10px;">
        <label>Agenda de posições (1 linha = fixa; várias com dur&gt;0 = ciclo <code>C=Σdur</code>, troca a cada <code>dur</code> s)</label>
        <div id="wmRows"></div>
        <button type="button" id="btnWmAdd" style="margin-top:6px; background:#eef2ff; color:var(--accent);">+ adicionar posição</button>
      </div>
    </div>
    <p class="hint">Arquivos grandes ficam em memória nesta PoC (<code>BufferTarget</code>); use um clipe curto. Suporte de encode H.264 depende do navegador/OS (Chrome/Edge).</p>
  </div>

  <div class="card">
    <h2>Execução (pipeline único no worker)</h2>
    <label class="check" style="margin-top:0;">
      <input type="checkbox" id="chkThumbs" checked>
      Gerar thumbs + previews depois da escada (default.jpg, 1..20.jpg, video.mp4/webm)
    </label>
    <div style="display:flex; gap:10px; margin-top:12px;">
      <button id="btnRun">Processar no worker</button>
      <button id="btnCancel">Cancelar</button>
    </div>
    <div id="status">Aguardando arquivo…</div>
    <div id="barTrack"><div id="barFill"></div></div>
    <p class="hint">Fases no worker: <code>convert</code> → <code>thumbs</code> → <code>vthumbs</code>. Buffers voltam transferidos (sem cópia) e são reconstruídos como Blob na página.</p>
  </div>

  <div class="card">
    <h2>Auto-poll (fila do servidor)</h2>
    <p class="sub">Busca automática de vídeos pendentes na fila de conversão (<code>mediabunny_pending</code>). Quando o processador está em <code>mediabunny</code> ou <code>local</code>, o servidor marca vídeos como <code>active=3</code> e este painel os busca, processa no worker e devolve o resultado.</p>
    <label class="check" style="margin-top:0;">
      <input type="checkbox" id="chkAutoPoll">
      Ativar auto-poll
    </label>
    <div class="row" style="margin-top:10px;">
      <div>
        <label for="pollInterval">Intervalo de busca (s)</label>
        <input type="number" id="pollInterval" min="3" max="60" value="5">
      </div>
      <div>
        <label>Status do poll</label>
        <div id="pollStatus" style="font-size:13px; min-height:18px;">Inativo</div>
      </div>
    </div>
    <div style="display:flex; gap:10px; margin-top:10px;">
      <button id="btnPollOnce" style="background:#eef2ff; color:var(--accent);">Buscar agora</button>
    </div>
    <div id="pollLog" style="font-size:12px; color:var(--muted); margin-top:8px; min-height:14px;"></div>
  </div>

  <div class="card">
    <h2>Log da engine</h2>
    <pre id="log"></pre>
  </div>

  <div class="card">
    <h2>Resultado por formato</h2>
    <div id="results"><span class="hint">—</span></div>
  </div>

  <div class="card" id="thumbsCard">
    <h2>Thumbs e previews (Fase 6, geradas no worker)</h2>
    <div id="thumbsStatus" style="font-size:13px; margin:0 0 8px;"></div>
    <div id="thumbsOut"><span class="hint">—</span></div>
  </div>
</div>

<script>
  // Escada enviada pelo servidor (fonte única: tabela `encoding` do AVS).
  window.AVS_ENCODINGS = <?php echo json_encode($encodings); ?>;
  // Config de thumbs/vthumbs (mesmas chaves usadas pelo pipeline PHP).
  window.AVS_THUMB_CFG = {
    playerW: <?php echo (int) ($config['thumbnail_player_width'] ?? 1920); ?>,
    playerH: <?php echo (int) ($config['thumbnail_player_height'] ?? 1080); ?>,
    gridW: <?php echo (int) ($config['img_max_width'] ?? 960); ?>,
    gridH: <?php echo (int) ($config['img_max_height'] ?? 540); ?>,
    removeBb: <?php echo ($config['thumbnail_remove_bb'] ?? '1') === '1' ? 1 : 0; ?>,
    keepAr: <?php echo ($config['thumbnail_keep_ar'] ?? '1') === '1' ? 1 : 0; ?>,
    vthumbs: <?php echo ($config['vthumbs'] ?? '1') === '1' ? 1 : 0; ?>,
    clipW: <?php echo (int) ($config['vthumb_width'] ?? 960); ?>,
    clipH: <?php echo (int) ($config['vthumb_height'] ?? 540); ?>
  };
</script>
<script type="module" src="<?php echo $config['BASE_URL']; ?>/siteadmin/mediabunny_poc.js?ver=7"></script>
</body>
</html>
