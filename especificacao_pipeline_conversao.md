# Especificação de referência — pipeline de conversão FFmpeg (AVS CMS 8.2)

**Status:** Fase 1 (espelhamento). Nenhuma alteração de produção.
**Objetivo:** congelar o comportamento atual do pipeline FFmpeg como **especificação de referência**, para que uma futura engine Mediabunny (navegador, WebCodecs) produza o **mesmo resultado lógico** — mesmos parâmetros, não bytes idênticos. O FFmpeg permanece como fallback/referência (ver `worker_role`/decisão no `relatorio_avs82.md`, seção 4).

> **Progresso da paridade:** Fases 3–6 implementadas na PoC do siteadmin (`siteadmin/mediabunny_poc.php` + `mediabunny_engine.js` + `mediabunny_thumbnails.js`): PoC MP4; escada da tabela `encoding`, elegibilidade/`lq`, enquadramento DAR>4/3, política de áudio AAC/copy, codec avc, autorotate embutido; agenda de posições da marca d'água (`wmPositionXY`, `normalizeWmSchedule`, `wmActiveIndex`, `watermarkProcessFor`); thumbs/vthumbs (seeks de default.jpg/1..20, barras pretas, contido sem upscale, sharpen; montagem de preview 30 fps mp4/webm). **Fase 7 concluída**: pipeline (escada → thumbs → vthumbs) movido para um **Web Worker ESM** (`siteadmin/mediabunny_worker.js`) com o protocolo `START / PHASE / PROGRESS / LOG / COMPLETE / ERROR / CANCELLED`; a main thread (`mediabunny_poc.js`) ficou só com UI e reconstrução de Blobs a partir de buffers transferidos; o logo da marca d'água agora é enviado como Blob e decodificado no worker (`ImageBitmap`), e os módulos engine/thumbnails não dependem mais de DOM. Pendentes: Fases 8–14 (progresso por etapa real na UI por fase, cancelamento/limpeza refinados, upload, job API/fila, fallback automático, A/B, rollout).

Referências de código são `arquivo:linha`. Comportamento foi lido do código, não executado; pontos que exigem confirmação em runtime estão marcados como **[CONFIRMAR]**.

---

## 1. Visão geral

Existem **três entrypoints** de conversão, todos CLI PHP que dão `require` em `include/config.php` (o que re-entra em `check_q()`/`check_grab_queue()` — ver relatório seção 4):

| Script | Uso | Motor |
|---|---|---|
| `scripts/convert_videos.php` | conversão direta/legada (grabber_worker quando `conversion_q != '1'`, upload direto, reprocess) | `include/function_conversion.php` |
| `scripts/convert_videos_fp.php` | 1ª passagem via fila `conversion_queue_fp` | `include/function_conversion_fp.php` |
| `scripts/convert_videos_sp.php` | 2ª passagem via fila `conversion_queue_sp` | `include/function_conversion_sp.php` |

### 1.1 Cadeia completa (fluxo de produção atual)
```
entrada (raw) em media/videos/vid/{VID}.{ext}        (grabber_worker ou upload)
   → fila conversion_queue_fp (status 0)
   → check_q()/pump inicia convert_videos_fp.php em background
   → 1ª passagem: gera os formatos da escada (maior→menor), marca video.formats/lformats
   → agendada a 2ª passagem (conversion_queue_sp) com skip = maior altura já feita
   → convert_videos_sp.php completa formatos restantes + postConversion
   → postThumbs (thumbs + vthumbs) a partir do arquivo processado
   → upload dos MP4 para GCS (privado) + thumbs (publicRead)
   → remoção de arquivos locais conforme del_original_video
```

---

## 2. Entrada e sondagem (probe)

