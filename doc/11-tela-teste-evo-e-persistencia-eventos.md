# Etapa 11 - Tela de Teste EVO e Persistencia de Eventos

## Objetivo

Facilitar testes manuais da integracao n8n/EVO e registrar historico operacional no banco.

## Entregas

1. Nova tela `Teste EVO` no menu:
- selecao de cliente/unidade
- campo de CPF
- token da unidade para teste
- botao para gerar token de teste
- botoes para chamar:
  - `financial/status`
  - `financial/link`
- exibicao de retorno JSON em tela

2. Proxy do frontend:
- adicionado `/financial` no `vite.config.ts`.

3. Persistencia de eventos no backend:
- `financial/status` salva em `debtor_events`:
  - `DELINQUENT_FOUND` quando ha debito
  - `REGULARIZED` quando nao ha debito
- `financial/link` salva em `debtor_events`:
  - `PAYMENT_LINK_SENT` quando ha link de pagamento

4. Logs tecnicos:
- `financial/status` e `financial/link` continuam registrando em `integration_logs`.

## Teste manual (passo a passo)

1. Acesse a aba `Teste EVO`.
2. Selecione cliente e unidade.
3. Clique em `Gerar Token de Teste`.
4. Informe CPF de teste.
5. Clique em `Testar Financial Status`.
6. Clique em `Testar Financial Link`.
7. Verifique no banco:
- `integration_logs`
- `debtor_events`

