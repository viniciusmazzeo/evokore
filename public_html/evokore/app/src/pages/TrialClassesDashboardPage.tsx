import { useEffect, useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  type Client,
  type TrialDashboardData,
  type Unit,
  ApiError,
  fetchClients,
  fetchTrialDashboard,
  fetchUnits,
} from '@/services/adminApi'

const now = new Date()
const defaultStart = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10)
const defaultEnd = now.toISOString().slice(0, 10)

const emptyData: TrialDashboardData = {
  summary: {
    total_trials: 0,
    scheduled_trials: 0,
    completed_trials: 0,
    canceled_trials: 0,
    completion_rate: 0,
  },
  by_unit: [],
}

export function TrialClassesDashboardPage() {
  const [clients, setClients] = useState<Client[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [clientId, setClientId] = useState<number>()
  const [unitId, setUnitId] = useState<number>()
  const [startDate, setStartDate] = useState(defaultStart)
  const [endDate, setEndDate] = useState(defaultEnd)
  const [data, setData] = useState<TrialDashboardData>(emptyData)
  const [loading, setLoading] = useState(false)
  const [message, setMessage] = useState<string>('Use os filtros e clique em atualizar.')
  const [messageType, setMessageType] = useState<'info' | 'success' | 'error'>('info')

  useEffect(() => {
    void (async () => {
      try {
        const loadedClients = await fetchClients()
        setClients(loadedClients)
        if (loadedClients[0]) {
          setClientId(loadedClients[0].id)
        }
      } catch (error) {
        handleError(error)
      }
    })()
  }, [])

  useEffect(() => {
    if (!clientId) return
    void (async () => {
      try {
        const loadedUnits = await fetchUnits(clientId)
        setUnits(loadedUnits)
        setUnitId(undefined)
      } catch (error) {
        handleError(error)
      }
    })()
  }, [clientId])

  const maxCompleted = useMemo(
    () => Math.max(1, ...data.by_unit.map((item) => item.completed_trials)),
    [data.by_unit],
  )

  async function refresh() {
    setLoading(true)
    setMessage('Atualizando dashboard de aulas...')
    setMessageType('info')
    try {
      const response = await fetchTrialDashboard({ startDate, endDate, clientId, unitId })
      setData(response)
      setMessage('Dashboard de aulas atualizado com sucesso.')
      setMessageType('success')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  function handleExportCsv() {
    const lines = [
      'Unidade,Total,Agendadas,Concluidas,Canceladas,Conclusao (%)',
      ...data.by_unit.map((row) =>
        [
          csvEscape(row.unit_name),
          row.total_trials,
          row.scheduled_trials,
          row.completed_trials,
          row.canceled_trials,
          row.completion_rate.toFixed(2).replace('.', ','),
        ].join(','),
      ),
    ]

    const csv = lines.join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `dashboard-aulas-${startDate}-${endDate}.csv`
    anchor.click()
    URL.revokeObjectURL(url)
  }

  function handleError(error: unknown) {
    let errorText = 'Falha ao carregar dashboard de aulas.'
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
        <Badge variant="secondary">Dashboard</Badge>
        <h1>Dashboard de Aulas Experimentais</h1>
        <p className="text-body text-muted-foreground">Indicadores e gráficos exclusivos do fluxo de aulas experimentais.</p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Filtros</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
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
              <option value="">Todas as unidades</option>
              {units.map((unit) => (
                <option key={unit.id} value={unit.id}>
                  {unit.unit_name}
                </option>
              ))}
            </select>
          </div>
          <Input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} />
          <Input type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} />
          <Button className="xl:col-span-2" onClick={() => void refresh()} disabled={loading}>
            {loading ? 'Carregando...' : 'Atualizar Dashboard de Aulas'}
          </Button>
        </CardContent>
      </Card>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <MetricCard label="Total de aulas" value={String(data.summary.total_trials)} />
        <MetricCard label="Agendadas" value={String(data.summary.scheduled_trials)} />
        <MetricCard label="Concluídas" value={String(data.summary.completed_trials)} />
        <MetricCard label="Taxa de conclusão" value={`${data.summary.completion_rate.toFixed(2)}%`} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Gráfico: aulas concluídas por unidade</CardTitle>
          <CardDescription>Comparativo de conclusão por unidade no período filtrado.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {data.by_unit.length === 0 ? (
            <p className="text-sm text-muted-foreground">Sem dados no período selecionado.</p>
          ) : (
            data.by_unit.map((item) => {
              const width = (item.completed_trials / maxCompleted) * 100
              return (
                <div key={item.unit_id} className="space-y-1">
                  <div className="flex items-center justify-between text-sm">
                    <span>{item.unit_name}</span>
                    <strong>{item.completed_trials}</strong>
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

      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <CardTitle>Tabela por unidade</CardTitle>
          <Button variant="outline" onClick={handleExportCsv} disabled={data.by_unit.length === 0}>
            Exportar CSV
          </Button>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Unidade</TableHead>
                <TableHead>Total</TableHead>
                <TableHead>Agendadas</TableHead>
                <TableHead>Concluídas</TableHead>
                <TableHead>Canceladas</TableHead>
                <TableHead>Conclusão (%)</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.by_unit.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6}>Sem dados.</TableCell>
                </TableRow>
              ) : (
                data.by_unit.map((item) => (
                  <TableRow key={item.unit_id}>
                    <TableCell>{item.unit_name}</TableCell>
                    <TableCell>{item.total_trials}</TableCell>
                    <TableCell>{item.scheduled_trials}</TableCell>
                    <TableCell>{item.completed_trials}</TableCell>
                    <TableCell>{item.canceled_trials}</TableCell>
                    <TableCell>{item.completion_rate.toFixed(2)}%</TableCell>
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

function MetricCard({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="text-2xl font-semibold">{value}</p>
      </CardContent>
    </Card>
  )
}

function csvEscape(value: string): string {
  if (value.includes(',') || value.includes('"') || value.includes('\n')) {
    return `"${value.replaceAll('"', '""')}"`
  }

  return value
}
