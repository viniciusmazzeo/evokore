# Database - EvoKore

## Estrutura desta etapa

- `migrations/20260410_001_initial_schema.sql`
  - Cria as tabelas iniciais de clientes, unidades, credenciais por unidade, logs de integracao, eventos de inadimplencia e relatorios mensais.
- `migrations/20260410_002_unit_api_tokens.sql`
  - Cria tabela de tokens de integracao por unidade (token por unidade para n8n), com hash, hint e controle de rotacao.
- `migrations/20260423_003_admin_users.sql`
  - Cria tabela de usuarios administrativos para login no painel web.
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
SOURCE database/migrations/20260423_003_admin_users.sql;
SOURCE database/seeds/20260410_001_seed_panobianco.sql;
```

## Criacao do primeiro usuario admin

1. Gere o hash da senha no servidor:

```bash
php -r "echo password_hash('SUA_SENHA_FORTE', PASSWORD_DEFAULT), PHP_EOL;"
```

2. Insira o usuario:

```sql
INSERT INTO admin_users (username, display_name, password_hash, role, status)
VALUES ('admin', 'Administrador', 'COLE_AQUI_O_HASH', 'admin', 'ACTIVE');
```

## Observacoes

- Substitua os placeholders `__REPLACE_WITH_UNIT_X_TOKEN__` pelos tokens reais da unidade.
- `unit_evo_credentials.token_encrypted` recebe token cifrado na aplicacao (`enc:v1:` com AES-256-GCM).
- Configure `TOKENS_ENC_KEY` no `.env` para habilitar criptografia em novas gravacoes.
- Compatibilidade legada: se existir token antigo em texto puro, a leitura continua funcionando ate a proxima rotacao.