- **Arquivo:** `{VDO_DIR}/{video_name}` (`VDO_DIR = media/videos/vid`, `include/config.paths.php:17`); `video_name` segue `^[0-9]{1,5}\.[a-z0-9]{2,4}$` e normalmente é `{VID}.mp4` (grabber grava sempre `.mp4`; uploads preservam a extensão original). Caminho vem da linha da fila (`video_path`).
- **Cortes:** `video.cut` (início, s) e `video.cut_out` (fim, s) são snapshot da fonte de grab (`function_conversion_fp.php:214-237`). Aplicados como `-ss {cut}` e `-t {duração-ffprobe - cut - cut_out}` (só quando o trim calculado > 1 s). Aplica-se a TODOS os formatos e às thumbs.
- **Probe** com `ffprobe` (`function_conversion_fp.php:100-118`): stream v:0 → `codec_long_name,codec_name,width,height,display_aspect_ratio,sample_aspect_ratio,duration`; format → `filename,format_name,duration,size`. Deriva `file_extension`. `ffpInfo()` (`:120-180`) normaliza `display_aspect_ratio` quando `0:1`/`N/A`/vazio para `width:height` (via `ratio()`, `:22`).
- **Orientação:** `video_orientation(w,h,dar)` (`:35-51`): `w<h` → portrait; `w>h` → landscape; quadrado → usa o DAR para decidir (anamórfico conta como portrait/landscape).

---

## 3. Perfil de encodings (tabela `encoding`)

Carregado por `getEncodings()` (`function_conversion_fp.php:190-199`): `SELECT * FROM encoding WHERE status='1' ORDER BY height DESC`; a **última linha recebe a flag `lq=true`** (é o formato "pega-tudo" de menor altura).

Seed atual (`sql/avs.sql:387-392`):

| id | label | width | height | crf | preset | faststart | ios | format | copyonly | status |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 240p | 320 | 240 | 22 | medium | 1 | '' | mp4 | 1 | 1 |
| 2 | 360p | 480 | 360 | 21 | medium | 1 | '' | mp4 | 1 | 1 |
| 3 | 480p | 640 | 480 | 20 | medium | 1 | '' | mp4 | 1 | 1 |
| 4 | 720p | 1280 | 720 | 19 | medium | 1 | '' | mp4 | 1 | 1 |
| 5 | 1080p | 1920 | 1080 | 18 | medium | 1 | '' | mp4 | 1 | 1 |

Editável no painel (Settings > Video Conversion / encodings). `ios` hoje é vazio (coluna existe p/ compatibilidade). **[CONFIRMAR]** conteúdo real da tabela em produção.

---

## 4. Conversão por formato — 1ª passagem (FP)

Lógica central: `convert()` em `function_conversion_fp.php:201-349`, loop sobre `getEncodings()` em `scripts/convert_videos_fp.php:38-41`.

### 4.1 Elegibilidade por resolução
Converte quando `(altura_alvo <= altura_fonte || largura_alvo <= largura_fonte) || lq`. Ou seja: **nunca aumenta** a resolução, exceto o último formato (`lq`) que sempre roda.

### 4.2 Escala/enquadramento (sem watermark)
Fórmula `scale` usada (FP): `function_conversion_fp.php:274-282`:
```
scale=iw*sar:ih,setsar=1,scale='if(gt(dar,4/3),{W},-2)':'if(gt(dar,4/3),-2,{H})',setsar=1
```
- 1º `scale=iw*sar:ih,setsar=1` **normaliza SAR** (fontes anamórficas viram pixels quadrados antes do enquadramento);
- 2º enquadra: DAR > 4/3 → largura fixa `W` e altura automática par; senão altura fixa `H` e largura automática par (`-2` mantém proporção e dimensão par);
- o **fallback** (`scale()` em `function_conversion_fp.php:8-20` + `:330-349`) recalcula com enquadramento por razão simples + arredondamento par (`floor`/`*2`) e re-tenta quando o 1º arquivo não passou no check de tamanho (>100 bytes).
- Quando o alvo `lq` é maior que a fonte (ex.: 1080p de fonte pequena), **não escala** (sem `-vf`) e o label vira `HD` (altura ≥480) ou `SD` (`:283-293`).

### 4.3 Watermark
Quando o vídeo tem perfil de watermark, o `-vf`/escala é substituído pelo **gráfico de overlay** construído em `wm_build_args()` (`include/function_watermark.php:281-380`). Sem perfil → nenhuma alteração do comando (compatibilidade byte-a-byte do passado). Detalhe completo na seção 5.

