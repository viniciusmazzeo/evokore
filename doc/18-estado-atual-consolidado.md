# Etapa 18 - Estado Atual Consolidado

## Objetivo

Consolidar o estado real do projeto (backend, frontend, n8n, EVO, seguranca e operacao), com:

- o que ja esta implementado e validado
- o que depende de fallback/configuracao
- o que ainda falta para evolucao SaaS em escala

---

## 1) Arquitetura atual (resumo)

- Backend PHP (`api-evokore`) como camada de orquestracao entre n8n e EVO.
- Frontend React (`public_html/evokore/app`) para operacao administrativa.
- Banco MySQL com entidades de cliente, unidade, tokens, credenciais EVO e eventos operacionais.
- Integracao EVO via `EvoService` (v1 e v2), com retries, fallback e logs.
- n8n consome endpoints da API (nao chama EVO direto).

---

## 2) Endpoints principais em producao

### 2.1 Admin e cadastro

- `POST /admin/auth/login`
- `GET /admin/auth/me`
- `POST /admin/auth/logout`
- `GET/POST/PUT /admin/clients`
- `GET/POST/PUT /admin/units`
- `GET/POST /admin/units/{id}/access-token`
- `GET/POST/PUT /admin/unit-credentials`

### 2.2 Planos EVO (separado)

- `GET /admin/units/{id}/evo-plans`
- `GET /admin/units/{id}/evo-plans/active`
- `GET /admin/units/{id}/evo-plans/characteristics`

Regra operacional:
- estes endpoints sao os oficiais para consulta de planos (incluindo online/promocionais).
- o endpoint de informacoes operacionais de unidade nao deve ser usado para planos.

### 2.3 Informacoes operacionais da unidade (separado de planos)

- `GET /admin/units/{id}/evo-unit-info`

Retorna:
- unidade/academia
- periodo retornado
- `address`
- `contacts`
- `infrastructure`
- `has_parking`
- `class_types`
- `teachers`
- `schedule`
- `operation_today`
- `metadata_source`, `metadata_endpoint`, `metadata_query`, `metadata_errors`

### 2.4 Fluxos n8n -> API -> EVO

- `POST /n8n/sales/plan`
- `POST /n8n/sales/plan/status`
- `POST /n8n/sales/trial`
- `POST /n8n/sales/trial/status`
- `POST /webhook/evo`

### 2.5 Dashboards e logs

- `GET /admin/dashboard-summary`
- `GET /admin/dashboard/plan-sales`
- `GET /admin/dashboard/trials`
- `GET /admin/logs`

---

## 3) Tokens e seguranca (estado atual)

### 3.1 Tokens por finalidade

- `ADMIN_API_TOKEN`: automacao server-to-server em `/admin/*` quando token auth estiver habilitado.
- `FINANCIAL_ENDPOINT_TOKEN`: endpoint financeiro.
- `WEBHOOK_TOKEN`: autenticacao do webhook de entrada.
- `UNIT_TOKEN_EKU`: token por unidade para fluxo n8n.
- Credencial EVO por unidade (`unit_evo_credentials`): DNS + token EVO (criptografado em banco).

### 3.2 Sessao admin

- Login por sessao ativa no painel.
- Rotas admin protegidas por sessao.
- Token em header pode coexistir para automacoes (conforme `ADMIN_ALLOW_TOKEN_AUTH`).

### 3.3 Criptografia de segredos

- `TOKENS_ENC_KEY` obrigatoria para cifrar/decifrar segredos no banco.
- Nao usar token hardcoded em codigo.

---

## 4) Comportamentos importantes da EVO

### 4.1 Rate limit (429)

A EVO pode responder:
- `EVO HTTP 429: The request limit of 40 requests per minute has been reached`

Consequencias:
- respostas incompletas em consultas amplas
- fallback automatico no backend

Mitigacoes implementadas:
- janelas de consulta menores em unit-info
- tentativas mais conservadoras
- retorno parcial quando possivel

### 4.2 Endereco/contato da unidade

Nem sempre a EVO retorna endereco/contato nos endpoints disponiveis.

Por isso o backend usa merge:
1. dados vindos da EVO (quando existir)
2. fallback configurado no `.env`:
   - `UNIT_ADDRESS_MAP_JSON`
   - `UNIT_CONTACTS_MAP_JSON`

---

## 5) Frontend (estado atual)

- Menu consolidado com Dash, Teste, Cadastro.
- Tema claro/escuro ativo.
- Dashboard de inadimplencia ajustado com filtros e graficos.
- Dashboards de vendas e aulas ativos.
- Tela de logs com detalhe (modal).
- Login administrativo ativo e validado.

Observacao:
- o frontend depende dos endpoints admin; quando algum retorno cair em fallback por 429 da EVO, cards/listas podem vir vazios parcial/temporariamente.

---

## 6) n8n (estado atual)

Fluxo recomendado:
- consulta de planos: endpoint de planos (`/evo-plans/active` ou `/characteristics`)
- venda: `/n8n/sales/plan`
- status: webhook EVO ou `/n8n/sales/plan/status`
- aula: `/n8n/sales/trial`

Nao recomendado:
- misturar consulta de planos com endpoint operacional da unidade.

---

## 7) Pendencias e lacunas (importante)

1. Endereco/contato totalmente automatico via EVO para todas as unidades
- hoje pode depender de fallback por config.

2. Cobertura automatizada de testes
- backend: PHPUnit (unit + integracao + seguranca)
- frontend: Jest/RTL
- gate de CI obrigatorio por release.

3. RBAC completo multi-tenant
- perfis por cliente/unidade e permissoes por modulo.

4. Observabilidade de producao
- painel de health por unidade (ultima sync EVO, ultima venda, ultimo webhook).

---

## 8) Variaveis de ambiente relevantes (resumo)

Seguranca:
- `WEBHOOK_TOKEN`
- `ADMIN_API_TOKEN`
- `FINANCIAL_ENDPOINT_TOKEN`
- `TOKENS_ENC_KEY`
- `ADMIN_ALLOW_TOKEN_AUTH`

EVO:
- `EVO_BASE_URL`
- `EVO_AUTH_MODE`
- `EVO_DNS_HEADER_NAME`
- `EVO_TIMEOUT_SECONDS`
- `EVO_MAX_RETRIES`
- `EVO_DEFAULT_BRANCH_ID`
- `EVO_PLANS_ENDPOINTS`
- `EVO_SALE_ENDPOINT`
- `EVO_SALE_ENDPOINT_FALLBACK`
- `EVO_TRIAL_ENDPOINTS`

Unit info:
- `EVO_UNIT_INFO_ENDPOINTS`
- `EVO_UNIT_INFO_DEFAULT_WINDOW_DAYS`
- `EVO_UNIT_INFO_MAX_WINDOW_DAYS`
- `EVO_UNIT_INFO_ONLY_AVAILABLES`
- `UNIT_INFRASTRUCTURE_DEFAULT`
- `UNIT_INFRASTRUCTURE_MAP_JSON`
- `UNIT_ADDRESS_MAP_JSON`
- `UNIT_CONTACTS_MAP_JSON`

---

## 9) Regra de documentacao daqui para frente

Toda alteracao de endpoint, contrato de resposta, seguranca ou deploy deve atualizar no minimo:

1. `api-evokore/docs/admin-crud.md` (contrato tecnico)
2. `doc/16-deploy-producao-checklist.md` (operacao de deploy)
3. este consolidado (`doc/18-estado-atual-consolidado.md`) quando mudar comportamento macro

