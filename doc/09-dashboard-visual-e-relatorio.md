# Etapa 9 - Dashboard Visual Full Width e Relatorio

## Objetivo da etapa

Evoluir a experiencia do dashboard para uso operacional diario, com:

- layout em largura total (full width)
- visual moderno
- graficos sempre visiveis, inclusive com dados zerados
- foco em valor recuperado no mes
- exportacao de relatorio

## Entregas realizadas

1. Layout full width:
- removido limite `max-w-5xl` no header e no conteudo principal.

2. Dashboard visual:
- grafico circular de recuperacao mensal (%).
- painel ao lado com dados de:
  - recuperado no mes
  - base inadimplente

3. Grafico por unidade:
- barras de recuperacao por unidade (top 5).
- placeholders exibidos mesmo sem dados.

4. Filtros e datas:
- filtros de cliente, unidade e periodo mantidos.
- campos de data com calendario nativo (`type="date"`).
- periodo inicial padrao: mes atual.

5. Exportacao de relatorio:
- botao `Exportar Relatorio`.
- gera CSV com:
  - filtros aplicados
  - KPIs
  - ranking por unidade

## Arquivos impactados

- `public_html/evokore/app/src/pages/DashboardPage.tsx`
- `public_html/evokore/app/src/layouts/AppLayout.tsx`
- `public_html/evokore/app/src/components/AppHeader.tsx`

## Validacao

- teste manual visual aprovado.
- `npm run lint` sem erros.

## Checklist de Aceite Funcional

Use este checklist em homologacao:

1. Layout:
- [ ] Dashboard ocupa largura total da tela.
- [ ] Header e conteudo sem corte/quebra em desktop.
- [ ] Responsivo funcional em resolucoes menores.

2. Filtros:
- [ ] Filtro de cliente funciona.
- [ ] Filtro de unidade funciona.
- [ ] Data inicial abre calendario nativo.
- [ ] Data final abre calendario nativo.
- [ ] Botao "Atualizar Dashboard" recarrega os dados.

3. KPIs:
- [ ] Inadimplentes (quantidade) correto.
- [ ] Valor inadimplente correto.
- [ ] Regularizados (quantidade) correto.
- [ ] Valor regularizado correto.
- [ ] Conversao por quantidade (%) correta.
- [ ] Conversao por valor (%) correta.
- [ ] Links de pagamento enviados correto.

4. Graficos:
- [ ] Grafico circular aparece com dados > 0.
- [ ] Grafico circular aparece com dados zerados.
- [ ] Grafico por unidade mostra top 5 com dados.
- [ ] Grafico por unidade mostra placeholders quando sem dados.

5. Tabela:
- [ ] Ranking de unidades lista dados coerentes.
- [ ] Estado vazio aparece quando nao ha dados no filtro.

6. Exportacao:
- [ ] Botao "Exportar Relatorio" habilita com dashboard carregado.
- [ ] CSV e baixado com sucesso.
- [ ] CSV contem filtros aplicados.
- [ ] CSV contem KPIs.
- [ ] CSV contem linhas de unidades.
