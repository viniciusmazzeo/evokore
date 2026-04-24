# Etapa 6 - Roadmap Próximas Etapas

## Próxima Entrega Funcional

Dashboard de inadimplência com filtros por:

- Cliente
- Unidade
- Período

Métricas iniciais:

- Total inadimplentes
- Total regularizados
- Conversão por quantidade
- Conversão por valor

## Integração e Automação

- Job mensal para conciliação de regularizados.
- Job recorrente para identificar dívidas ativas > 15 dias.
- Disparo para n8n com token de acesso da unidade.

## Segurança e Governança

- Expiração/rotação de tokens.
- Auditoria de chamadas por unidade.
- Limites de taxa por endpoint crítico.

## Qualidade e Testes

- Frontend: incluir suíte com Jest/Vitest + React Testing Library.
- Backend: avaliar e incluir PHPUnit para controllers/services críticos.
- Pipeline CI: lint + testes + build.

## Deploy

Etapas previstas:

1. Configuração de ambiente de homologação.
2. Migração de banco versionada.
3. Variáveis seguras por ambiente.
4. Checklist de smoke test pós-deploy.

