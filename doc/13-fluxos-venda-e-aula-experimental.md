# Etapa 13 - Fluxos de Venda de Plano e Aula Experimental

> Nota de governanca:
> Este documento preserva historico de evolucao da etapa 13.
> Para contratos de API vigentes e comportamento atual, considerar como fonte primaria:
> - `api-evokore/docs/admin-crud.md`
> - `doc/18-estado-atual-consolidado.md`

## Objetivo
Implementar dois fluxos separados da ponte n8n/EVO:

1. Venda de plano:
- n8n envia lead aceito
- API cadastra no EVO
- API retorna link de pagamento para n8n

2. Aula experimental:
- n8n envia lead que nao aceitou plano
- API cadastra aula experimental no EVO

## Backend implementado

### Endpoints n8n

- `POST /n8n/sales/plan`
- `POST /n8n/sales/trial`
- `POST /n8n/sales/plan/status`
- `POST /n8n/sales/trial/status`
- `POST /webhook/evo` (confirmacao automatica de pagamento via EVO, sem depender do callback do n8n)

Autenticacao:
- `x-api-key` com token da unidade (`unit_api_tokens`)
- Resolve unidade e credencial EVO ativa por unidade (`unit_evo_credentials`)

Persistencia:
- Tabela `plan_sales` (criada automaticamente se nao existir)
- Tabela `trial_classes` (criada automaticamente se nao existir)
- Atualizacao de status por callback do n8n/EVO
- Reconciliacao automatica no `plan_sales` por:
  - `evo_sale_id`
  - `payment_reference`
  - `evo_member_id`
  - fallback `cpf + plan_name`

### Dashboards separados

- `GET /admin/dashboard/plan-sales`
- `GET /admin/dashboard/trials`

Filtros:
- `start_date`
- `end_date`
- `client_id` (opcional)
- `unit_id` (opcional)

## Frontend implementado

### Navegacao

Menu com paginas:

- Dashboard (inadimplencia)
- Dashboard de Vendas
- Dashboard de Aulas
- Teste de Vendas
- Teste de Aulas
- Cadastro

### Paginas novas

- `PlanSalesPage.tsx`
  - Formulario de teste do fluxo de venda (simulacao n8n)
  - Selecao de plano carregada direto da EVO por unidade
  - Valor preenchido automaticamente a partir do plano selecionado
  - Nao exige token manual na tela de teste (usa endpoint admin de teste por `unit_id`)
  - Formulario de callback de status de venda
  - Retorno JSON da ultima chamada

- `TrialClassesPage.tsx`
  - Formulario de teste do fluxo de aula experimental (simulacao n8n)
  - Formulario de callback de status da aula
  - Retorno JSON da ultima chamada

- `PlanSalesDashboardPage.tsx`
  - Dashboard exclusivo de vendas
  - Graficos de conversao e valor pago por unidade
  - Tabela por unidade

- `TrialClassesDashboardPage.tsx`
  - Dashboard exclusivo de aulas experimentais
  - Grafico de aulas concluidas por unidade
  - Dashboard separado de aulas
  - Tabela por unidade

### Tema e identidade visual

- Tema escuro como padrao
- Botao de alternancia claro/escuro no header
- Paleta ajustada com base no logo
- Logos aplicados no header:
  - `public/branding/logo-color.png`
  - `public/branding/logo-pb.png`

## Observacoes

- Endpoints EVO usados para escrita sao configuraveis via `.env`:
  - `EVO_SALE_ENDPOINT` (default: `/api/v1/sales`)
  - `EVO_TRIAL_ENDPOINT` (default: `/api/v1/trials`)
- Endpoint de catalogo de planos usado no teste de vendas:
  - `GET /api/v2/membership` (prioritario, conforme EVO)
  - fallback configuravel em `EVO_PLANS_ENDPOINTS`
- Se a EVO usar caminhos diferentes, ajustar no `.env`.

## Tratamento de indisponibilidade de cadastro de membro na EVO

Quando o endpoint de criacao de membro nao estiver disponivel para a unidade/token (404/405), a API agora:

- registra a oportunidade na tabela `plan_sales`
- define `status = PENDING_MEMBER_CREATION`
- responde HTTP `202` com `queued = true`
- evita quebrar o fluxo no front/n8n com erro tecnico bruto

Assim o time pode seguir operando e reprocessar essas oportunidades apos habilitar o endpoint de cadastro de membro na EVO.
## Ajuste de contrato EVO v2 para venda

A venda agora usa como padrao o endpoint `POST /api/v2/sales`.

Campos enviados no payload de venda:
- `idBranch` (padrao por `EVO_DEFAULT_BRANCH_ID`, sobrescrevivel por `id_branch`)
- `idMembership`
- `memberData.idMember`
- `memberData.document`
- `payment`
- `totalInstallments`

Variaveis de ambiente:
- `EVO_SALE_ENDPOINT=/api/v2/sales`
- `EVO_SALE_ENDPOINT_FALLBACK=/api/v1/sales`
- `EVO_DEFAULT_BRANCH_ID=1`
## Atualizacao 2026-04-15 - Fluxo oficial EVO para venda

Com base no retorno do suporte EVO, o backend foi ajustado para este fluxo:
1. Buscar membro por CPF na unidade.
2. Se nao existir, cadastrar prospect (Add Prospect).
3. Converter prospect para membro (turn opportunity into member).
4. Criar venda em /api/v2/sales (com fallback para /api/v1/sales quando necessario).

Variaveis novas de ambiente:
- EVO_PROSPECT_CREATE_ENDPOINTS`r
- EVO_PROSPECT_CONVERT_ENDPOINTS`r

Observacao: o frontend de venda continua sem campo manual de token; a unidade selecionada usa automaticamente DNS/token EVO salvos no banco.

### Ajuste de fallback (2026-04-15)
Quando a conversao de prospect em membro retornar 404 (endpoint indisponivel na unidade), o backend agora tenta criar a venda direto no /api/v2/sales para permitir conversao no ato da venda (comportamento orientado pelo suporte EVO).

### Ajuste anti-rate-limit EVO (2026-04-15)
- Reduzimos tentativas de endpoints de prospect/conversao para evitar estourar limite de 40 req/min.
- Conversao agora prioriza payload com IdProspect (formato esperado pelo endpoint /api/v1/prospects/convert).
- Requisicoes HTTP 429 agora entram em retry com espera maior antes da proxima tentativa.

### Ajuste de forma de pagamento na venda (2026-04-15)
- Se EVO retornar 'Cartao de credito nao encontrado' na venda, o backend tenta automaticamente codigos alternativos de payment (EVO_SALE_PAYMENT_FALLBACKS).
- Padrao de payment da venda passou a ser configuravel por EVO_SALE_PAYMENT_DEFAULT (padrao 2).

### Atualizacao 2026-04-16 - Contrato de resposta de venda simplificado

Para alinhar com o fluxo n8n (retornar apenas o necessario para continuidade), o retorno de `POST /n8n/sales/plan` e `POST /admin/tests/plan-sale` foi simplificado.

Campos principais no `data`:
- `sale_id`
- `unit_id`
- `unit_name`
- `cpf`
- `customer_name`
- `plan_name`
- `plan_value`
- `payment_link`
- `evo_sale_id`
- `evo_member_id`
- `message`

Observacoes:
- O payload completo da EVO continua salvo em banco (`plan_sales.evo_response_json`) e log (`logs/evo-sales.log`) para auditoria.
- O backend tenta extrair `payment_link` de multiplos campos do retorno EVO, incluindo `clienteContratos[].linkAceiteContrato`.
- O backend tambem persiste chaves de conciliacao para webhook:
  - `evo_sale_id`
  - `evo_member_id`
  - `payment_reference`

### Atualizacao 2026-04-23 - Confirmacao automatica de pagamento (webhook EVO)

Para manter o dashboard de vendas sempre atualizado mesmo sem callback do n8n, o endpoint `POST /webhook/evo` agora:

1. valida o `WEBHOOK_TOKEN`
2. identifica a venda no `plan_sales` pelos identificadores da EVO
3. atualiza automaticamente:
   - `status` (ex.: `PAID`, `PENDING`, `CANCELED`, `FAILED`)
   - `paid_value`
   - `paid_at`
   - `status_note`
   - `last_webhook_at`
   - `last_webhook_payload_json`

Importante:
- Nao altera contratos do n8n existentes.
- Continua aceitando `POST /n8n/sales/plan/status` para operacao manual/fallback.

### Atualizacao 2026-04-23 - Dashboards de Vendas e Aulas (UX e operacao)

Foram aplicadas melhorias nas paginas de dashboard de vendas e aulas para padronizar com a dashboard de inadimplentes:

- estados de carregamento (`Carregando...`) durante consulta
- mensagens de status/sucesso/erro com badge no rodape da pagina
- tratamento explicito de erro de API (`ApiError`)
- filtros com labels (`Cliente`, `Unidade`, `Data inicial`, `Data final`)
- exportacao CSV no detalhamento por unidade

Objetivo: reduzir erro operacional e facilitar leitura/extração dos dados em rotina diaria.

### Cache de planos EVO (2026-04-16)
- Endpoint GET /admin/units/{id}/evo-plans agora usa cache local por unidade (tabela unit_evo_plans_cache).
- TTL configuravel por EVO_PLANS_CACHE_TTL_MINUTES (padrao 360).
- Em erro da EVO (ex.: HTTP 429), a API retorna cache de fallback quando existir.
- Resposta inclui source (evo, cache, cache_fallback) e is_stale.

### Atualizacao 2026-04-23 - Filtro estrito de planos online

No endpoint `GET /admin/units/{id}/evo-plans/active?online_only=1`, o backend agora retorna apenas planos online ativos com regra estrita:
- prioridade para flag `is_online=true` retornada pela EVO
- fallback por padrao no nome (`ONLINE`, `ON-LINE`, `VENDA ONLINE`)

Isso evita retorno de planos presenciais quando a EVO nao envia a flag de online de forma consistente.

### Atualizacao 2026-04-16 - Teste de Aula sem token manual

A tela `TrialClassesPage` foi alinhada com o padrao das outras telas:

- usa escopo cliente/unidade
- nao possui campo de token manual
- nao faz rotacao automatica de token no front
- chama endpoints admin por `unit_id`:
  - `POST /admin/tests/trial-class`
  - `POST /admin/tests/trial-class/status`

Resultado:
- menos friccao no teste manual
- token/DNS EVO sempre resolvidos no backend a partir do banco
- comportamento consistente com tela de teste de vendas

### Atualizacao 2026-04-16 - Aula experimental (resiliencia EVO + horarios da unidade)

Melhorias aplicadas:
- O backend de aula experimental agora tenta multiplos endpoints EVO (`EVO_TRIAL_ENDPOINTS`) e multiplos formatos de payload.
- Quando todos os endpoints de aula retornam indisponivel (404/405), o fluxo faz fallback para cadastro de prospect na EVO e retorna sucesso com `fallback_mode=prospect`.
- A tela de teste de aula passou a carregar os horarios da unidade via `GET /admin/units/{id}/trial-time-slots`.
- O campo de horario agora e `select`, evitando digitacao manual fora dos horarios disponiveis.

### Atualizacao 2026-04-16 - Endpoint oficial de agendamento de aula experimental (EVO)

Fluxo ajustado para seguir o endpoint oficial informado pela EVO:
- `POST /api/v1/activities/schedule-experimental-class`

Implementacao aplicada:
- o backend cria prospect primeiro (quando necessario) para obter `idProspect`
- monta `activityDate` no formato `YYYY-MM-DD HH:mm`
- envia os campos obrigatorios:
  - `idProspect`
  - `activityDate`
  - `service`
  - `activity`
- envia opcionais quando informados:
  - `activityExist`
  - `idBranch`

Frontend de teste de aula atualizado:
- adicionados campos `Servico` e `Atividade`
- payload enviado para `/admin/tests/trial-class` inclui:
  - `service`
  - `activity`
  - `preferred_time` (obrigatorio)
- token manual continua desnecessario (resolucao por unidade no backend)
