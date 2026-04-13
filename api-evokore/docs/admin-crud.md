# Admin CRUD API

Base local: `http://127.0.0.1:8080`

Header obrigatorio:

```http
x-api-key: ADMIN_API_TOKEN
```

## Clients

- `GET /admin/clients?status=ACTIVE&q=pan`
- `POST /admin/clients`
- `GET /admin/clients/{id}`
- `PUT /admin/clients/{id}`

Exemplo `POST /admin/clients`:

```json
{
  "name": "Panobianco",
  "legal_name": "Panobianco Franchising LTDA",
  "document_type": "CNPJ",
  "document_number": null,
  "status": "ACTIVE"
}
```

## Units

- `GET /admin/units?client_id=1&status=ACTIVE`
- `POST /admin/units`
- `GET /admin/units/{id}`
- `PUT /admin/units/{id}`
- `GET /admin/units/{id}/access-token`
- `POST /admin/units/{id}/access-token` (gera/rotaciona token da unidade para n8n)

Exemplo `POST /admin/units`:

```json
{
  "client_id": 1,
  "unit_code": "PAN-CAMPINAS-CENTRO",
  "unit_name": "Panobianco Campinas Centro",
  "status": "ACTIVE",
  "timezone": "America/Sao_Paulo"
}
```

Exemplo `POST /admin/units/{id}/access-token`:

```json
{
  "expires_at": "2026-12-31 23:59:59"
}
```

Retorno inclui `token` apenas no momento da geracao.

## Unit Credentials

- `GET /admin/unit-credentials?unit_id=1&is_active=1`
- `POST /admin/unit-credentials`
- `GET /admin/unit-credentials/{id}`
- `PUT /admin/unit-credentials/{id}`

Exemplo `POST /admin/unit-credentials`:

```json
{
  "unit_id": 1,
  "evo_dns": "PANOBIANCOS",
  "token": "TOKEN-DA-UNIDADE",
  "is_active": 1
}
```

Observacoes:

- Quando uma credencial ativa (`is_active=1`) e criada/atualizada para a unidade, as anteriores da mesma unidade sao desativadas.
- O retorno nao expõe token completo, apenas `token_hint`.

## Integration Logs

- `GET /admin/logs`
- Filtros suportados:
  - `client_id`
  - `unit_id`
  - `provider` (`EVO`, `N8N`, `SYSTEM`)
  - `success` (`0` ou `1`)
  - `http_status`
  - `date_from` (`YYYY-MM-DD`)
  - `date_to` (`YYYY-MM-DD`)
  - `q` (busca em endpoint, request_id e erro)
  - `page`
  - `per_page` (max `100`)

Exemplo:

```txt
GET /admin/logs?provider=SYSTEM&success=1&page=1&per_page=20
```
