# Relatório técnico AVS CMS 8.2

## Escopo
Análise de comportamento frontend (deck de vídeo), comportamento de mass grabber (Mixvazadas) e sobre o comportamento de fila/worker no banco compartilhado (task de custo/gastos). 

Os itais abaixo correspondem ao que foi inspecionado e ao que foi concluído no código.

---

## 1. Pré-visualização de vídeo no hover do card

### Estado atual
O HTML do card pode renderizar um elemento com id `playvthumb_<VID>` quando `$videos[i].vthumbs == '1'`. O JS em `templates/frontend/pornozinho/js/jquery.rotator.js` possui um handler de `mouseenter` que:
- insere um elemento `<video>` sobre o thumbnail com sources apontando para `video.webm` e `video.mp4` no caminho de thumbnail;
- esconde o img e faz fadeIn do vídeo na entrada;
- remove o vídeo e o loader no `mouseleave`.

### Condições necessárias para aparecer
A prévia só dispara se você estiver no JS correto, se o card tiver o id `playvthumb_...`, se o img não tiver a classe `img-private`, e se os arquivos de preview estiverem disponíveis no path esperado.

### O que foi alterado
- Toque de hover no trio: os handlers de `mouseenter/enter` associados ao trio foram removidos para não avançar os frames no trio. Isso não remove a prévia do vídeo, apenas deixa o trio sem rotação automática no hover.
- Prévia de vídeo: movimento presente e inalterado na lógica de hover do `playvthumb_...`.

Se a prévia não aparecer, os pontos de verificação são:
1. O card tem `id="playvthumb_<VID>"`?
2. O img tem `img-private`?
3. Os arquivos `video.webm`/`video.mp4` existem em `thumb_path(<VID>)/<VID>/`?
4. O JS carrega sem erro e o handler de `mouseenter` está no documento?

---

## 2. Trio vertical de imagens (3 frames)

### Como é gerado
O trio é produzido no servidor, no PHP, pela função `video_trio_html()` e pelo helper `insert_video_trio()`.  
Ele consome:
- `VID`, `thumb`, `thumbs`, `thumbnails_opt`;
- monta `<div class="xb-trio">` com 3 `<img>`, preenchendo `data-vid`, `data-idx`, `data-covers`, `data-thumbs`.

### Comportamento antes
O JS das cards fazia avanço/rotação das imagens do trio em hover, além de movimentação em otros handlers.  
O que você pediu:
- Sem troca das 3 imagens ao passar o mouse no card.
- As 3 imagens devem ser sorteadas de forma aleatória ao montar o trio, sem sequência fixa como 4, 5, 6...

### Alterações no trio (JS)
- Foi removido/neutralizado os handlers de trio que avançavam os frames no hover.
- Foi adicionado comportamento de montagem/preparo do trio uma vez ao carregar o documento, sem alterar os frames durante interação.

### Regra de sorteio (lado servidor)
Como você pediu para o trio ser exibido/definido uma vez por sessão/página no frontend, e sem alterar comportamento atual dos demais pontos, a forma mais segura é manter o trio gerado pelo PHP e ajustar a forma de escolha dos 3 frames dentro de `video_trio_html()` / funções correlatas.

### Implementação recomendada
Para seguir o padrão do trio sem avançar em hover e com aleatoriedade uma vez por renderização:
- manter geração do trio em `video_trio_html()` (PHP);
- usar uma semente estável por request para escolher os 3 frames a partir de `video_cover_list()`;
- evitar handlers de trio que alterem `data-idx` ou troquem `src` durante o ciclo de vida do card.

Isso resolve sem mexer comportamento dos demais templates que incluem `video_trio`.

---

## 3. Tempo zerado na lista do Mixvazadas no painel mass grabber

### Onde o tempo é montado
- No painel, a listagem usa `DiscoveryManager::getDiscovered()`.
- A coluna de tempo vem do campo `duration` de `grabber_discovered_videos` e é formatada por `formatDuration()` da mesma classe.
- Se o `duration` salvo for `0`, a exibição mostra tempo zerado.

### Por que o Mixvazadas vem com tempo zerado
- O scraper `scripts/mixvazadas_scrape.py` faz discovery e depois enriching das páginas dos vídeos.
- No padrão atual, o Mixvazadas não estava trazendo `duration` real no JSON de retorno das cards, então DiscoveryMananger guarda `duration = 0`.
- Os sites que “funcionam” no painel trazem tempo real no discovery, seja de meta/JSON-LD, seja de probe da fonte durante o enrichment.

