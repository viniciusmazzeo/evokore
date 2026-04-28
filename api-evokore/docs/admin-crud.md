# Admin CRUD API

Base local: `http://127.0.0.1:8080`

## Autenticacao do painel (etapa 1 e 2)

Login por sessao (frontend):

- `POST /admin/auth/login`
- `GET /admin/auth/me`
- `POST /admin/auth/logout`

Body de login:

```json
{
  "username": "admin",
  "password": "sua_senha"
}
```

Resposta de login/me:

```json
{
  "data": {
    "user": {
      "id": 1,
      "username": "admin",
      "display_name": "Administrador",
      "role": "admin"
    }
  }
}
```

Protecao de `/admin/*`:

- Sessao autenticada sempre funciona.
- Token por header ainda pode ser usado para automacoes quando habilitado.

Header de token (opcional para automacao server-to-server):

```http
x-api-key: ADMIN_API_TOKEN
```

Variaveis relacionadas:

- `ADMIN_ALLOW_TOKEN_AUTH=1` (padrao; aceita token alem da sessao)
- `ADMIN_SESSION_NAME=EVOKORESESSID`

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
- `GET /admin/units/{id}/evo-plans` (lista planos e precos da EVO para a unidade)
- `GET /admin/units/{id}/evo-plans/active` (lista somente planos comerciais ativos da unidade)
- `GET /admin/units/{id}/evo-plans/characteristics` (atalho para planos ativos + online com caracteristicas)
- `GET /admin/units/{id}/evo-unit-info` (horarios, infraestrutura, tipos de aulas e professores da unidade)

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

### Planos EVO por unidade

Endpoint:

```txt
GET /admin/units/{id}/evo-plans
```

Somente planos comerciais ativos:

```txt
GET /admin/units/{id}/evo-plans/active
```

Comportamento:

- Usa a credencial EVO ativa da unidade (`unit_evo_credentials`).
- Consulta a EVO e normaliza para:
  - `id`
  - 
