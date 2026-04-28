# Etapa 6 - Roadmap Proximas Etapas

## Objetivo desta etapa

Planejar a evolucao para produto SaaS multi-tenant sem quebrar os fluxos atuais de n8n + EVO.

## Situacao atual

As entregas principais de integracao EVO + n8n estao ativas:

- fluxo de venda de plano
- fluxo de aula experimental
- dashboards operacionais
- webhook EVO para conciliacao automatica de pagamento
- login administrativo por sessao

## Proximas etapas (prioridade)

### Etapa 6.1 - Fundacao SaaS (multi-tenant)

Objetivo:
- garantir segregacao total de dados por cliente (`client_id`)

Escopo:
- revisar todas as consultas e escritas para sempre filtrar por `client_id`
- validar que usuarios de um cliente nao acessam dados de outro
- criar testes de isolamento de tenant (backend + frontend)

Definicao de pronto:
- sem endpoint retornando dados de outro tenant
- relatorio de validacao de isolamento anexado na release

### Etapa 6.2 - Permissoes por perfil e unidade (RBAC)

Objetivo:
- controlar quem pode ver o que no SaaS

Escopo:
- perfis minimos: `owner`, `admin`, `operator`, `viewer`
- escopo por unidade: usuario ve apenas unidades permitidas
- matriz de permissao por modulo: Dash, Teste, Cadastro, Logs

Definicao de pronto:
- usuario com perfil `viewer` nao executa acoes de escrita
- usuario fora da unidade nao visualiza dados da unidade

### Etapa 6.3 - Observabilidade e auditoria

Objetivo:
- operar em escala com visibilidade e rastreabilidade

Escopo:
- health panel por unidade (ultima sync EVO, ultima venda, ultimo webhook)
- alertas de falha de webhook e fila de reconciliacao
- auditoria de acoes administrativas (quem, o que, quando, IP)

Definicao de pronto:
- alerta emitido quando webhook falhar acima de limite
- logs de auditoria consultaveis no dashboard de logs

### Etapa 6.4 - Seguranca operacional

Objetivo:
- reduzir superficie de risco e manter compliance basico

Escopo:
- expiracao de sessao admin e politica de senha
- rate limit por endpoint sensivel
- revisao de tokens (escopo, expiracao e rotacao controlada)
- allowlist de origem para webhook EVO quando viavel

Definicao de pronto:
- bateria de testes de seguranca documentada e executada por release

### Etapa 6.5 - Qualidade automatizada (obrigatorio)

Objetivo:
- cobrir backend e frontend com testes automatizados antes de novas features

Escopo backend (`PHPUnit`):
- testes unitarios de servicos principais
- testes de integracao de controllers/rotas criticas
- testes de seguranca: auth, token invalido, limites e validacoes

Escopo frontend (`Jest` + React Testing Library):
- testes de componentes de filtros e formularios
- testes de telas de dashboard (estado vazio, carregamento, sucesso, erro)
- testes de autenticacao frontend (login, sessao expirada, logout)

Definicao de pronto:
- pipeline CI obrigando:
  - lint
  - build
  - testes backend
  - testes frontend
- sem merge em branch principal com teste falhando

### Etapa 6.6 - Produto e performance

Objetivo:
- ampliar valor de negocio mantendo estabilidade

Escopo:
- dashboard de funil comercial (lead -> link -> pago)
- filtros salvos por usuario
- exportacao CSV em lote
- cache de consultas pesadas + jobs assincronos

Definicao de pronto:
- desempenho aceitavel com base de clientes maior
- sem regressao de funcionalidade principal

## Entregaveis de documentacao desta etapa

- `doc/05-testes-etapa-a-etapa.md` (plano detalhado de teste manual + automatizado)
- `doc/16-deploy-producao-checklist.md` (gate de release)
- `doc/17-saas-multi-tenant-e-permissoes.md` (arquitetura SaaS e RBAC)
- `doc/18-estado-atual-consolidado.md` (baseline unico do estado real em producao)

## Regra de governanca

Toda nova funcionalidade deve incluir:

- historia tecnica
- criterios de aceite
- testes automatizados (backend e frontend)
- atualizacao de documentacao