### 4.4 Comando de encode (FP, com watermark ou re-encode)
```
ffmpeg { -ss cut } -i "{src}" { -t trim } {vfilter} -threads 0
  -c:v libx264 -preset {preset} -crf {crf} {ios} { -movflags +faststart } -y "{out}"
```
- Áudio: sem `-c:a` explícito na maioria dos caminhos FP → **comporta o default do ffmpeg p/ MP4** (seleção automática; tipicamente AAC) **[CONFIRMAR codec/bitrate resultante]**; o mapa de áudio só é garantido pelo caminho watermark (`-map 0:a?` dentro de `wm_build_args`, `function_watermark.php:377`). Vídeo sem áudio não quebra (`0:a?` / seleção automática tolerante).

### 4.5 Copy-only (atalho) — FP
`function_conversion_fp.php:250-272`: quando o encoding tem `copyonly=1`, a fonte é **MP4/H.264 já com faststart ok, SAR 1:1, sem corte e sem watermark** → apenas remux `-c copy -movflags +faststart` (segundos) ou cópia pura. Caso haja `cut` mas o resto valha, usa `-ss cut -i src -c copy`. **Qualquer perfil de watermark força re-encode** (`wm_force_reencode()`, `function_watermark.php:246-248`).

### 4.6 Pós-1ª-passagem
- Se arquivo gerado (`>100 bytes`): atualiza `video.formats` (`"{height}.{label}.{format}"`) e `video.lformats` (`"{label}"`) sem duplicar; chama `insert_q_sp($vid, $e['height'], ...)` (`function_conversion_fp.php:352-385`) que insere a 2ª passagem na fila `conversion_queue_sp` e **remove a linha da FP**.
- Se não gerou nada no 1º comando: tenta o **fallback de escala fixa** e re-encode (`:300-349`).
- `scripts/convert_videos_fp.php` **não** chama `postConversion` (adiado p/ a 2ª passagem — a 1ª não pode ativar o vídeo com apenas o formato maior), e faz cleanup de linha FP travada quando nenhum formato foi produzido (`:59-66`), deixando `active='0'` p/ reprocess manual.

---

## 5. Watermark — comportamento completo (paridade obrigatória)

Fonte: `include/function_watermark.php`. Perfil JSON congelado em `video.watermark_cfg` (snapshot no grab) ou resolvido por domínio da `source_url` em `grabber_sources.watermark_config` (`wm_video_config`, `:85-124`).

```json
{ "enabled": 1, "opacity": 60, "size": 120, "margin": 12,
  "positions": [ {"pos":"top-right","dur":5}, {"pos":"bottom-left","dur":5} ] }
```
- `enabled`: 0/ausente → sem marca (null).
- `opacity`: 0–100 (viram `aa=0.60` no `colorchannelmixer`).
- `size`: **largura do logo em pixels, fixa**, independente da resolução do vídeo/saída; altura automática (`scale=w={size}:h=-2`), dimensões pares garantidas (`:322-325`). Valores 8–4096.
- `margin`: 0–200 px.
- `positions`: até 5 posições válidas — `top-left, top-right, bottom-left, bottom-right, center` (`wm_valid_positions`, `:20-23`).
- **Agenda temporal:** 1 posição → fixa o vídeo todo. Várias posições com `dur>0` → alternância em ciclo de `C=Σdur` segundos, com troca **instantânea** modelada por expressões aninhadas `if(lt(mod(t,C),A_i), pos_i, ...)` num único overlay (`:338-358`). Linha com `dur=0` em lista multi só é aceita se o ciclo total > 0 (`:50-58`). **[CONFIRMAR]** semântica real de `dur=0` no meio da agenda (o comentário do arquivo descreve "fica até o fim do ciclo").
- **Filtro/grafo:** `[0:v]scale...setsar=1[bg];[1:v]scale=w={W}:h=-2,format=rgba,colorchannelmixer=aa={op}[wm];[bg][wm]overlay=x='...':y='...',format=yuv420p[vout]` + `-map "[vout]" -map 0:a?` (`:360-380`). `format=yuv420p` no fim garante compatibilidade com H.264.
- Logo: `config['watermark_image']` (default `media/player/logo/logo.png`, `wm_logo_path`, `:246-263`); arquivo ausente → converte **sem** marca, com aviso no log.
- Não há opacidade/posição por encoding — o perfil é único por vídeo e se aplica a **todos os formatos** da escada.

