# Etapa 1 - Visao Geral

## Objetivo do sistema

Centralizar operacao de inadimplencia, vendas de planos e aulas experimentais por:

- Cliente
- Unidade
- Integracao EVO + n8n

## Conceitos-chave

- Um cliente possui varias unidades.
- Cada unidade possui:
  - credencial EVO (`evo_dns` + `token` armazenado cifrado)
  - token interno de integracao (unit token para n8n/endpoints financeiros)
- Logs e eventos ficam persistidos para auditoria, dashboards e operacao.

## Estado atual implementado

- CRUD administrativo de clientes/unidades/credenciais.
- Login administrativo por sessao (`/admin/auth/*`) com opcao de token para automacao.
- Dashboards separados:
  - inadimplentes
  - vendas
  - aulas
  - logs (com detalhe por modal no frontend)
- Fluxo de venda com plano online e persistencia em `plan_sales`.
- Fluxo de aula experimental com persistencia em `trial_classes`.
- Webhook EVO para confirmacao automatica de pagamento e conciliacao de `plan_sales`.
- Endpoint de planos ativos/online por unidade (`/admin/units/{id}/evo-plans/active?online_only=1`).

## Documentos-chave para operacao

- `doc/13-fluxos-venda-e-aula-experimental.md`
- `doc/14-testes-finais-etapa-a-etapa.md`
- `doc/15-seguranca-e-validacao-webhook-evo.md`
- `doc/16-deploy-producao-checklist.md`
- `doc/05-testes-etapa-a-etapa.md`
- `doc/17-saas-multi-tenant-e-permissoes.md`
