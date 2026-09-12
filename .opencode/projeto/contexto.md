# AVSCMS — Contexto do Projeto

## Stack
- PHP legado (sem namespace na maioria dos arquivos, sem composer, sem build).
- MySQL remoto (Cloud SQL) exposto em `127.0.0.1:3307` por `./tunnel.sh`; o MariaDB do XAMPP é residual. Entrypoints `.php` na raiz + `include/` (config, functions), `classes/`, `modules/`, `templates/` (Smarty-like), `language/`, `siteadmin/`, `sql/` (schema/migrations manuais).
- Ambiente local: `/Applications/XAMPP/xamppfiles/htdocs/avscms`, acesso via `http://localhost/avscms`.

## Convenções
- Não introduzir build step, framework novo ou refactor amplo sem pedido explícito.
- Manter estilo do entorno (naming, includes via `require/include`, SQL inline).
- OWASP: escapar output, prepared statements onde houver wrapper; nunca concatenar `$_GET/$_POST` em SQL/shell.

## Risco neste repo (sempre classificar antes de agir)
- Low: edição local em template/language, `php -l`, leitura.
- Medium: mudança em `include/`, `classes/`, `modules/`, entrypoints compartilhados — exige teste do fluxo afetado + adjacente.
- High: `sql/` destrutivo, `rm`, `siteadmin/` com efeito produção, push/deploy — exige confirmação explícita do usuário.

## Verificação padrão (sem suite automatizada)
1. `php -l <arquivo>` no arquivo alterado.
2. Quick Smoke: smoke manual do fluxo via XAMPP.
3. Targeted se multi-arquivo: fluxo alterado + 1 adjacente + 1 caso de erro.
4. Declarar gap: "sem testes automatizados, verificação limitada a lint + smoke".

