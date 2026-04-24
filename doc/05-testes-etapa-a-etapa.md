# Etapa 5 - Testes Etapa a Etapa

## Etapa A - Backend online

1. Apache e MySQL iniciados no XAMPP.
2. Teste:

```powershell
curl.exe -i -H "x-api-key: SEU_TOKEN_ADMIN" "http://localhost/evokore/public_html/evokore/admin/clients"
```

Esperado: `200 OK`.

## Etapa B - Escopo no frontend

1. Abrir `http://localhost:5173`.
2. Verificar carregamento dos selects:
- Cliente
- Unidade

Esperado: valores preenchidos.

## Etapa C - Cadastro de unidade

1. Selecionar cliente.
2. Informar código e nome.
3. Salvar unidade.

Esperado: unidade criada e disponível no select.

## Etapa D - Credencial EVO da unidade

1. Selecionar unidade.
2. Informar `DNS EVO` e `Token EVO`.
3. Salvar credencial.

Esperado: exibir `token_hint` ativo no card.

## Etapa E - Token n8n da unidade

1. Selecionar unidade.
2. Rotacionar token de acesso.

Esperado: token exibido uma vez para cópia + `token_hint` ativo.

## Etapa F - Logs

1. Abrir bloco de logs.
2. Filtrar por provedor/status/unidade.
3. Carregar logs.

Esperado: lista coerente com filtros.

## Verificação em Banco

Validar no phpMyAdmin:

- `units`
- `unit_evo_credentials`
- `unit_api_tokens`
- `integration_logs`

## Etapa G - Fluxo n8n por token da unidade

1. Gere token da unidade em `POST /admin/units/{id}/access-token`.
2. Use esse token no header `x-api-key` para chamar:

```powershell
curl.exe -i -H "x-api-key: TOKEN_DA_UNIDADE" "http://localhost/evokore/public_html/evokore/financial/status?cpf=12345678901"
```

```powershell
curl.exe -i -H "x-api-key: TOKEN_DA_UNIDADE" "http://localhost/evokore/public_html/evokore/financial/link?cpf=12345678901"
```

Esperado:

- API identifica a unidade sem `unit_id`.
- Resposta retorna `unit_id` e `unit_code`.
- Token invalido ou expirado retorna `401`.
- `last_used_at` atualizado em `unit_api_tokens`.
- chamada registrada em `integration_logs` com unidade e status.