### O que foi feito no Mixvazadas
- Foi alinhado o enriquecimento do Mixvazadas ao padrão dos demais providers de mass grabber: tentativa de extração de tempo durante o enrichment da página.
- O script Python (`mixvazadas_scrape.py`) agora:
  - tenta `og:video:duration`;
  - tenta `JSON-LD VideoObject` com duração ISO 8601;
  - tenta converter formato `mm:ss` / `h:mm:ss` se disponível.
- O `enrich_video()` popula `duration` e `duration_formatted` quando consegue encontrar um tempo válido.

### Provider PHP
- `MixvazadasProvider` continua no padrão dos demais providers de massa:
  - estende `AbstractScrapeProvider`;
  - regista o script Python;
  - diferença principal: timeout e hint de descoberta conforme site.

### Resultado esperado
- Após retry de discovery com scraper atualizado, os itens recém descobertos devem vir com `duration` real quando a página do vídeo expor esse tempo;
- A lista do Mixvazadas deve deixar de mostrar tempo zerado para os itens que tiverem duração recuperada.

### Limitação
Se uma página do Mixvazadas não expuser duração em `og:video:duration`, `JSON-LD` ou formato legível, o tempo continuará zerado até existir outra fonte de informação confiável. A correção aqui trata o caso em que a informação existe e é legível, mantendo o padrão dos demais providers.

---

## 4. Concorrência PC/VM — auditoria completa de disparo do FFmpeg

### Contexto informado
Você descreveu uma situação em que seu PC processou um vídeo enquanto a VM também parece ter ativado trabalho, o que explica um gasto repentino na VM.

### Arquitetura confirmada no código
- MySQL compartilhado: o banco MariaDB roda na VM; o PC conecta via túnel (`deploy.sh` mantém `config.db.php` separado por host — VM usa `127.0.0.1:3306`, PC usa o túnel `127.0.0.1:3307`).
- `deploy.sh` preserva o `include/config.local.php` de cada host em deploys (backup/restore), então a config da VM é independente da do PC — edição deve ser feita na VM.
- Filas compartilhadas: `conversion_queue_fp`, `conversion_queue_sp` e a fila de grabs (mass grabber) são as mesmas tabelas para os dois hosts.

### Correção semântica mais importante
`conversion_q` NÃO é um interruptor "este host converte sim/não". É um **seletor de modo de fila**: três pontos tratam `'1'` como "enfileirar em `conversion_queue_fp`" e `'0'` como **"rodar `scripts/convert_videos.php` agora, em background, neste host"**:
- `scripts/grabber_worker.php:258-267` — o `else` dispara `convert_videos.php` direto;
- `modules/upload/video.php:110-122` — mesma semântica dupla no upload;
- reprocess/grab/upload sempre passam por um desses dois pontos.

Consequência: **`conversion_q = '0'` na VM para o polling por request, mas NÃO impede FFmpeg na VM** para qualquer job que a VM baixar, clamar ou reprocessar — a VM converte inline, sem passar pela fila.

### Mapa completo de pontos de disparo (auditado)

1. **Polling da fila de conversão a cada request** (o caminho já descoberto)
   - `include/config.php:100-102`: se `conversion_q == '1'` → `check_q()`.
   - `check_q()` (`include/function_queue.php:25`) inicia `scripts/convert_videos_fp.php` / `convert_videos_sp.php` em background (`function_queue.php:46` e `:58-60`; SP em `:85` e `:90-92`).
   - Gates: `conversion_q`, `q_limit` e `file_exists($video_path)`.
   - Efeito colateral destrutivo: quando o arquivo NÃO existe no host que faz polling, `check_q()` **apaga a linha da fila** (`function_queue.php:54-58` e `:96-100`) — a VM polando a fila compartilhada sem os arquivos locais destrói conversões pendentes do PC em silêncio.

2. **Pipeline de grab a cada request — SEM gate de `conversion_q`**
   - `include/config.php:104-106`: `check_grab_queue()` roda incondicionalmente.
   - `check_grab_queue()` (`include/function_grab_queue.php:75-89`): quando `grabber_settings.realtime_enabled == '1'` (DB, valor global) → dispara `scripts/grabber_cron.php` em background (limitado a 1x/30s por host via lock).
   - `grabber_cron.php` clama jobs da tabela compartilhada e spawna `grabber_worker.php` (`grabber_cron.php:214-218`).
   - `grabber_worker.php` roda **download yt-dlp + remux ffmpeg faststart incondicionalmente** ao processar um job (`grabber_worker.php:163-170`), antes de qualquer decisão de conversão.
   - Como a VM serve o site em produção, tráfego real + `realtime_enabled=1` faz a VM baixar e rodar remux para os grabs que ela clamar.

