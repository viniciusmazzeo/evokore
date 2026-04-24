# Etapa 4 - Frontend Admin

## Stack

- Vite
- React
- TypeScript
- Tailwind CSS
- shadcn/ui

## Estrutura

Frontend em `public_html/evokore/app/src`:

- `components/`
- `pages/`
- `layouts/`
- `services/`
- `hooks/`
- `lib/`
- `styles/`

## Telas Atuais

- `CadastroPage`:
  - escopo de cliente/unidade
  - cadastro de unidade
  - cadastro de credencial EVO (DNS + token)
  - rotacao de token de acesso da unidade (n8n)
  - listagem de logs com filtros
- `DashboardPage`:
  - indicadores, graficos e ranking
  - filtros por cliente/unidade/periodo
  - exportacao de relatorio CSV
- `TesteEvoPage`:
  - selecao de unidade
  - geracao de token de teste
  - consulta por CPF em `financial/status` e `financial/link`
  - retorno JSON em tela para validacao

## Integracao API

Servicos:

- `src/services/adminApi.ts`
- `src/services/financialApi.ts`

Principais metodos:

- `listClients`
- `listUnits`
- `createUnit`
- `listUnitCredentials`
- `createUnitCredential`
- `getUnitAccessToken`
- `rotateUnitAccessToken`
- `listLogs`
- `getDashboardSummary`
- `getFinancialStatusByCpf`
- `getFinancialLinkByCpf`

