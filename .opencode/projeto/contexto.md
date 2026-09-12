# AVSCMS — Contexto do Projeto

## Stack
- PHP legado (sem namespace na maioria dos arquivos, sem composer, sem build).
- MySQL via XAMPP. Entrypoints `.php` na raiz + `include/` (config, functions), `classes/`, `modules/`, `templates/` (Smarty-like), `language/`, `siteadmin/`, `sql/` (schema/migrations manuais).
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
- Aberto: mapear fluxos críticos (upload, auth, player) e arquivos de config reais em `include/`.
