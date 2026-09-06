# AVSCMS 8.2 — AGENTS.md

Stack: PHP + MySQL rodando em XAMPP (`/Applications/XAMPP/xamppfiles/htdocs/avscms`). CMS de video sharing legado, sem build step, sem testes automatizados.

## Estrutura relevante
- `*.php` na raiz: entrypoints (video.php, upload.php, signup.php, ajax.php, etc.)
- `include/`, `classes/`, `modules/`, `templates/`, `language/`, `siteadmin/`, `sql/`
- `.opencode/ia/`: contrato de comportamento do agente (ler antes de editar, diff mínimo, risk tiers)
- `.opencode/skills/`: skills carregáveis via tool `skill`
- `.opencode/projeto/`: memória específica deste projeto

## Regras de atuação (resumo, detalhe em .opencode/)
1. Ler arquivo antes de editar. Preferir edit a create. Diff mínimo, sem refactor adjacente.
2. Classificar risco antes de agir: Low (ed edição local) / Medium (shared paths) / High (SQL destrutivo, rm, push, deploy) — High exige confirmação explícita.
3. Nunca expor credenciais. Nunca `DROP TABLE`, `rm -rf`, `force-push` sem aprovação.
4. Verificação: `php -l` no arquivo alterado; smoke manual via XAMPP quando aplicável. Sem suite de testes — declarar gap.
5. Comunicação: direta, answer-first, referência `file:line`, sem emoji.

## Skills — quando carregar via tool skill
- `coding-agent-standards`: toda edição/criação de código.
- `verification-agent`: antes de signoff (Quick Smoke por padrão; Targeted se multi-arquivo; Deep se auth/billing/migration).
- `prompt-architect`: ao criar/refinar prompts reutilizáveis.

## Instruções carregadas via opencode.json
`opencode.json` referencia `AGENTS.md`, `.opencode/projeto/*.md`, `.opencode/ia/*.md`, `.opencode/skills/README.md` e prompts coordenador/sistema. Não duplicar conteúdo aqui — detalhe vive nesses arquivos.
