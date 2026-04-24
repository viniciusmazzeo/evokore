# Etapa 12 - Dashboard de Pagamentos e Conversão

## Objetivo
Exibir no dashboard os indicadores de recuperação:

1. Quantidade de links gerados no período.
2. Valor total inadimplente no período.
3. Valor regularizado no mesmo período.
4. Conversão de links gerados em pagamentos.

## Backend

### Endpoints adicionados

- `GET /admin/clients`
- `GET /admin/units?client_id=<id>`
- `GET /admin/dashboard?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD&client_id=<id>&unit_id=<id>`

### Autenticação

- Header obrigatório: `x-api-key`
- Token validado com `FINANCIAL_ENDPOINT_TOKEN`.

### Regras do dashboard

Fonte principal: tabela `debtor_events`.

Eventos considerados:

- `DELINQUENT_FOUND`
- `PAYMENT_LINK_SENT`
- `REGULARIZED`

Indicadores calculados:

- `links_generated`
- `delinquent_count`
- `delinquent_amount`
- `regularized_count`
- `regularized_amount`
- `conversion_by_links`
- `conversion_by_amount`

Também retorna `by_unit` com detalhamento por unidade (top e tabela).

## Frontend

### Página nova

- `src/pages/DashboardPage.tsx`

### Funcionalidades

- Filtros por cliente, unidade e período.
- Cards com KPIs principais.
- Bloco visual de recuperação por valor.
- Gráfico simples por unidade (top 5).
- Tabela de detalhamento por unidade.
- Exportação CSV do relatório.

### Serviço novo

- `src/services/adminApi.ts` (consumo de `/admin/*` com `x-api-key`)

## Navegação

Menu no topo com duas áreas:

- `Dashboard`
- `Cadastro` (estrutura inicial para próximos CRUDs)

Arquivos:

- `src/components/AppHeader.tsx`
- `src/layouts/AppLayout.tsx`
- `src/App.tsx`
- `src/pages/CadastroPage.tsx`

## Teste manual rápido

1. Acesse o frontend.
2. Abra `Dashboard`.
3. Escolha cliente, unidade e datas.
4. Clique em `Atualizar Dashboard`.
5. Valide cards, gráfico e tabela.
6. Clique em `Exportar Relatório` e valide o CSV.

## Observações

- Se `debtor_events` não existir ou não tiver colunas mínimas, o endpoint retorna dashboard zerado com motivo em `meta.reason`.
- Lint do frontend depende de `eslint` instalado localmente.
