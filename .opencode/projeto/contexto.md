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
- Aberto: mapear fluxos críticos (upload, auth, player) e arquivos de config reais em `include/`.
