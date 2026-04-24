# Etapa 7 - Dashboard Operacional

## Entrega desta etapa

- Endpoint backend: `GET /admin/dashboard-summary`
- Filtros: `client_id`, `unit_id`, `date_from`, `date_to`
- Frontend:
  - Bloco "Dashboard de Inadimplencia"
  - KPIs consolidados
  - Conversao por quantidade e valor
  - Top unidades por inadimplencia

## KPIs retornados

- `delinquent_count`
- `delinquent_amount`
- `regularized_count`
- `regularized_amount`
- `payment_link_sent_count`
- `conversion_count_pct`
- `conversion_amount_pct`

## Teste manual rapido

1. Abrir frontend em `http://localhost:5173`.
2. Selecionar cliente/unidade no escopo.
3. Informar periodo (opcional).
4. Clicar em "Atualizar Dashboard".
5. Validar cards e tabela de top unidades.

## Observacao

Se ainda nao houver registros em `debtor_events`, os indicadores aparecem zerados. Isso e esperado nesta fase.