---

## 6. Segunda passagem (SP) e pós-conversão

`include/function_conversion_sp.php`, acionada por `conversion_queue_sp`; `skip` = maior altura já produzida na FP (não refaz esse formato, `convert()`, `:201-210`).

- **Preset acelerado:** se o encoding usa preset `medium/slow/slower/veryslow`, a SP troca para `fast` (≥720p) ou `faster` (<720p) (`:250-255`). A FP mantém o preset da tabela. **[CONFIRMAR]** impacto na qualidade/bitrate entre passes.
- **Copy-only** da SP: mesma heurística da FP + condição de resolução idêntica (`:262-282`).
- **Áudio explícito:** re-encode da SP usa `-c:a copy` (`:293`); o retry com escala fixa usa `-c:a aac -b:a 128k` (`:325`). FP não fixa áudio (seção 4.4).
- Cada formato atualiza `formats`/`lformats` de forma incremental (`:298-350`).
- Ao final (`scripts/convert_videos_sp.php:45-48`): remove as linhas das filas FP/SP do vídeo e chama `postConversion($vid,$video_path)`.

### 6.1 postConversion (FP e SP; `function_conversion_fp.php:462-565` / `function_conversion.php:406-...`)
1. **Ativação:** respeita suspensão manual (`active='0'` mantém 0); `active='1'` mantém 1; senão ativa (`active=1`) quando `approve='0'` e há formatos; caso contrário 0.
2. **Metadados** (do probe do arquivo SD = último formato e HD = primeiro, quando >1 formato): `duration`, `width_sd/height_sd/aspect_sd` (+ `width_hd/height_hd/aspect_hd`), `hd` (1 quando altura > 480, FP) ou `>=480` (legado), `orientation` (via `video_orientation`), `last_update`.
3. **Multi-server/GCS:** se `multi_server=1`, `upload_video_formats($vid, $formats, $server)` (`include/function_server.php:58`).
4. **Limpeza local:** se `del_original_video=1`, remove os `h264/{VID}_{label}.{ext}` locais após upload confirmado (`function_conversion_fp.php:554-563`). O arquivo raw `vid/{VID}.mp4` (e `.bak`) é gerenciado pelo fluxo de origem/cleanup — **[CONFIRMAR]** momento exato de remoção do raw por fluxo (grab mantém? `media_cleanup.php` cobre falhas pós-GCS).

### 6.2 Distribuição (function_server.php)
- **GCS** (`upload_video_formats_gcs`, `:206-269`): objeto `h264/{VID}/{label}.{ext}` (ex.: `h264/88/720p.mp4`) — **privado**, acesso por V4 Signed URL (classes/gcs.class.php). Após sucesso: `video.server = https://storage.googleapis.com/{bucket}` e `upload_video_thumbs_gcs()` (`:306-...`) envia `thumbs/{VID}/` (publicRead).
- **Layout local/FTP** (legado): `h264/{VID}_{label}.{ext}` plano.
- Thumbs também migram p/ GCS `thumbs/{VID}/{n}.jpg` + sprite (ver seção 7).

---

## 7. Thumbnails, vthumbs (preview) e sprites

### 7.1 Disparo
`postThumbs($vid,$src)` (`function_conversion_fp.php:386-461`): thumbs **sempre depois da conversão** quando o vídeo tem watermark ou corte (`cut`/`cut_out`) — usa arquivo processado (nunca o raw, que exporia frames sem marca/with intro). Sem watermark/corte: tenta o `.bak` do raw se existir, senão o primeiro MP4 convertido local (ou remoto via URL), senão o próprio raw.

