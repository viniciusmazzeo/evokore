# Etapa 3 - Backend, Banco e API

## Banco de Dados

Migracoes em `api-evokore/database/migrations`:

- `20260410_001_initial_schema.sql`
- `20260410_002_unit_api_tokens.sql`

Seeds em `api-evokore/database/seeds`:

- `20260410_001_seed_panobianco.sql`

## Tabelas Principais

- `clients`
- `units`
- `unit_evo_credentials`
- `unit_api_tokens`
- `integration_logs`
- `debtor_events`
- `monthly_reports`

## Endpoints Administrativos

Base: `http://localhost/evokore/public_html/evokore`

- `GET /admin/clients`
- `POST /admin/clients`
- `GET /admin/units`
- `POST /admin/units`
- `GET /admin/unit-credentials`
- `POST /admin/unit-credentials`
- `GET /admin/units/{id}/access-token`
- `POST /admin/units/{id}/access-token`
- `GET /admin/logs`
- `GET /admin/dashboard-summary`

## Fluxo Oficial n8n -> EvoKore -> EVO

1. n8n chama nossa API financeira com `x-api-key`.
2. Esse valor precisa ser o token da unidade gerado em `unit_api_tokens`.
3. O backend identifica automaticamente a unidade pelo hash do token.
4. O backend carrega a credencial EVO ativa da unidade em `unit_evo_credentials`.
5. A chamada para EVO usa os dados da unidade:
- `evo_dns`
- `token_encrypted` (token EVO cadastrado)

## Endpoints Financeiros (EVO)

- `GET/POST /financial/status`
- `GET/POST /financial/link`

Regras atuais:

- Nao precisa enviar `unit_id` nem `unit_code` nesses endpoints.
- A unidade e resolvida pelo token recebido no header `x-api-key`.
- Token invalido/inativo/expirado retorna `401`.
- Quando token valido, o backend atualiza `last_used_at` em `unit_api_tokens`.
- Os endpoints registram log tecnico em `integration_logs`.
- Os endpoints persistem historico em `debtor_events`:
  - status: `DELINQUENT_FOUND` ou `REGULARIZED`
  - link: `PAYMENT_LINK_SENT`

## Seguranca

- Endpoints admin continuam protegidos por token admin.
- Endpoints financeiros agora usam token por unidade.
- Resposta em JSON e rastreio por logs de integracao.
