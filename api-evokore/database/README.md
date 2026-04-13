# Database - EvoKore

## Estrutura desta etapa

- `migrations/20260410_001_initial_schema.sql`
  - Cria as tabelas iniciais de clientes, unidades, credenciais por unidade, logs de integracao, eventos de inadimplencia e relatorios mensais.
- `migrations/20260410_002_unit_api_tokens.sql`
  - Cria tabela de tokens de integracao por unidade (token por unidade para n8n), com hash, hint e controle de rotacao.
- `seeds/20260410_001_seed_panobianco.sql`
  - Seed inicial de exemplo para cliente Panobianco e duas unidades.

## Privacidade de dados (CPF)

- Nao armazenamos CPF em texto puro.
- Campos usados para rastreio:
  - `cpf_hash` (SHA-256 com salt na camada de aplicacao)
  - `cpf_mask` (ex.: `***.***.***-12`)

## Execucao manual (MySQL)

```sql
SOURCE database/migrations/20260410_001_initial_schema.sql;
SOURCE database/migrations/20260410_002_unit_api_tokens.sql;
SOURCE database/seeds/20260410_001_seed_panobianco.sql;
```

## Observacoes

- Substitua os placeholders `__REPLACE_WITH_UNIT_X_TOKEN__` pelos tokens reais da unidade.
- `unit_evo_credentials.token_encrypted` foi definido para receber token cifrado; a cifragem sera aplicada na camada de aplicacao na proxima etapa.
