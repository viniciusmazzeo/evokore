# EvoKore

Sistema SaaS para gestão de inadimplência por cliente/unidade, com integração EVO, emissão de token por unidade (n8n) e painel administrativo.

## Documentação

A documentação está organizada por etapas em [`doc/`](d:\xampp\htdocs\evokore\doc):

1. [`doc/01-visao-geral.md`](d:\xampp\htdocs\evokore\doc\01-visao-geral.md)
2. [`doc/02-ambiente-e-execucao.md`](d:\xampp\htdocs\evokore\doc\02-ambiente-e-execucao.md)
3. [`doc/03-backend-banco-e-api.md`](d:\xampp\htdocs\evokore\doc\03-backend-banco-e-api.md)
4. [`doc/04-frontend-admin.md`](d:\xampp\htdocs\evokore\doc\04-frontend-admin.md)
5. [`doc/05-testes-etapa-a-etapa.md`](d:\xampp\htdocs\evokore\doc\05-testes-etapa-a-etapa.md)
6. [`doc/06-roadmap-proximas-etapas.md`](d:\xampp\htdocs\evokore\doc\06-roadmap-proximas-etapas.md)
7. [`doc/07-dashboard-operacional.md`](d:\xampp\htdocs\evokore\doc\07-dashboard-operacional.md)
8. [`doc/08-navegacao-e-separacao-telas.md`](d:\xampp\htdocs\evokore\doc\08-navegacao-e-separacao-telas.md)
9. [`doc/09-dashboard-visual-e-relatorio.md`](d:\xampp\htdocs\evokore\doc\09-dashboard-visual-e-relatorio.md)
10. [`doc/10-fluxo-token-unidade-n8n-evo.md`](d:\xampp\htdocs\evokore\doc\10-fluxo-token-unidade-n8n-evo.md)
11. [`doc/11-tela-teste-evo-e-persistencia-eventos.md`](d:\xampp\htdocs\evokore\doc\11-tela-teste-evo-e-persistencia-eventos.md)

## Estrutura do Projeto

- `api-evokore/`: backend PHP
- `public_html/evokore/`: entrada HTTP no Apache/XAMPP
- `public_html/evokore/app/`: frontend React + Vite + TypeScript
- `doc/`: documentação funcional e técnica por etapas

## Execução Rápida (padrão atual)

1. Backend no XAMPP (Apache + MySQL).
2. Frontend via Vite em `public_html/evokore/app`.
3. Validar integração em `http://localhost:5173`.
