# Plano — CRUD de vídeo: corte + watermark por vídeo e Reprocessar com essas configs

Escopo aprovado pelo usuário: (1) corte + watermark no form de edição (modal + página full edit);
(2) reprocess com **ambos** os modos — re-download da origem (hoje) e re-encode local;
(3) as duas superfícies de UI.

## Por que já quase funciona (achados)

- As colunas per-video **já existem**: `video.watermark_cfg` (JSON), `video.cut`, `video.cut_out`
  (migrations `20260905000002`/`20260905000003`). Todo o pipeline (FP/SP/legado/local/mediabunny)
  já lê: `wm_video_config($vid)` (`include/function_watermark.php:85`) e
  `SELECT cut, cut_out` (`include/function_conversion_fp.php:221`).
- **O edit não grava nada disso**: `include/ajax/admin_save_video.php:63-71` e
  `siteadmin/modules/videos/edit.php:56-65` só gravam title/tags/status/etc.
- **Armadilha 1 — sobrescrita**: `wm_refresh_video_snapshot()` (`function_watermark.php:179`),
  chamado em todo regrab (`scripts/grabber_worker.php:114`), regrava watermark/cut com os da
  `grabber_sources` → apagaria a edição manual no 1º reprocess. Mesmo problema no UPDATE de
  reuso do `scripts/grabber_cron.php:208-219`.
- **Armadilha 2 — desligar a marca por vídeo**: `wm_video_config()` cai no fallback da fonte
  quando o JSON tem `enabled=0` (`function_watermark.php:96-103`) → "watermark OFF" para este
  vídeo seria ignorado. Precisa de um flag de override na resolução.
- **Armadilha 3 — reprocess sem origem**: o botão só aparece com `source_url`
  (`templates/backend/default/videos.tpl:283-285`) e o endpoint aborta sem ela
  (`admin_reprocess_video.php:40-44`). Uploads têm `source_url` vazio mas publicam a fonte no
  bucket (`modules/upload/video.php:133` → `src/{VID}/{vdoname}`); grabs **não** publicam, e o
  raw local é apagado no fim da 2ª passagem (`function_conversion_sp.php:561`,
  `del_original_video=1`). Ou seja: re-encode local só é viável quando o raw existe localmente
  ou no bucket `src/`.
- Fila já resolve isso: `queue_resolve_source()` (`include/function_queue.php:48`) tenta
  `video_path` local → `VDO_DIR/{vdoname}` → baixa `src/{VID}/{name}` do bucket.

## Mudanças

### 1. Migration (SQL aditivo — **confirmação necessária na execução**)
`sql/migrations/20261007000001_add_video_cfg_manual.sql`:
`ALTER TABLE video ADD COLUMN cfg_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER cut_out;`
(idempotente, mesmo padrão das migrations anteriores; segue o padrão do repo — `watermark_cfg`
também só existe em migration, não em `avs.sql`). Sem ela, as guards dos itens 2/3 não existem.

### 2. `include/function_watermark.php` — resolução e guarda
- `wm_video_config()`: incluir `cfg_manual` no SELECT; **se `cfg_manual=1`**, usar apenas o
  snapshot do vídeo (`wm_normalize($raw)`, podendo devolver `null` = sem marca) e **não** cair no
  fallback da fonte. Mantém o comportamento atual para `cfg_manual=0`.
- `wm_refresh_video_snapshot()`: early-return quando `video.cfg_manual = 1` (protege todos os
  reprocess de uma vez).

### 3. `scripts/grabber_cron.php:208-219` — guarda do reuso
No UPDATE de reuso, só escrever `watermark_cfg/cut/cut_out` quando `cfg_manual=0` (insert de
vídeo novo não precisa — flag nasce 0).

### 4. Save da config — modal de edição (caminho vivo)
- `include/ajax/admin_save_video.php`: ler do POST `cfg_manual`, `cut`, `cut_out`,
  `wm_enabled/opacity/size/margin/positions`; montar o JSON **via `wm_normalize()`** (fonte única
  de validação/limites); gravar `watermark_cfg`, `cut`, `cut_out`, `cfg_manual` no mesmo UPDATE.
  Modo "Herdar da fonte" (`cfg_manual=0`): zera wm/cut e chama
  `wm_refresh_video_snapshot($vid, source_url)` para repuxar a config da fonte na hora.
- `templates/backend/default/video_edit.tpl`: novo bloco **"Processamento"** antes dos likes —
  select Origem (Manual / Herdar da fonte), `Cut start (sec)`, `Cut end (sec)`, Watermark
  (Enabled, Opacity %, Width px, Margin px) e agenda de posições (select + segundos, botão
  "Add position") — markup copiado de `videos_mass_grabber.tpl:248-265`.
  Rodapé ganha `#edit-save-reprocess` ("Save & Reprocess") + select de modo
  (Auto / Re-encode local / Re-baixar da origem, default Auto).
