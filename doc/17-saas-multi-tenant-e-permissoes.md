# Etapa 17 - SaaS Multi-tenant e Permissoes

## Objetivo

Definir a base de escala do produto SaaS para multiplos clientes com isolamento, seguranca e governanca.

## 1) Modelo de tenant

Regra principal:
- todo dado de negocio deve estar associado a um `client_id`

Regras de implementacao:
- consultas devem filtrar por `client_id` obrigatoriamente
- inserts devem persistir `client_id` de forma explicita
- updates/deletes devem validar `client_id` no `WHERE`

Risco que evita:
- vazamento de dados entre clientes

## 2) Modelo de usuario e acesso

Perfis recomendados:
- `owner`: controle total do tenant
- `admin`: gestao operacional completa
- `operator`: operacao do dia a dia sem configuracoes sensiveis
- `viewer`: somente leitura

Escopo por unidade:
- usuario pode ter acesso a 1..N unidades do mesmo `client_id`
- consultas de dashboard e logs devem considerar esse escopo

## 3) Matriz minima de permissao

- Dashboards: `owner`, `admin`, `operator`, `viewer`
- Testes operacionais (n8n): `owner`, `admin`, `operator`
- Cadastro de clientes/unidades/tokens: `owner`, `admin`
- Logs detalhados e seguranca: `owner`, `admin`

## 4) Seguranca de autenticacao

- sessao com expiracao configuravel
- cookies `HttpOnly`, `Secure`, `SameSite`
- politica de senha forte
- bloqueio progressivo por tentativas invalidas

## 5) Seguranca de tokens

Tipos de token:
- token administrativo global (uso restrito)
- token financeiro de endpoint
- token por unidade para n8n
- token EVO por unidade (credencial externa)

Boas praticas:
- guardar segredo de forma criptografada quando aplicavel
- nao expor token completo na UI
- registrar apenas `token_hint` para suporte
- permitir rotacao sem indisponibilidade

## 6) Auditoria e observabilidade

Auditoria minima:
- login/logout
- criacao/edicao/exclusao de unidade
- rotacao de token
- mudanca de permissao de usuario

Observabilidade minima:
- taxa de erro por endpoint
- tempo medio de resposta
- alertas de webhook falho
- fila de reconciliacao pendente

## 7) Performance e escala

- cache de consultas de dashboard com invalidador
- jobs assincronos para processamento pesado
- paginacao em listagens grandes (logs e eventos)
- indices por `client_id`, `unit_id`, `created_at`

## 8) Qualidade obrigatoria

Toda feature nova deve incluir:
- teste unitario backend (`PHPUnit`)
- teste de integracao backend (`PHPUnit`)
- teste de componente/tela frontend (`Jest`)
- atualizacao de documentacao tecnica

## 9) Plano de implantacao por fases

Fase 1:
- garantir `client_id` e escopo de unidade em todas as rotas

Fase 2:
- RBAC completo com matriz de permissao

Fase 3:
- auditoria + alertas + health dashboard

Fase 4:
- hardening de seguranca e quality gate obrigatorio

## 10) Criterio de aceite final

A plataforma so e considerada pronta para escala SaaS quando:

- isolamento de tenant estiver validado
- RBAC estiver ativo em todos os modulos
- pipeline de testes automatizados estiver bloqueando deploy com falha
- observabilidade e auditoria estiverem disponiveis em producao
