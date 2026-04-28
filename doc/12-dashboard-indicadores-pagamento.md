# Etapa 12 - Dashboard de Pagamentos e Conversao

## Objetivo

Exibir indicadores de recuperacao no periodo filtrado:

1. Links gerados
2. Valor inadimplente
3. Valor regularizado
4. Conversao por quantidade e valor

## Backend

### Endpoints

- `GET /admin/clients`
- `GET /admin/units?client_id=<id>`
- `GET /admin/dashboard?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD&client_id=<id>&unit_id=<id>`

### Autenticacao

- Painel: sessao autenticada (`/admin/auth/login`).
- Automacao server-to-server: `x-api-key` quando habilitado.

### Fonte de dados

Tabela principal: `debtor_events`.

Eventos considerados:

- `DELINQUENT_FOUND`
- `PAYMENT_LINK_SENT`
- `REGULARIZED`

## Frontend

### Pagina

- `src/pages/DashboardPage.tsx`

### Funcionalidades

- Filtros por cliente, unidade e periodo.
- Cards com KPIs principais.
- Bloco de recuperacao por valor.
- Grafico por unidade (top por valor).
- Tabela detalhada por unidade.
- Exportacao CSV.
- Mensagens de status/sucesso/erro.

## Observacoes importantes

- Se `debtor_events` estiver vazio, o dashboard retorna zerado.
- Dashboard de inadimplentes e separado dos dashboards de vendas e aulas.
- Conversao de vendas pagas agora e atualizada automaticamente via webhook EVO (documentado na etapa 13 e 15).