ame`
  - `value`
  - `currency`
- No endpoint `/active`, aplica filtro para retornar somente planos com `value > 0` e, quando disponivel no payload, apenas status/flag ativo.
- Suporta payload EVO em formatos `items`, `data`, `results`, `list` e `lista` (incluindo retorno do `/api/v2/membership`).
- No frontend de teste de vendas, esses dados populam o seletor de planos.

Configuracao `.env`:

- `EVO_PLANS_ENDPOINTS` define a ordem de tentativa dos endpoints EVO.
- Para aumentar limite de requisicoes da EVO (sem bloqueio de 40/min), configure:
  - `EVO_PRO_REQUEST_HEADER_NAME=evoapipro-request`
  - `EVO_PRO_REQUEST_HEADER_VALUE=<valor fornecido pela EVO>`
- Ordem recomendada (atual):
  - `/api/v2/membership` (oficial)
  - `/api/v1/sales/plans`
  - `/api/v1/plans`
  - `/api/v1/services`
  - `/api/v1/products`
  - `/api/v1/memberships`

Observacao:
- Para categorias de planos na EVO, pode ser usado `GET /api/v1/membership/category` (quando necessario em tela/filtros futuros).

### Informacoes operacionais da unidade (horarios/infra/aulas/professores)

Endpoint:

```txt
GET /admin/units/{id}/evo-unit-info
```

Query params opcionais:

- `id_branch` (default: `EVO_DEFAULT_BRANCH_ID`)
- `date_from` (`YYYY-MM-DD`, opcional)
- `date_to` (`YYYY-MM-DD`, opcional)

Sem `date_from/date_to`, o endpoint tenta trazer a grade completa sem filtro de data. Se a EVO nao retornar nesse modo, aplica fallback automatico para uma janela padrao configuravel (`EVO_UNIT_INFO_DEFAULT_WINDOW_DAYS`, default `30`).

Exemplo:

```txt
GET /admin/units/3/evo-unit-info?id_branch=1&date_from=2026-04-24&date_to=2026-04-30
```

Exemplo sem filtro de datas:

```txt
GET /admin/units/3/evo-unit-info?id_branch=1
```

Retorno `200`:

```json
{
  "data": {
    "unit_id": 3,
    "unit_name": "Vila Antonieta",
    "unit_code": "PAN-AME-001",
    "id_branch": 1,
    "academy": {
      "client_id": 1,
      "client_name": "Panobianco",
      "client_legal_name": "Panobianco Franchising LTDA",
      "unit_id": 3,
      "unit_name": "Vila Antonieta",
      "unit_code": "PAN-AME-001",
      "id_branch": 1
    },
    "period": {
      "date_from": "2026-04-24",
      "date_to": "2026-04-30"
    },
    "address": {
      "street": "AV. INCONFIDENCIA MINEIRA",
      "number": "1885",
      "complement": null,
      "district": "Vila Antonieta",
      "city": "Sao Paulo",
      "state": "SP",
      "zip_code": "00000-000",
      "formatted": "AV. INCONFIDENCIA MINEIRA, 1885 - Vila Antonieta - Sao Paulo/SP"
    },
    "contacts": {
      "phone": "(11) 91659-0438",
      "whatsapp": "(11) 91659-0438",
      "email": "contato@academia.com.br"
    },
    "infrastructure": [
      "Musculacao",
      "Area de cardio",
      "Aulas coletivas",
      "Panobianco app"
    ],
    "has_parking": true,
    "class_types": [
      "Musculacao",
      "Funcional"
    ],
    "teachers": [
      "Professor A",
      "Professor B"
    ],
    "schedule": [
      {
        "date": "2026-04-24",
        "weekday": "sexta",
        "first_time": "06:00",
        "last_time": "21:00",
        "time_slots": ["06:00", "07:00", "08:00"]
      }
    ],
    "operation_today": {
      "date": "2026-04-24",
      "weekday": "sexta",
      "open_at": "06:00",
      "close_at": "21:00"
    },
    "metadata_source": "evo+config",
    "metadata_endpoint": "/api/v1/branches",
    "metadata_query": {
      "idBranch": "1"
    },
    "source": "evo"
  }
}
```

Fallback:

- Se a EVO estiver indisponivel, retorna `200` com:
  - `source: "fallback"`
  - `class_types`, `teachers` e `schedule` vazios
  - `infrastructure` preenchida por configuracao/fallback
  - `warning` e `details` com diagnostico.

Configuracao opcional de infraestrutura via `.env`:

- `UNIT_INFRASTRUCTURE_DEFAULT=Musculacao,Area de cardio,Aulas coletivas,Panobianco app`
- `UNIT_INFRASTRUCTURE_MAP_JSON={"by_unit_code":{"PAN-AME-001":["Musculacao","Area de cardio"]},"by_unit_id":{"3":["Musculacao","Aulas coletivas"]},"default":["Musculacao","Area de cardio"]}`
- `EVO_UNIT_INFO_DEFAULT_WINDOW_DAYS=30`
- `EVO_UNIT_INFO_ENDPOINTS=/api/v1/branches,/api/v1/branch,/api/v1/units,/api/v1/unit,/api/v1/gyms,/api/v1/gym,/api/v1/companies,/api/v1/company`
- `UNIT_ADDRESS_MAP_JSON={"by_unit_code":{"PAN-AME-001":{"street":"AV. INCONFIDENCIA MINEIRA","number":"1885","district":"Vila Antonieta","city":"Sao Paulo","state":"SP","zip_code":"00000-000","formatted":"AV. INCONFIDENCIA MINEIRA, 1885 - Vila Antonieta - Sao Paulo/SP"}}}`
- `UNIT_CONTACTS_MAP_JSON={"by_unit_code":{"PAN-AME-001":{"phone":"(11) 91659-0438","whatsapp":"(11) 91659-0438","email":"contato@academia.com.br"}}}`

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
- O retorno nao expõem token completo, apenas `token_hint`.

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

## Dashboard Summary

- `GET /admin/dashboard-summary`
- Filtros suportados:
  - `client_id`
  - `unit_id`
  - `date_from` (`YYYY-MM-DD`)
  - `date_to` (`YYYY-MM-DD`)

Exemplo:

```txt
GET /admin/dashboard-summary?client_id=1&date_from=2026-04-01&date_to=2026-04-30
```

Retorna:

- KPIs consolidados (inadimplentes, regularizados, conversoes)
- Top 10 unidades por valor inadimplente

## Endpoints Admin de Teste (sem token da unidade no frontend)

- `POST /admin/tests/plan-sale`
- `POST /admin/tests/plan-sale/status`

Objetivo:
- Permitir testes na tela de vendas usando apenas `unit_id` (cliente/unidade selecionados), sem precisar preencher `x-api-key` da unidade manualmente no front.
- O backend resolve credencial EVO da unidade no banco e executa a integração.

## Retorno simplificado de venda (n8n e teste admin)

Endpoints:
- `POST /n8n/sales/plan`
- `POST /admin/tests/plan-sale`

Contrato de resposta `201`:

```json
{
  "data": {
    "sale_id": 8,
    "unit_id": 8,
    "unit_name": "PAN Vila Antonieta",
    "cpf": "23773519052",
    "customer_name": "Maria teste",
    "plan_name": "GOLD MENSAL",
    "plan_value": 119.9,
    "payment_link": "https://...",
    "evo_sale_id": 32898526,
    "evo_member_id": 4418717,
    "message": "Venda criada e link de pagamento retornado."
  }
}
```

Notas:
- O backend nao expõem o `evo_response` completo no retorno padrao.
- A resposta completa da EVO fica persistida em `plan_sales.evo_response_json` e no log `logs/evo-sales.log`.

### Cache de planos EVO
- O endpoint `GET /admin/units/{id}/evo-plans` agora prioriza cache local por unidade para reduzir chamadas na EVO.
- Configuracao: `EVO_PLANS_CACHE_TTL_MINUTES=360`
- Query opcional: `refresh=1` para forcar consulta na EVO.
- Em falha da EVO (ex.: limite 429), retorna cache com `source=cache_fallback` e `is_stale=true`.
## Endpoints Admin de Teste - Aula Experimental (sem token manual)

- `POST /admin/tests/trial-class`
- `POST /admin/tests/trial-class/status`

Objetivo:
- A tela de teste de aula experimental usa somente `unit_id` (cliente/unidade selecionados).
- O backend resolve DNS/token EVO da unidade no banco (`unit_credentials`) e executa a chamada na EVO.
- Nao precisa gerar/colar token manual nessa tela.

Exemplo `POST /admin/tests/trial-class`:

```json
{
  "unit_id": 8,
  "cpf": "23773519052",
  "customer_name": "Maria Teste",
  "phone": "11999990000",
  "email": "maria@teste.com",
  "preferred_date": "2026-04-16",
  "preferred_time": "19:00"
}
```

Exemplo `POST /admin/tests/trial-class/status`:

```json
{
  "unit_id": 8,
  "trial_id": 12,
  "status": "COMPLETED",
  "trial_date": "2026-04-16",
  "trial_time": "19:00",
  "status_note": "Aluno compareceu"
}
```

## Horarios de aula experimental por unidade

- `GET /admin/units/{id}/trial-time-slots`

Retorno:

```json
{
  "data": {
    "unit_id": 8,
    "unit_name": "PAN Vila Antonieta",
    "unit_code": "PAN-AME-01",
    "time_slots": ["06:00", "07:00", "08:00", "09:00"]
  }
}
```

Observacao:
- A tela de teste de aula usa esses horarios para o campo de horario (select).
- Configuracao opcional via `.env`: `TRIAL_DEFAULT_TIME_SLOTS=06:00,07:00,08:00,...`

## Autenticacao EVO (modo auto)

Para reduzir falhas por variacao de permissao/configuracao entre tokens EVO, o backend suporta:

- `EVO_AUTH_MODE=auto` (recomendado)
  - tenta Bearer
  - se receber 401/403/405, tenta Basic (DNS:token)

Tambem configuramos tentativas de endpoint para aula experimental:

- `EVO_TRIAL_ENDPOINTS=/api/v1/activities/schedule/experimental-class`

## Ajustes Blip -> EvoKore (2026-04)

### Planos por unidade (online/ativos)

Endpoints:

- `GET /admin/units/{id}/evo-plans`
- `GET /admin/units/{id}/evo-plans/active`

Query params opcionais:

- `id_branch` (default `EVO_DEFAULT_BRANCH_ID`)
- `online_only=0|1` (default `1` para `/active`)
- `refresh=1` (forca consulta EVO e ignora cache)

Observacoes:

- Para `/api/v2/membership`, o backend consulta com `idBranch`, `showAccessBranches=false` e `showOnlineSalesObservation=false`.
- O payload normalizado de planos inclui:
  - `academy` (dados da academia/unidade para consumo do n8n/blip)
  - `id`, `name`, `value`
  - `regular_value`, `promotional_value`
  - `is_promotional`
  - `value_label`, `regular_value_label`, `promotional_value_label`
  - `promo_message`
  - `promo` (objeto com `is_promo`, `first_period_value`, `regular_value_after_period`, `period_label`, `message`)
  - `months_promotional_period`, `days_promotional_period`
  - `online_sales_observations`
  - `differentials`
  - `benefits`
  - `url_sale`, `external_sale_available`
  - `is_active`, `is_online`, `status`

### Venda de plano (sem hardcode)

Endpoints:

- `POST /n8n/sales/plan`
- `POST /admin/tests/plan-sale`

Campos adicionais aceitos no payload:

- `payment` (sobrescreve `EVO_SALE_PAYMENT_DEFAULT`)
- `id_prospect` ou `idProspect` (quando o fluxo externo ja tiver prospect EVO)

Configuracao recomendada:

```env
EVO_SALE_ENDPOINT=/api/v2/sales
EVO_SALE_SHOW_CONTRACT_HTML=false
EVO_SALE_PAYMENT_DEFAULT=6
EVO_SALE_PAYMENT_FALLBACKS=2,3,4,5,1
```

## Atualizacao 2026-04-23 - Webhook EVO e conciliacao automatica

Endpoint:

- `POST /webhook/evo`

Autenticacao:

- Header obrigatorio: `x-webhook-token: <WEBHOOK_TOKEN>`

Comportamento:

- valida token, content-type, tamanho de payload e rate limit
- reconcilia pagamento da venda em `plan_sales` por prioridade:
  1. `evo_sale_id`
  2. `payment_reference`
  3. `evo_member_id`
  4. `cpf + plan_name` (fallback)
- atualiza automaticamente:
  - `status`
  - `paid_value`
  - `paid_at`
  - `status_note`
  - `last_webhook_at`
  - `last_webhook_payload_json`

Observacao:

- `POST /n8n/sales/plan/status` continua ativo como fallback manual.

## Atualizacao 2026-04-23 - Planos online ativos (filtro estrito)

Endpoint:

- `GET /admin/units/{id}/evo-plans/active?online_only=1`

Regra de filtro online:

- prioridade para `is_online = true` vindo da EVO
- fallback por nome contendo:
  - `ONLINE`
  - `ON-LINE`
  - `ON LINE`
  - `VENDA ONLINE`

Isso evita retorno de planos presenciais quando a EVO nao envia flag online de forma consistente.
