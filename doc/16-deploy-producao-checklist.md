# Etapa 16 - Deploy Producao Checklist

## Objetivo

Checklist unico para publicar backend/frontend com seguranca e validar ponta a ponta sem quebrar n8n.

## 1) Arquivos backend para subir

- `api-evokore/src/Controllers/WebhookController.php`
- `api-evokore/src/Controllers/N8nPlanSaleController.php`
- `api-evokore/src/Controllers/AdminManagementController.php`
- `api-evokore/src/Controllers/SecurityController.php`
- `api-evokore/src/Services/*` (arquivos alterados na release)
- `api-evokore/router.php` (se houver ajuste novo)
- `api-evokore/bootstrap.php` (se houver ajuste novo)

## 2) Migracoes para executar

Executar as migracoes novas da release em `api-evokore/database/migrations`.

Valida apos SQL:

- tabelas novas criadas
- colunas novas existentes
- indices e constraints aplicados

## 3) Variaveis de ambiente

Obrigatorias:

- `WEBHOOK_TOKEN`
- `FINANCIAL_ENDPOINT_TOKEN`
- `ADMIN_API_TOKEN`
- `TOKENS_ENC_KEY`

Seguranca/login:

- `ADMIN_SESSION_TTL_SECONDS` (se aplicado)
- `COOKIE_SECURE` em producao HTTPS

Recomendadas EVO:

- `EVO_AUTH_MODE=basic` (ou `auto`, conforme ambiente)
- `EVO_BASE_URL`
- `EVO_DNS_HEADER_NAME`
- `EVO_DEFAULT_BRANCH_ID`
- `EVO_PRO_REQUEST_HEADER_NAME=evoapipro-request`
- `EVO_PRO_REQUEST_HEADER_VALUE=<segredo enviado pela EVO>`

## 4) Frontend

Se o deploy usa `dist`:

1. Build local:

```bash
cd public_html/evokore/app
npm run build
```

2. Publicar:

- `public_html/evokore/app/dist/index.html`
- `public_html/evokore/app/dist/assets/*`

3. Limpar cache do navegador (`Ctrl+F5`).

## 5) Quality gate obrigatorio (antes de subir)

Backend:

```bash
cd api-evokore
vendor/bin/phpunit --testdox
```

Frontend:

```bash
cd public_html/evokore/app
npm run lint
npm run build
npm run test
```

Regra:
- se qualquer comando falhar, bloquear deploy

## 6) Validacao funcional rapida (pos deploy)

### 6.1 Admin auth

- `GET /admin/auth/me` sem login -> `401`
- login no frontend -> sucesso

### 6.2 Planos online

- `GET /admin/units/{id}/evo-plans/active?online_only=1`
- validar que retorna apenas planos online ativos conforme regra implementada

### 6.3 Venda

- executar venda de teste
- confirmar registro em `plan_sales`

### 6.4 Webhook EVO

- enviar payload de pagamento para `POST /webhook/evo`
- validar atualizacao de:
  - `status`
  - `paid_value`
  - `paid_at`

### 6.5 Dashboards

- dashboard inadimplentes carregando cliente/unidade
- dashboard vendas refletindo pagamento confirmado
- dashboard aulas sem erro de filtros
- dashboard logs abrindo modal com detalhes

### 6.6 Informacoes operacionais de unidade

- `GET /admin/units/{id}/evo-unit-info?id_branch=1`
- validar retorno de:
  - `class_types`, `teachers`, `schedule`
  - `operation_today`
  - `metadata_source`
  - `metadata_errors` (quando EVO nao retornar metadados)
- confirmar que **nao** retorna bloco de planos neste endpoint

## 7) Testes de seguranca rapidos

1. Webhook com token invalido -> `401`
2. Webhook com payload acima do limite -> `413`
3. Sem content-type json -> `415`
4. Rate limit webhook -> `429` quando estourar janela
5. Endpoint admin sem sessao -> `401`

## 8) Rollback

Se houver erro critico apos deploy:

1. Reverter arquivos backend para versao anterior
2. Reverter frontend (`dist` anterior)
3. Manter dados novos no banco (sem drop de coluna)
4. Desativar temporariamente webhook externo se necessario
5. Reexecutar checklist de validacao

## 9) Evidencias para fechar release

Guardar:

- print do login admin
- print do retorno `evo-plans/active?online_only=1`
- print do webhook `200` com `reconciliation.matched=true`
- print dos cards de vendas atualizados
- output dos testes (`phpunit` e `jest`)
- horario/data da publicacao
- print do `evo-unit-info` com `metadata_source` e `operation_today`