## Memória viva (atualizar ao descobrir)
- Goal atual: ativação automática do .opencode via opencode.json.
- Decisão: `opencode.json: instructions` carrega AGENTS.md + projeto/ia/skills/sistema/coordenador; `permission.skill * : allow`.
- MD3 no tema: header.tpl/enter.tpl carregam Roboto + Material Symbols Rounded (Google Fonts) e css/pornozinho-md3.css (tokens --md-sys-*); js/md3-ripple.js roda em todas as páginas (seletor RIPPLE_SELECTOR inclui .avs-settings-tab/.avs-settings-item). embed.tpl não carregava MD3 — fontes adicionadas em seu head.
- Player mediabunny usa MD3 no menu de configurações (abas Qualidade/Velocidade, Material Symbols para ícones/check, tokens --md-sys-* com fallback). Se novos componentes do player usarem Material Symbols, manter `embed.tpl` com as fontes.
- Mini player no site (substitui o PiP nativo, removido): botão `data-action="mini"` + `.avs-mini-actions` (expandir/fechar) em `video_mbplayer.tpl`; classe `.avs-mini-mode` fixa o player no canto ao rolar (IntersectionObserver, "enquanto toca" + preferência `avs_mini_auto`, localStorage default on); persistência `sessionStorage.avs_mini_state` {vid,source,time,href,ts} gravada ~5s + pagehide/visibilitychange, limpa em pause/end/X; resume na página do vídeo via `seekToTime(time,true)` no boot. `avs-mini.js`+`avs-mini.css` (no `footer.tpl`, hoje 1.1.0) recriam a janelinha nas demais páginas com `<video>` nativo, retomando do tempo salvo.
- ARMADILHA do mini: NUNCA observar o próprio player para decidir SAIR do mini. Ao entrar em `.avs-mini-mode` ele vira `position: fixed` no canto, volta a intersectar o viewport e o observer manda sair — laço medido em 141 ativações/141 desativações em 8s (o player "piscando"). Entrar: observar o player enquanto ele está no fluxo. Sair: observar `.avs-mini-spacer`, div que reserva o lugar do player (não se move por nossa causa e de quebra evita o salto de layout). Para testar, contar transições de classe com MutationObserver — a olho nu não dá para ver que é laço.
- Paridade dos dois minis: o usuário vê um só mini player, então `avs-mini.css` espelha `.avs-mini-mode` (400px, right/bottom 16/88, raio 12px, expandir/fechar como overlay em 8/8 com botões 30x30). Mexeu em um, conferir o outro.
- Aberto: mapear fluxos críticos (upload, auth, player) e arquivos de config reais em `include/`.
- Banco: NÃO é o MariaDB do XAMPP (`var/mysql` local é residual e `mysql.server start` falha com Errcode 13). O site usa o Cloud SQL remoto, exposto em `127.0.0.1:3307` por `./tunnel.sh` (`status`/`stop` também); `include/config.db.php` lê credenciais do `.env` (DB_HOST=127.0.0.1, DB_PORT=3307, DB_NAME=avs). Leitura local: `set -a; . ./.env; set +a` + `/Applications/XAMPP/xamppfiles/bin/php` (o `php` do CLI não está no PATH).
- Armadilha de include: `include/function_global.php` declara 36 funções incondicionais no topo, o que dispara early binding — guard `if (defined(...)) { return; }` NÃO evita o redeclare. O guard precisa envolver as declarações (`if ( !defined('AVS_FUNCTION_GLOBAL_LOADED') ) { ... }`). Sem isso, o `require_once` de `config.php` (rotação de capas) + o `require` dos entrypoints fataliza com "Cannot redeclare get_request()" e derruba TODAS as páginas em 500.
- Mediabunny empacotado local: `media/player/mediabunny/mediabunny.min.js` (v1.55.6, de `cdn.jsdelivr.net/npm/mediabunny@1.55.6/dist/bundles/mediabunny.min.mjs`), importado por `avs-player.js` via `./mediabunny.min.js?ver=<versão da lib>`. NÃO voltar para CDN: import estático que falha impede o módulo INTEIRO de executar (tela ficava só com "desative bloqueadores"; reproduzido com `Network.setBlockedURLs` no probe). Manter extensão `.js`, não `.mjs`: o Apache do XAMPP não tem MIME para `.mjs` (serve sem `Content-Type` e o browser recusa módulo); `.js` sai como `application/x-javascript`, que é válido.
- Cache-buster do player: `avs-player.{css,js}?ver=N` em `video_mbplayer.tpl` (hoje 3.2.2) precisa subir a cada edição dos arquivos — um teste com perfil de navegador reaproveitado mediu o layout ANTIGO e só acusou o fix depois de limpar o cache.
- Fallback do player no `video_mbplayer.tpl`: `avs-player.js` marca `window.__avsModuleLoaded` ao subir; o template acusa bloqueador só se o módulo NÃO rodou em ~8s, e aviso de lentidão só em ~45s, parando quando `__avsReady` vira true. Antes era um `setTimeout` de 10s cego que mentia sobre bloqueador em carga lenta (e seguia rodando depois do player pronto).
- Menu de configurações do player: as 3 abas (ícone + rótulo) pedem ~336px. Painel de 280px + `flex: 1 1 0` (não encolhe abaixo do `min-content`) + faixa `overflow-x: visible` faziam a aba "Reprodução" sair ~75px para fora do painel. Estado atual: painel `min(360px, calc(100% - 24px))`, faixa com `overflow-x: auto` e aba `flex: 1 0 auto`. Ao mexer, MEDIR (`scrollWidth` vs `clientWidth`, e a borda direita de cada aba vs a borda interna do painel) — não confiar em inspeção visual.
- Viewport do tema (pré-existente, `header.tpl`): `content="width=1280, initial-scale=1, maximum-scale=1, user-scalable=no"`. No celular o layout é 1280px escalado, então as `@media (max-width: 767px/575px)` do player NUNCA casam no mobile — regra de responsividade tem que caber no layout de 1280, não em media query de viewport.
- Estado do player 12/09: `__avsReady` vira true em ~1,7s local; com o bundle local o player carrega inclusive com jsdelivr bloqueado (módulo/ready true, sem mensagem).
- Smoke 12/09 (túnel 3307 ativo): home 200, `/video/156/<slug>` 200 com avs-player.js/css + avs-mini.js/css (200), videos/categories/login/signup 200, `/pagina-inexistente` 404, sem fatals novos no `php_error_log`. Warnings pré-existentes (search_type, ad, autoplay em video_mbplayer.tpl) não vêm do diff. Verificação segue sem suite automatizada (lint + smoke).
