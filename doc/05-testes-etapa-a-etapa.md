# Etapa 5 - Testes Etapa a Etapa

## Objetivo

Padronizar validacao manual e automatizada para backend e frontend.

## Parte A - Testes manuais (smoke)

### Etapa A1 - Backend online

1. Apache e MySQL iniciados no XAMPP.
2. Teste rapido:

```powershell
curl.exe -i -H "x-api-key: SEU_TOKEN_ADMIN" "http://localhost/evokore/public_html/evokore/admin/clients"
```

Esperado: `200 OK`.

### Etapa A2 - Escopo no frontend

1. Abrir `http://localhost:5173`.
2. Verificar carregamento dos selects:
- Cliente
- Unidade

Esperado: valores preenchidos.

### Etapa A3 - Cadastro de unidade

1. Selecionar cliente.
2. Informar codigo e nome.
3. Salvar unidade.

Esperado: unidade criada e disponivel no select.

### Etapa A4 - Credencial EVO da unidade

1. Selecionar unidade.
2. Informar `DNS EVO` e `Token EVO`.
3. Salvar credencial.

Esperado: exibir `token_hint` ativo no card.

### Etapa A5 - Token n8n da unidade

1. Selecionar unidade.
2. Rotacionar token de acesso.

Esperado: token exibido uma vez para copia + `token_hint` ativo.

### Etapa A6 - Logs

1. Abrir bloco de logs.
2. Filtrar por provedor/status/unidade.
3. Carregar logs.

Esperado: lista coerente com filtros.

### Etapa A7 - Fluxo n8n por token da unidade

1. Gerar token da unidade em `POST /admin/units/{id}/access-token`.
2. Usar esse token no header `x-api-key` para chamar:

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

## Parte B - Testes automatizados backend (PHPUnit)

## Objetivo

Cobrir regras criticas de negocio e seguranca no backend.

## Estrutura recomendada

Criar em `api-evokore/`:

- `composer.json` (dev dependency `phpunit/phpunit`)
- `phpunit.xml`
- `tests/Unit/`
- `tests/Integration/`
- `tests/Fixtures/`

## Escopo minimo de testes

`tests/Unit`:
- normalizacao de CPF
- filtro de planos online
- validacao de payload de webhook
- utilitarios de token/seguranca

`tests/Integration`:
- `GET /admin/clients` com/sem auth
- `GET /admin/units/{id}/evo-plans/active?online_only=1`
- `POST /n8n/sales/plan` (caso sucesso e falha EVO)
- `POST /webhook/evo` (match e nao match)

## Comandos esperados

Dentro de `api-evokore`:

```bash
composer install
vendor/bin/phpunit --testdox
```

## Meta inicial

- cobertura minima backend: 70%
- 100% de cobertura de fluxos criticos de auth, venda e webhook

## Parte C - Testes automatizados frontend (Jest)

## Objetivo

Garantir estabilidade de telas, filtros e autenticacao.

## Stack recomendada

No app React/Vite:
- `jest`
- `@testing-library/react`
- `@testing-library/jest-dom`
- `@testing-library/user-event`
- `ts-jest` (se necessario para TypeScript)

## Estrutura recomendada

Criar em `public_html/evokore/app`:

- `jest.config.ts`
- `src/test/setupTests.ts`
- `src/__tests__/`

## Escopo minimo de testes

- login admin (sucesso/falha)
- carregamento dos selects Cliente/Unidade
- filtros de dashboards (periodo/unidade/cliente)
- estados da UI: loading, vazio, erro, sucesso
- modal de logs (abrir/fechar/render de campos)

## Comandos esperados

Dentro de `public_html/evokore/app`:

```bash
npm install
npm run test
npm run test:coverage
```

## Meta inicial

- cobertura minima frontend: 60%
- 100% de cobertura dos componentes criticos de navegacao, auth e filtros

## Parte D - Gate de release (obrigatorio)

Toda release deve executar:

1. `lint` backend/frontend
2. `build` frontend
3. `phpunit`
4. `jest`
5. smoke manual minimo (A1, A2, A7)

Sem passar esses itens, nao publicar.

## Verificacao em banco (manual)

Validar no phpMyAdmin:

- `units`
- `unit_evo_credentials`
- `unit_api_tokens`
- `integration_logs`
- `plan_sales`
- `debtor_events`
