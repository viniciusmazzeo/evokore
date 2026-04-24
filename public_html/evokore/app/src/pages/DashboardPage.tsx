import { useEffect, useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  type Client,
  type DashboardSummary,
  type DashboardUnitRow,
  type Unit,
  ApiError,
  fetchClients,
  fetchDashboard,
  fetchUnits,
} from '@/services/adminApi'

const today = new Date()
const defaultEnd = toDateInput(today)
const defaultStart = toDateInput(new Date(today.getFullYear(), today.getMonth(), 1))

const emptySummary: DashboardSummary = {
  links_generated: 0,
  delinquent_count: 0,
  delinquent_amount: 0,
  regularized_count: 0,
  regularized_amount: 0,
  conversion_by_links: 0,
  conversion_by_amount: 0,
}

export function DashboardPage() {
  const [clients, setClients] = useState<Client[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [clientId, setClientId] = useState<number | undefined>()
  const [unitId, setUnitId] = useState<number | undefined>()
  const [startDate, setStartDate] = useState(defaultStart)
  const [endDate, setEndDate] = useState(defaultEnd)
  const [summary, setSummary] = useState<DashboardSummary>(emptySummary)
  const [rows, setRows] = useState<DashboardUnitRow[]>([])
  const [loading, setLoading] = useState(false)
  const [message, setMessage] = useState<string>('Use os filtros e clique em atualizar.')
  const [messageType, setMessageType] = useState<'info' | 'success' | 'error'>('info')

  useEffect(() => {
    void loadClients()
  }, [])

  useEffect(() => {
    if (!clientId) {
      setUnits([])
      setUnitId(undefined)
      return
    }

    void loadUnits(clientId)
  }, [clientId])

  const recoveryRate = useMemo(() => {
    if (summary.delinquent_amount <= 0) {
      return 0
    }

    return Math.min(100, Number(((summary.regularized_amount / summary.delinquent_amount) * 100).toFixed(2)))
  }, [summary.delinquent_amount, summary.regularized_amount])

  const rowsTop5 = useMemo(() => rows.slice(0, 5), [rows])
  const chartRows = useMemo(() => {
    return [...rows]
      .sort((a, b) => b.delinquent_amount - a.delinquent_amount)
      .slice(0, 8)
  }, [rows])
  const maxChartAmount = useMemo(() => {
    const base = chartRows.reduce((carry, row) => Math.max(carry, row.delinquent_amount, row.regularized_amount), 0)
    return base <= 0 ? 1 : base
  }, [chartRows])

  async function loadClients() {
    try {
      const data = await fetchClients()
      setClients(data)
      if (data.length > 0) {
        setClientId(data[0].id)
      }
    } catch (error) {
      handleError(error)
    }
  }

  async function loadUnits(currentClientId: number) {
    try {
      const data = await fetchUnits(currentClientId)
      setUnits(data)
      setUnitId(data[0]?.id)
    } catch (error) {
      handleError(error)
    }
  }

  async function handleLoadDashboard() {
    setLoading(true)
    setMessage('Atualizando indicadores...')
    setMessageType('info')

    try {
      const data = await fetchDashboard({
        startDate,
        endDate,
        clientId,
        unitId,
      })

      setSummary(data.summary ?? emptySummary)
      setRows(data.by_unit ?? [])
      setMessage('Dashboard atualizado com sucesso.')
      setMessageType('success')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  function handleExportCsv() {
    const lines = [
      'Unidade,Links Gerados,Inadimplentes,Valor Inadimplente,Regularizados,Valor Regularizado,Conversão por Links (%)',
      ...rows.map((row) =>
        [
          csvEscape(row.unit_name),
          row.links_generated,
          row.delinquent_count,
          row.delinquent_amount.toFixed(2).replace('.', ','),
          row.regularized_count,
          row.regularized_amount.toFixed(2).replace('.', ','),
          row.conversion_by_links.toFixed(2).replace('.', ','),
        ].join(','),
      ),
    ]

    const csv = lines.join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `relatorio-dashboard-${startDate}-${endDate}.csv`
    anchor.click()
    URL.revokeObjectURL(url)
  }

  function handleError(error: unknown) {
    let errorText = 'Falha ao carregar dados.'
    if (error instanceof ApiError) {
      errorText = `${error.status} - ${error.message}`
    } else if (error instanceof Error) {
      errorText = error.message
    }

    setMessage(errorText)
    setMessageType('error')
  }

  return (
    <section className="space-y-6">
      <header className="space-y-2">
        <Badge variant="secondary">Dashboard de Cobrança</Badge>
        <h1>Painel de Recuperação</h1>
        <p className="text-body text-muted-foreground">
          Acompanhe links gerados, inadimplência registrada no sistema no período filtrado e quanto foi regularizado.
        </p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Filtros</CardTitle>
          <CardDescription>Selecione período, cliente e unidade para atualizar os indicadores.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          <div className="space-y-2">
            <label className="text-sm font-medium">Cliente</label>
            <select
              className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
              value={clientId ?? ''}
              onChange={(event) => setClientId(event.target.value ? Number(event.target.value) : undefined)}
            >
              {clients.map((client) => (
                <option key={client.id} value={client.id}>
                  {client.name}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Unidade</label>
            <select
              className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
              value={unitId ?? ''}
              onChange={(event) => setUnitId(event.target.value ? Number(event.target.value) : undefined)}
            >
              <option value="">Todas</option>
              {units.map((unit) => (
                <option key={unit.id} value={unit.id}>
                  {unit.unit_name} ({unit.unit_code})
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Data inicial</label>
            <Input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} />
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Data final</label>
            <Input type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} />
          </div>

          <div className="flex items-end gap-2">
            <Button className="w-full" onClick={handleLoadDashboard} disabled={loading}>
              {loading ? 'Carregando...' : 'Atualizar Dashboard'}
            </Button>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <MetricCard label="Links gerados" value={String(summary.links_generated)} />
        <MetricCard label="Valor inadimplente no período" value={formatCurrency(summary.delinquent_amount)} />
        <MetricCard label="Regularizados" value={String(summary.regularized_count)} />
        <MetricCard
          label="Valor regularizado no período"
          value={formatCurrency(summary.regularized_amount)}
          helper="Recuperado no período"
        />
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader>
            <CardTitle>Recuperação do mês</CardTitle>
            <CardDescription>
              {formatCurrency(summary.regularized_amount)} de {formatCurrency(summary.delinquent_amount)}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="h-3 w-full rounded-full bg-secondary">
              <div
                className="h-full rounded-full bg-primary transition-all"
                style={{ width: `${recoveryRate}%` }}
              />
            </div>
            <p className="text-sm text-muted-foreground">
              Conversão por valor: <strong>{summary.conversion_by_amount.toFixed(2)}%</strong>
            </p>
            <p className="text-sm text-muted-foreground">
              Conversão por quantidade (links x pagamentos):{' '}
              <strong>{summary.conversion_by_links.toFixed(2)}%</strong>
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Top unidades</CardTitle>
            <CardDescription>Regularização por unidade (top 5).</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {rowsTop5.length === 0 ? (
              <p className="text-sm text-muted-foreground">Sem dados no período selecionado.</p>
            ) : (
              rowsTop5.map((row) => {
                const width = Math.min(100, row.conversion_by_links)
                return (
                  <div key={row.unit_id} className="space-y-1">
                    <div className="flex items-center justify-between gap-2 text-sm">
                      <span className="line-clamp-1">{row.unit_name}</span>
                      <strong>{row.conversion_by_links.toFixed(1)}%</strong>
                    </div>
                    <div className="h-2 w-full rounded-full bg-secondary">
                      <div className="h-full rounded-full bg-success" style={{ width: `${width}%` }} />
                    </div>
                  </div>
                )
              })
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Gráfico: inadimplente x regularizado por unidade</CardTitle>
          <CardDescription>Comparativo dos valores no período (top 8 por inadimplência).</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {chartRows.length === 0 ? (
            <p className="text-sm text-muted-foreground">Sem dados no período selecionado.</p>
          ) : (
            chartRows.map((row) => (
              <div key={row.unit_id} className="space-y-2">
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="line-clamp-1">{row.unit_name}</span>
                  <span className="text-muted-foreground">
                    {formatCurrency(row.regularized_amount)} / {formatCurrency(row.delinquent_amount)}
                  </span>
                </div>
                <div className="space-y-1">
                  <div className="h-2 w-full rounded-full bg-secondary">
                    <div
                      className="h-full rounded-full bg-error"
                      style={{ width: `${Math.min(100, (row.delinquent_amount / maxChartAmount) * 100)}%` }}
                    />
                  </div>
                  <div className="h-2 w-full rounded-full bg-secondary">
                    <div
                      className="h-full rounded-full bg-success"
                      style={{ width: `${Math.min(100, (row.regularized_amount / maxChartAmount) * 100)}%` }}
                    />
                  </div>
                </div>
              </div>
            ))
          )}
          {chartRows.length > 0 ? (
            <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
              <span className="inline-flex items-center gap-2">
                <span className="h-2 w-4 rounded bg-error" />
                Inadimplente
              </span>
              <span className="inline-flex items-center gap-2">
                <span className="h-2 w-4 rounded bg-success" />
                Regularizado
              </span>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <div>
            <CardTitle>Detalhamento por unidade</CardTitle>
            <CardDescription>
              Quantidade de links gerados e regularizações no período selecionado.
            </CardDescription>
          </div>
          <Button variant="outline" onClick={handleExportCsv} disabled={rows.length === 0}>
            Exportar Relatório
          </Button>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Unidade</TableHead>
                <TableHead>Links</TableHead>
                <TableHead>Inadimplentes</TableHead>
                <TableHead>Valor inadimplente</TableHead>
                <TableHead>Regularizados</TableHead>
                <TableHead>Valor regularizado</TableHead>
                <TableHead>Conversão</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7}>Nenhum dado para os filtros selecionados.</TableCell>
                </TableRow>
              ) : (
                rows.map((row) => (
                  <TableRow key={row.unit_id}>
                    <TableCell>{row.unit_name}</TableCell>
                    <TableCell>{row.links_generated}</TableCell>
                    <TableCell>{row.delinquent_count}</TableCell>
                    <TableCell>{formatCurrency(row.delinquent_amount)}</TableCell>
                    <TableCell>{row.regularized_count}</TableCell>
                    <TableCell>{formatCurrency(row.regularized_amount)}</TableCell>
                    <TableCell>{row.conversion_by_links.toFixed(2)}%</TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="pt-6">
          <Badge variant={messageType === 'success' ? 'success' : messageType === 'error' ? 'error' : 'secondary'}>
            {messageType === 'success' ? 'Sucesso' : messageType === 'error' ? 'Erro' : 'Status'}
          </Badge>
          <p className="mt-3 text-sm text-muted-foreground">{message}</p>
        </CardContent>
      </Card>
    </section>
  )
}

function MetricCard({
  label,
  value,
  helper,
}: {
  label: string
  value: string
  helper?: string
}) {
  return (
    <Card>
      <CardContent className="space-y-1 pt-6">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="text-3xl font-semibold tracking-tight">{value}</p>
        {helper ? <p className="text-sm text-success">{helper}</p> : null}
      </CardContent>
    </Card>
  )
}

function toDateInput(date: Date): string {
  return date.toISOString().slice(0, 10)
}

function formatCurrency(value: number): string {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(value || 0)
}

function csvEscape(value: string): string {
  if (value.includes(',') || value.includes('"') || value.includes('\n')) {
    return `"${value.replaceAll('"', '""')}"`
  }

  return value
}
