# Etapa 15 - Seguranca e Validacao do Webhook EVO

## Objetivo
Garantir que a confirmacao automatica de pagamento (EVO -> `/webhook/evo`) atualize `plan_sales` com seguranca e sem quebrar os fluxos atuais do n8n.

## Validacoes de codigo executadas

1. Lint PHP (sintaxe):
- `api-evokore/src/Controllers/WebhookController.php`
- `api-evokore/src/Controllers/AdminManagementController.php`
- `api-evokore/src/Controllers/N8nPlanSaleController.php`
- `api-evokore/router.php`
- `api-evokore/bootstrap.php`

2. Revisao de seguranca no webhook:
- Token obrigatorio (`WEBHOOK_TOKEN`) validado com `hash_equals`.
- Header sensivel mascarado em log (`authorization`, `x-webhook-token`).
- Limitacao de taxa ativa (rate limit por IP/rota).
- Limite de tamanho do corpo (`WEBHOOK_MAX_BODY_BYTES`).
- Validacao de `Content-Type` para JSON.

3. Revisao de reconciliação:
- Matching por prioridade:
  - `evo_sale_id`
  - `payment_reference`
  - `evo_member_id`
  - fallback `cpf + plan_name`
- Protecao de status: se ja estiver `PAID/CONFIRMED`, nao regride para status inferior.

## Testes de seguranca recomendados em producao

### Teste A - Token invalido (deve negar)
```powershell
curl.exe -i -X POST `
  -H "x-webhook-token: token_invalido" `
  -H "Content-Type: application/json" `
  -d "{\"idVenda\":123,\"status\":\"PAID\"}" `
  "https://evokore.niokore.com/webhook/evo"
```
Esperado: `401 Unauthorized`.

### Teste B - Token valido e payload minimo (deve aceitar)
```powershell
curl.exe -i -X POST `
  -H "x-webhook-token: SEU_WEBHOOK_TOKEN" `
  -H "Content-Type: application/json" `
  -d "{\"idVenda\":123,\"status\":\"PAID\"}" `
  "https://evokore.niokore.com/webhook/evo"
```
Esperado: `200 OK` com `reconciliation`.

### Teste C - Confirmar persistencia no banco
Verificar na tabela `plan_sales`:
- `status`
- `paid_value`
- `paid_at`
- `last_webhook_at`
- `last_webhook_payload_json`

## Observacoes operacionais

- O fluxo novo nao remove nem altera o endpoint manual do n8n:
  - `POST /n8n/sales/plan/status` continua como fallback.
- A migration para reconciliacao deve estar aplicada:
  - `api-evokore/database/migrations/20260423_004_plan_sales_reconciliation.sql`.
