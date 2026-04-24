# Etapa 14 - Testes Finais Etapa a Etapa

## Premissas

1. Backend rodando no XAMPP:
- URL base: `http://localhost/evokore/public_html/evokore`

2. Frontend rodando:
- URL: `http://localhost:5173`

3. Token admin para endpoints `/admin/*`:
- Header: `x-api-key: <FINANCIAL_ENDPOINT_TOKEN>`

4. Token da unidade para fluxos n8n:
- Header: `x-api-key: <TOKEN_DA_UNIDADE>`

---

## Etapa 1 - Sanidade dos endpoints admin

PowerShell:

```powershell
curl.exe -i -H "x-api-key: SEU_TOKEN_ADMIN" "http://localhost/evokore/public_html/evokore/admin/clients"
curl.exe -i -H "x-api-key: SEU_TOKEN_ADMIN" "http://localhost/evokore/public_html/evokore/admin/units?client_id=1"
curl.exe -i -H "x-api-key: SEU_TOKEN_ADMIN" "http://localhost/evokore/public_html/evokore/admin/units/1/evo-plans"
curl.exe -i -H "x-api-key: SEU_TOKEN_ADMIN" "http://localhost/evokore/public_html/evokore/admin/dashboard/plan-sales?start_date=2026-04-01&end_date=2026-04-30"
curl.exe -i -H "x-api-key: SEU_TOKEN_ADMIN" "http://localhost/evokore/public_html/evokore/admin/dashboard/trials?start_date=2026-04-01&end_date=2026-04-30"
```

Esperado:
- HTTP 200
- JSON com `data`

---

## Etapa 2 - Fluxo de venda (n8n -> API -> EVO)

```powershell
curl.exe -i -X POST `
  -H "x-api-key: SEU_TOKEN_UNIDADE" `
  -H "Content-Type: application/json" `
  -d "{\"cpf\":\"54325699813\",\"customer_name\":\"Cliente Teste\",\"phone\":\"11999999999\",\"email\":\"cliente@teste.com\",\"plan_name\":\"Plano Gold\",\"plan_value\":199.90}" `
  "http://localhost/evokore/public_html/evokore/n8n/sales/plan"
```

Esperado:
- HTTP 201
- Campo `sale_id`
- Campo `payment_link` quando EVO retornar link

---

## Etapa 3 - Callback de status da venda (pagamento confirmado)

Use o `sale_id` retornado na etapa 2.

```powershell
curl.exe -i -X POST `
  -H "x-api-key: SEU_TOKEN_UNIDADE" `
  -H "Content-Type: application/json" `
  -d "{\"sale_id\":1,\"status\":\"PAID\",\"paid_value\":199.90,\"paid_at\":\"2026-04-14 16:00:00\",\"status_note\":\"Pagamento confirmado\"}" `
  "http://localhost/evokore/public_html/evokore/n8n/sales/plan/status"
```

Esperado:
- HTTP 200
- `message: "Status da venda atualizado com sucesso."`

---

## Etapa 3B - Confirmacao automatica via webhook EVO (sem n8n)

Use o token do webhook (`WEBHOOK_TOKEN`) no header `x-webhook-token`.

Exemplo:

```powershell
curl.exe -i -X POST `
  -H "x-webhook-token: SEU_WEBHOOK_TOKEN" `
  -H "Content-Type: application/json" `
  -d "{\"idVenda\":12345678,\"status\":\"PAID\",\"paid_value\":199.90,\"paid_at\":\"2026-04-23 10:30:00\",\"referenceCode\":\"ABC123\"}" `
  "http://localhost/evokore/public_html/evokore/webhook/evo"
```

Esperado:
- HTTP 200
- Campo `reconciliation.matched = true` quando encontrar a venda
- Campo `reconciliation.new_status = PAID` para evento de pagamento

---

## Etapa 4 - Fluxo de aula experimental

```powershell
curl.exe -i -X POST `
  -H "x-api-key: SEU_TOKEN_UNIDADE" `
  -H "Content-Type: application/json" `
  -d "{\"cpf\":\"54325699813\",\"customer_name\":\"Cliente Teste\",\"phone\":\"11999999999\",\"email\":\"cliente@teste.com\",\"preferred_date\":\"2026-04-20\",\"preferred_time\":\"18:30\"}" `
  "http://localhost/evokore/public_html/evokore/n8n/sales/trial"
```

Esperado:
- HTTP 201
- Campo `trial_id`

---

## Etapa 5 - Callback de status da aula

Use o `trial_id` da etapa 4.

```powershell
curl.exe -i -X POST `
  -H "x-api-key: SEU_TOKEN_UNIDADE" `
  -H "Content-Type: application/json" `
  -d "{\"trial_id\":1,\"status\":\"COMPLETED\",\"trial_date\":\"2026-04-20\",\"trial_time\":\"18:30\",\"status_note\":\"Aula realizada\"}" `
  "http://localhost/evokore/public_html/evokore/n8n/sales/trial/status"
```

Esperado:
- HTTP 200
- `message: "Status da aula experimental atualizado com sucesso."`

---

## Etapa 6 - Validacao visual no frontend

1. Abrir `Venda de Planos`.
2. Confirmar se o seletor de plano carregou dados da EVO.
3. Rodar formulario de teste de venda.
4. Rodar formulario de callback de status.

6. Abrir `Aula Experimental`.
7. Rodar formulario de teste de aula.
8. Rodar formulario de callback de status.
9. Atualizar dashboard de aulas.
10. Confirmar cards/tabela (total, concluidas, taxa).

11. Alternar tema claro/escuro no header.
12. Validar logo e contraste dos componentes.

---

## Etapa 7 - Validacao no banco (phpMyAdmin)

Conferir tabelas:
- `plan_sales`
- `trial_classes`

Campos para confirmar:
- `status`
- `updated_at`
- `paid_value` / `paid_at` (plan_sales)
- `trial_date` / `trial_time` (trial_classes)

---

## Resultado esperado da etapa

1. Dois fluxos completos (venda e aula) funcionando de ponta a ponta.
2. Atualizacao de status refletindo nos dashboards.
3. Front com paginas separadas e tema visual final.