### 7.2 Frames (`extract_video_thumbs`, `include/function_video.php:318-...`)
- Loop `i=0..20`: `default.jpg` (poster do player, seek **aleatório** dentro da janela) e `1.jpg..20.jpg` (frames da grade/rotação) com seeks em `(i*timeseg)+se`, onde `se = 2|1|0.5|0` conforme duração (>5s → 2) **+ offset aleatório** `rand(0, floor(dur/10))` (comum a todos os frames), e `timeseg = (dur-2*se)/20`.
- Extração por ffmpeg (`-ss {hh:mm:ss} -frames:v 1 -an -q:v 2 -vcodec mjpeg`), com fallback de download p/ fonte remota quando o frame falha.
- **Tamanhos alvo** (`function_video.php:333-374`): player (default.jpg) com piso de qualidade (`max(1920, thumbnail_player_width)`, sem upscale — `thumb_target_dims()`); frames 1..20 com `max(960, img_max_width)` e `img_max_height` no piso. `keep_ar` preserva razão (sem upscale); senão preenche a caixa exata (corte central via `process_thumb`). **[CONFIRMAR]** valores efetivos (config atual: thumbnail_player 1920x1080; img_max 1920x1080).
- `thumbnail_remove_bb` ativa corte de barras pretas (detecção via `process_thumb`); alvo `main`/`player` restringem a produção de frames (usado em regenerações parciais do painel).
- Saída em `get_thumb_dir($vid)` (`include/function_thumbs.php:140`): layout local `media/videos/tmb` ou `tmb{índice}/{VID}/` por volume (`max_thumb_folders`).

### 7.3 vthumbs (preview hover do player/rotator)
`extract_video_vthumbs` (`include/function_video.php:597`) e variante HQ (`:728`), ligado por `config['vthumbs']='1'` e `vthumb_width/height` (config atual 960x540): gera mini-clipes **webm** (`libvpx`) e **mp4** (`libx264`, `crf 18/20`, `-movflags +faststart`) a partir do 2º~4º segundo do arquivo processado, na pasta de thumbs (`.../{VID}/video.webm`/`video.mp4`) — são os arquivos consumidos pelo hover-preview `playvthumb_<VID>` (ver relatório seção 1). Clipes são derivados do mesmo arquivo da seção 7.1.

---

## 8. Fila, estado e banco (resumo p/ paridade)

- Tabelas `conversion_queue_fp` / `conversion_queue_sp` (colunas: VID, UID, video_name, video_path, title, status, addtime, start, skip) — schema `sql/avs.sql:267-296`.
- Ciclo de vida: raw ok → fila FP `status=0` → `status=1` (iniciado) → removido na passagem seguinte; SP similar. `remove_overdue` limpa travados após `q_timeout` h.
- `video.active`: 0 (inativo/suspenso/falha), 1 (ativo), 2 (baixando), 3 (na fila) — transições 2→3→(1|0).
- Guards/concorrência: `include/config.php:100-106`, `check_q()`, `pump_conversion_queue()` e o papel `worker_role` — detalhados no `relatorio_avs82.md` (seção 4). Esta spec não duplica.

---

## 9. Contrato de paridade p/ a engine Mediabunny (critérios de aceite)

Para cada vídeo real, a engine cliente deve reproduzir (comparador A/B FFmpeg × Mediabunny):