3. **Handoff de conversão (semântica dupla)**
   - `scripts/grabber_worker.php:253-267`;
   - `modules/upload/video.php:106-122`;
   - caminhos de reprocess que chegam no worker: `include/ajax/admin_reprocess_video.php:66-81`, `scripts/reprocess_videos.php:82-100`, `scripts/grabber_cron.php:201-218`.

4. **Ações do painel (rodam no host do siteadmin — em produção, a VM)**
   - `siteadmin/modules/videos/mass_grabber.php:487-488` (Process Now → `grabber_cron.php`);
   - `siteadmin/modules/videos/grabber.php:220` e `siteadmin/modules/videos/all.php:95` (grab → `grabber_worker.php`).

5. **Cron/CLI local do host** (não versionado — crontab vive em cada host): `grabber_cron.php`, `reprocess_videos.php`, `media_cleanup.php`, `convert_videos*.php`. Verificar na VM com `crontab -l` e `/etc/cron.d/`.

6. **Amplificador recursivo**: os scripts de conversão (`convert_videos.php`, `convert_videos_fp.php`, `convert_videos_sp.php`) dão `require` em `include/config.php`, então cada worker em background re-executa `check_q()`/`check_grab_queue()` no próprio host. Limitado pelo `q_limit` ativo, mas uma conversão disparada por request encadeia outras.

7. **Footgun de painel**: salvar Settings > Video Conversion (`siteadmin/modules/index/media.php:76,184`) chama `update_config()` (`include/function_admin.php:422-443`), que **reescreve o `config.local.php` inteiro do host** que serve o painel. Qualquer save futuro na VM sobrescreve um `conversion_q = '0'` editado à mão.

### O que `conversion_q = '0'` na VM resolve (e o que não resolve)
- ✅ Para o polling `check_q()` por request (`include/config.php:100`).
- ✅ Para a VM apagar linhas da fila do PC cujo arquivo não está local.
- ❌ NÃO para o trabalho de grab disparado por `check_grab_queue()` (tráfego real na VM).
- ❌ NÃO para conversão inline quando a VM clama/processa um grab ou um reprocess é acionado pelo painel da VM — nesse caso roda `convert_videos.php` direto.

### Condição para "a VM nunca processa"
Além de `conversion_q = '0'`, a VM não pode clamar jobs de grab nem executar os spawns web. Isso exige gate por host em `check_q()` **e** em `check_grab_queue()` (flag de papel/role), ou então a combinação operacional: `realtime_enabled = 0` no DB + grabber_cron só no crontab do PC + nenhuma ação de grab/reprocess no painel da VM.

### Decisão registrada (sem mudança de código nesta rodada)
Decidido: adotar **papel explícito de host** (`worker_role`) como solução estrutural. `conversion_q` **não** será usado como interruptor por host — mantém o sentido atual de modo de fila no PC (`'1'` = enfileirar em `conversion_queue_fp`).

- **`worker_role = 'converter'` (PC local)** — único host que:
  - consome `conversion_queue_fp`/`conversion_queue_sp` (`check_q()`);
  - dispara `check_grab_queue()` e executa `grabber_cron`/`grabber_worker` (claims de jobs de grab);
  - executa conversão/FFmpeg.
- **`worker_role = 'web'` (VM GCloud)** — não consome fila, não clama job de grab, não executa `grabber_cron`/`grabber_worker` nem conversão. A VM fica **aguardando o processamento local**: os jobs permanecem nas filas compartilhadas (sem `DELETE` por `file_exists` falso) até o PC retirá-los e processá-los.
- **Default fail-closed**: host sem a chave NÃO processa (equivale a `'web'`). Por isso o PC precisa declarar `'converter'` explicitamente no próprio `include/config.local.php`, e a VM declara `'web'`. Isso garante que uma VM "esquecida" nunca volte a processar.