- `templates/backend/default/assets/js/admin/jquery.manage-videos.js`:
  - popular os campos no open (`:618-634`) e no reset (`:665-680`) a partir de
    `watermark_cfg`/`cut`/`cut_out`/`cfg_manual` (o `admin_get_video` já devolve `SELECT *`);
  - incluir os campos no `videoData` (`:698-714`);
  - helpers das linhas de posição duplicados aqui (os de `mgWmRowHtml` só existem na página do
    mass grabber);
  - handler do `#edit-save-reprocess` = save + `admin_reprocess_video` com o modo escolhido.
  - `:545` (botão da linha) passa a enviar `mode` (default `auto`).

### 5. Save da config — página full edit
- `siteadmin/modules/videos/edit.php` (POST `edit_video`): mesmos campos com o mesmo parse
  (reaproveita `wm_normalize()`), gravados no UPDATE de `edit.php:56-65`.
- `templates/backend/default/videos_edit.tpl`: mesmo bloco "Processamento" (form tradicional,
  inputs `name=cut`, `name=cut_out`, `name=cfg_manual`, `name=wm_*`).

### 6. Reprocess com 2 modos — `include/ajax/admin_reprocess_video.php`
Aceita `mode` (`auto` | `local` | `redownload`):
- **redownload**: comportamento atual inalterado (exige `source_url`).
- **local**: mesmos gates (conversion_q, não estar em FP/SP); exige `vdoname`;
  `UPDATE active='3', formats=NULL, lformats=NULL, last_update`;
  `insert_into_q_fp($vid, $vdoname, VDO_DIR/vdoname)` (fila → `queue_resolve_source` resolve no
  host converter: disco local ou bucket `src/`). Pré-checagem para não deixar `active=3` preso:
  sem arquivo local no web **e** sem `src/{VID}/{vdoname}` no bucket (novo helper
  `gcs_object_exists()` em `include/function_server.php`, HEAD na URL de
  `storage_get_download_source`) → se há `source_url`, cai para redownload avisando no
  `message`; sem `source_url`, erro claro.
- **auto** (default): `source_url` ? redownload : local.
- Resposta com `{status, mode, message}`; log no `{LOG_DIR}/{VID}.grabber.log`/`.log`.
- `templates/backend/default/videos.tpl:283-285`: botão renderiza **sempre** (title dinâmico:
  "a partir da URL de origem" / "re-encode local").
- Bulk `reprocess_selected_videos` (`siteadmin/modules/videos/all.php:84-137`): mesmo fallback —
  vídeo sem `source_url` vai para o modo local em vez de `++skipped`.

### 7. Verificação (sem suite automatizada)
1. `php -l` em todos os PHP alterados; `node --check` no JS.
2. Migration aplicada no Cloud SQL via túnel `./tunnel.sh direct` (reversível: `DROP COLUMN`).
3. Render Smarty dos dois templates (harness CLI, como nos smokes anteriores).
4. Smoke HTTP local: `videos.php?m=edit&VID=` e listagem 200; `admin_get_video`/`admin_save_video`
   JSON 200 com os campos novos; `admin_reprocess_video` com `mode=local` num upload → entra em
   `conversion_queue_fp`; `mode=redownload` num vídeo com `source_url` → comportamento atual.
5. Conferir que um vídeo com `cfg_manual=1` **não** é sobrescrito por
   `wm_refresh_video_snapshot()` (unitário via script CLI).
Declarar gap: verificação limitada a lint + smoke; aparência do modal e pipeline real de
re-encode não assistidos em browser.

## Ordem de execução
1. Migration + guards (`function_watermark.php`, `grabber_cron.php`) — destravam o resto.
2. Save no modal (PHP + tpl + JS) e na página full edit.
3. Endpoint de reprocess com modos + botões (linha, bulk, modal).
4. Verificação (lint, smoke, harness).

## Riscos
- **Médio**: migration em produção (aditiva, 1 coluna) — aplicar antes do deploy; sem ela as
  guards degradam para o comportamento atual (edição manual seria sobrescrita).
- **Médio**: modo local depende de `worker_role=converter` (gate `queue_should_process`) — fila
  só drena na máquina certa, mesmo gate do fluxo normal de upload.
- **Baixo**: re-encode local reusa o raw; se o raw for a causa do problema, o modo certo é
  re-download (select no modal cobre isso).
