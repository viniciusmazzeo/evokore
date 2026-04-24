# Admin Auth (Sessao + Token)

## Objetivo

- Etapa 1: login no frontend com usuario/senha.
- Etapa 2: proteger todo `/admin/*` exigindo sessao valida (ou token tecnico, quando habilitado).

## Endpoints

- `POST /admin/auth/login`
- `GET /admin/auth/me`
- `POST /admin/auth/logout`

## Fluxo frontend

1. Ao abrir o app, chamar `GET /admin/auth/me`.
2. Se retornar `401`, exibir tela de login.
3. Login envia `POST /admin/auth/login`.
4. Se sucesso, backend grava sessao PHP (cookie HttpOnly) e o frontend carrega o painel.
5. Botao sair chama `POST /admin/auth/logout`.

## Tabela de usuarios

Migration: `database/migrations/20260423_003_admin_users.sql`

Campos principais:

- `username` (unico)
- `password_hash` (`password_hash` do PHP)
- `status` (`ACTIVE`/`INACTIVE`)
- `last_login_at`

## Configuracao `.env`

- `ADMIN_SESSION_NAME=EVOKORESESSID`
- `ADMIN_ALLOW_TOKEN_AUTH=1`

## Observacoes de seguranca

- Nao salvar senha em texto puro.
- Sempre usar `password_hash` + `password_verify`.
- Cookie de sessao e `HttpOnly`, `SameSite=Lax`.
- Em producao HTTPS, cookie e enviado com `Secure`.
