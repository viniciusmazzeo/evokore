# Etapa 2 - Ambiente e Execução

## Padrão Definido

- Backend: somente XAMPP (Windows).
- Banco: MySQL do XAMPP.
- Frontend: Vite (Windows recomendado para evitar conflito de rede WSL/localhost).

## Backend (XAMPP)

1. Iniciar `Apache` e `MySQL` no painel do XAMPP.
2. Validar endpoint:

```powershell
curl.exe -i -H "x-api-key: SEU_TOKEN_ADMIN" "http://localhost/evokore/public_html/evokore/admin/clients"
```

## Frontend (Vite)

```powershell
cd D:\xampp\htdocs\evokore\public_html\evokore\app
npm install
npm run dev -- --host --port 5173
```

## Variáveis Importantes

Arquivo: `public_html/evokore/app/.env.local`

```env
VITE_BACKEND_PROXY_TARGET=http://localhost/evokore/public_html/evokore
VITE_ADMIN_API_TOKEN=SEU_TOKEN_ADMIN
```

Arquivo: `api-evokore/.env`

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `ADMIN_API_TOKEN`