| # | Aspecto | Comportamento de referência |
|---|---|---|
| 1 | Escada | mesmos formatos elegíveis da tabela `encoding` (status=1, ordem altura desc, `lq` no fim), sem upscale salvo `lq` |
| 2 | Enquadramento | fórmula DAR>4/3 (`function_conversion_fp.php:274-282`) + normalização SAR + dimensões pares; fallback p/ escala fixa |
| 3 | Áudio | manter fluxo: sem faixa → vídeo ok; com faixa → copy ou AAC (confirmar policy exata por pass) |
| 4 | Codec/container | `avc` (H.264) + `mp4` com **faststart** (moov antes do mdat, equivalente a `-movflags +faststart`) |
| 5 | Watermark | profile JSON completo: tamanho fixo do logo (px), opacidade, margem, posições e **agenda temporal** (ciclo `if(lt(mod(t,C),A))`), `format=yuv420p` |
| 6 | Cortes | `cut`/`cut_out` aplicados a todos os formatos e à fonte de thumbs |
| 7 | Metadados | `formats`/`lformats`, `duration`, `width_sd/height_sd/aspect_sd` (+hd), `orientation`, `hd`, ativação `approve`/suspensão |
| 8 | Thumbs | default.jpg (seek aleatório) + 1..20 (intervalos), tamanhos/pisos, `remove_bb`, `keep_ar` |
| 9 | vthumbs | webm+mp4 de preview na pasta do vídeo (vthumbs=1), mesmo direcionamento da seção 7.1 (arquivo processado quando há watermark/corte) |
| 10 | Arquivos | nomenclatura `h264/{VID}_{label}.mp4` (local) / `h264/{VID}/{label}.mp4` (GCS) |
| 11 | Erros/progresso | falha de pass → vídeo inativo p/ reprocess (não trava fila); progresso por etapa (download→decode→encode→mux→upload) |

**Paridade não significa byte a byte**: encoders distintos (x264 vs WebCodecs/avc) produzem bitstreams diferentes. A comparação deve validar parâmetros (resolução, duração, fps, codec, faststart), existência de áudio, watermark (posição/ciclo) e QA visual no player do AVS.

---

## 10. Riscos e pontos abertos (confirmar antes da Fase 4)

1. **[CONFIRMAR]** codec/bitrate de áudio efetivo por pass (FP sem `-c:a`; SP `-c:a copy`; retry `aac 128k`).
2. **[CONFIRMAR]** conteúdo real da tabela `encoding` e configs de thumbs em produção (pisos de qualidade).
3. FPS variável / rotação por metadados (`rotation`) — fontes distintas entregam metadados diferentes; `display_aspect_ratio` anamórfico precisa da mesma regra de orientação.
4. `dur=0` na agenda de posições da watermark (semântica real).
5. Suporte a encode H.264/AAC do WebCodecs varia por navegador/OS — o fallback FFmpeg cobre; na engine Mediabunny usar `getFirstEncodableVideoCodec`/`conversion.isValid` + `discardedTracks`.
6. Thumbs a partir de URL remota já têm fallback de download no pipeline atual — replicar.

---

## 11. Artefatos

- Este documento (referência congelada).
- `siteadmin/mediabunny_poc.php` — página autenticada da PoC (embute a escada da tabela `encoding` do servidor).
- `siteadmin/mediabunny_engine.js` — engine Fases 4–5: `normalizeLadder`, `rowEligibility`, `expectedBox`, `videoOptionsForRow` (enquadramento AVS + avc + autorotate), `audioOptionsFor` (copy/AAC 128k/discard), watermark com agenda (`WM_POSITIONS`, `wmPositionXY`, `normalizeWmSchedule`, `wmActiveIndex`, `watermarkProcessFor`) e `runLadder` (um `Conversion`/MP4 por rung, com progresso e cancelamento).
- `siteadmin/mediabunny_thumbnails.js` — engine Fase 6: `planFrameSeeks`, `thumbTargetDims`, `detectBlackBars`, `sharpenCanvas`, `extractFrameSet` (default.jpg + 1..20.jpg), `planVthumbsMontage`, `composePreviewFrame`, `buildVthumbs` (video.mp4/video.webm 30 fps sem áudio).
- `siteadmin/mediabunny_poc.js` — wiring Fase 7 da página (main thread FINA: sondagem, tabela de elegibilidade, protocolo com o worker, reconstrução de Blobs, players/downloads).
- `siteadmin/mediabunny_worker.js` — Web Worker ESM Fase 7: recebe `{type:'run', job}`/`{type:'cancel'}`, roda `runLadder` + `extractFrameSet` + `buildVthumbs` em fases e emite `START/PHASE/PROGRESS/LOG/COMPLETE/ERROR/CANCELLED`, devolvendo os Blobs como `ArrayBuffer` transferidos (sem cópia).

Nenhum destes artefatos toca fila/banco/produção.