#### Plano de implementação (próxima rodada de código)
1. `include/config.php` (~linha 100): gate nas duas chamadas por request — `check_q()` só se `role == 'converter' && conversion_q == '1'`; `check_grab_queue()` só se `role == 'converter'`. Manter os `require` como hoje para não quebrar fluxos que chamam `insert_into_q_fp()` no host web.
2. `scripts/grabber_cron.php` (após includes): guarda de saída `exit(0)` se `role != 'converter'` — cobre crontab da VM e "Process Now" acionado pelo painel da VM.
3. `scripts/grabber_worker.php` (topo): guarda de saída se `role != 'converter'` — cobre reprocess/grab acionados pelo painel da VM. O worker já dá `require` em `function_queue.php`, então não há fatal.
4. Configs por host: `include/config.local.php` do repo (PC) ganha `$config['worker_role'] = 'converter';`; o da VM ganha `$config['worker_role'] = 'web';` (edição via ssh — o `deploy.sh` preserva o `config.local.php` da VM); `include/config.template.php` registra a chave para instalações novas.
5. Conferir `update_config()` (`include/function_admin.php:422-443`) para garantir que a chave `worker_role` sobrevive a saves de Settings no painel (o método reescreve o `config.local.php` inteiro a partir do `$config` carregado).

#### Efeitos colaterais previstos
- Ações de grab / reprocess / "Process Now" no painel da **VM** deixam de processar — passar a operá-las pelo painel localhost do **PC** (intenção arquitetural).
- Upload feito na VM (host `'web'`) não converte — uploads devem ocorrer no PC, onde o arquivo fica acessível ao conversor.
- Enquanto a implementação não sai, **não aplicar `conversion_q = '0'` isolado na VM**: nos fluxos de `grabber_worker.php`/`modules/upload/video.php` isso dispara `convert_videos.php` direto na VM (efeito contrário ao desejado).

### Checks de runtime para fechar a auditoria (na VM)
- `cat include/config.local.php` — `conversion_q` / `q_limit` atuais da VM;
- `crontab -l && ls /etc/cron.d/` — existe cron de grab/conversão na VM?;
- `mysql -e "SELECT setting_key, setting_value FROM grabber_settings WHERE setting_key='realtime_enabled'"`;
- `pgrep -af ffmpeg` durante um processamento iniciado no PC.

---

## 5. Resumo das alterações
- Prévia de vídeo no hover: mantida; não foi removida.
- Trio vertical: handlers de rotação no hover removidos/neutralizados; trio segue sendo preparado uma vez no carregamento.
- Mixvazadas mass grabber: alinhado ao padrão dos demais para preenchimento de duração durante o enrichment, somente quando o tempo for legível na página.
- Fila/worker: sem mudança de código nesta rodada. Auditoria completa de disparo do FFmpeg concluída — inclui a correção semântica de `conversion_q` (seletor de modo de fila, não interruptor por host) e o mapa de caminhos alternativos de processamento na VM (`check_grab_queue`, `grabber_worker` via reprocess/grab, painel, cron local).

---

## 6. Pontos de verificação
- Prévia de vídeo:
  - id do card;
  - existência dos arquivos webm/mp4;
  - ausência de `img-private`;
  - ausência de erro JS.
- Trio:
  - renderização correta do `xb-trio`;
  - sem troca de frames no hover;
  - correto comportamento de randomização uma vez por renderização.
- Mixvazadas:
  - novo discovery retorna `duration` quando a página expõe tempo;
  - lista de descoberta reflete valor não zerado.

---

## 7. Observações
Faça testes de smoke manual no XAMPP após alterações em JS/PHP.  
Não há suite automatizada para validação; vou considerar gap de verificação automática.

Próxima etapa (decisão registrada na seção 4): implementar o papel explícito de host `worker_role` — `'converter'` no PC (único processador) e `'web'` na VM (aguarda o processamento local), com default fail-closed. Arquivos: `include/config.php` (gates de `check_q()`/`check_grab_queue()`), `scripts/grabber_cron.php` e `scripts/grabber_worker.php` (guardas de saída), `include/config.local.php` do PC + `include/config.template.php`, e edição via ssh do `config.local.php` da VM (preservado pelo `deploy.sh`).

Demais etapas possíveis:
- ajustar a escolha do trio no PHP para randomização estável por request;
- confirmar se algum site de massa que “funciona” traz tempo do discovery e comparar comportamento lado a lado;
- executar os checks de runtime da seção 4 na VM (crontab, `realtime_enabled`, `pgrep`) antes de implementar o `worker_role`.
