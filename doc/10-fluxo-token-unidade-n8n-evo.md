# Etapa 10 - Fluxo por Token da Unidade (n8n -> EvoKore -> EVO)

## Objetivo

Garantir que cada chamada do n8n seja roteada para a unidade correta sem enviar
`unit_id` manualmente.

## Implementacao

- Endpoints `financial/status` e `financial/link` autenticam por token de unidade.
- O backend resolve a unidade via `unit_api_tokens` (hash SHA-256).
- O backend carrega `evo_dns` e `token_encrypted` da credencial ativa da unidade.
- O backend atualiza `last_used_at` ao usar token valido.

## Resultado

- Multi-unidade real: cada token usa sua propria credencial EVO.
- Menos risco operacional: n8n nao precisa montar `unit_id`.
- Fluxo aderente ao desenho de integracao do projeto.
- Registro automatico em `integration_logs` para `financial/status` e `financial/link` com:
  - `client_id`
  - `unit_id`
  - `http_status`
  - `latency_ms`
  - `request_id`
  - `success` / `error`
