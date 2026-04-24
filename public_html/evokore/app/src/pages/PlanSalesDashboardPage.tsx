import { useEffect, useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  type Client,
  type PlanSalesDashboardData,
  type Unit,
  ApiError,
  fetchClients,
  fetchPlanSalesDashboard,
  fetchUnits,
} from '@/services/adminApi'

const now = new Date()
const defaultStart = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10)
const defaultEnd = now.toISOString().slice(0, 10)

const emptyData: PlanSalesDashboardData = {
  summary: {
    total_sales: 0,
    total_value: 0,
    paid_sales: 0,
    paid_value: 0,
    links_generated: 0,
    conversion_by_count: 0,
    conversion_by_value: 0,
  },
  by_unit: [],
}

export function PlanSalesDashboardPage() {
  const [clients, setClients] = useState<Client[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [clientId, setClientId] = useState<number>()
  const [unitId, setUnitId] = useState<number>()
  const [startDate, setStartDate] = useState(defaultStart)
  const [endDate, setEndDate] = useState(defaultEnd)
  const [data, setData] = useState<PlanSalesDashboardData>(emptyData)
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

  const maxUnitValue = useMemo(
    () => Math.max(1, ...data.by_unit.map((item) => item.paid_value)),
    [data.by_unit],
  )

  async function refresh() {
    setLoading(true)
    setMessage('Atualizando dashboard de vendas...')
    setMessageType('info')
    try {
      const response = await fetchPlanSalesDashboard({ startDate, endDate, clientId, unitId })
      setData(response)
      setMessage('Dashboard de vendas atualizado com sucesso.')
      setMessageType('success')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  function handleExportCsv() {
    const lines = [
      'Unidade,Vendas,Valor vendido,Pagas,Valor pago,Links gerados,Conversao quantidade (%),Conversao valor (%)',
      ...data.by_unit.map((row) =>
        [
          csvEscape(row.unit_name),
          row.total_sales,
          row.total_value.toFixed(2).replace('.', ','),
          row.paid_sales,
          row.paid_value.toFixed(2).replace('.', ','),
          row.links_generated,
          row.conversion_by_count.toFixed(2).replace('.', ','),
          row.conversion_by_value.toFixed(2).replace('.', ','),
        ].join(','),
      ),
    ]

    const csv = lines.join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `dashboard-vendas-${startDate}-${endDate}.csv`
    anchor.click()
    URL.revokeObjectURL(url)
  }

  function handleError(error: unknown) {
    let errorText = 'Falha ao carregar dashboard de vendas.'
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
        <h1>Dashboard de Vendas de Planos</h1>
        <p className="text-body text-muted-foreground">Indicadores e gráficos exclusivos de vendas e conversão de planos.</p>
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
            {loading ? 'Carregando...' : 'Atualizar Dashboard de Vendas'}
          </Button>
        </CardContent>
      </Card>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <MetricCard label="Total de vendas" value={String(data.summary.total_sales)} />
        <MetricCard label="Valor vendido" value={brl(data.summary.total_value)} />
        <MetricCard label="Vendas pagas" value={String(data.summary.paid_sales)} />
        <MetricCard label="Valor pago" value={brl(data.summary.paid_value)} />
      </div>

      <div className="grid gap-4 xl:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Conversão por quantidade</CardTitle>
            <CardDescription>{data.summary.conversion_by_count.toFixed(2)}%</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="h-3 w-full rounded-full bg-secondary">
              <div
                className="h-full rounded-full bg-primary"
                style={{ width: `${Math.min(100, data.summary.conversion_by_count)}%` }}
              />
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Conversão por valor</CardTitle>
            <CardDescription>{data.summary.conversion_by_value.toFixed(2)}%</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="h-3 w-full rounded-full bg-secondary">
              <div
                className="h-full rounded-full bg-success"
                style={{ width: `${Math.min(100, data.summary.conversion_by_value)}%` }}
              />
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Gráfico: valor pago por unidade</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {data.by_unit.length === 0 ? (
            <p className="text-sm text-muted-foreground">Sem dados no período selecionado.</p>
          ) : (
            data.by_unit.map((item) => {
              const width = (item.paid_value / maxUnitValue) * 100
              return (
                <div key={item.unit_id} className="space-y-1">
                  <div className="flex items-center justify-between text-sm">
                    <span>{item.unit_name}</span>
                    <strong>{brl(item.paid_value)}</strong>
                  </div>
                  <div className="h-2 w-full rounded-full bg-secondary">
                    <div className="h-full rounded-full bg-primary" style={{ width: `${width}%` }} />
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
                <TableHead>Vendas</TableHead>
                <TableHead>Valor vendido</TableHead>
                <TableHead>Pagas</TableHead>
                <TableHead>Valor pago</TableHead>
                <TableHead>Conversão (%)</TableHead>
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
                    <TableCell>{item.total_sales}</TableCell>
                    <TableCell>{brl(item.total_value)}</TableCell>
                    <TableCell>{item.paid_sales}</TableCell>
                    <TableCell>{brl(item.paid_value)}</TableCell>
                    <TableCell>{item.conversion_by_count.toFixed(2)}%</TableCell>
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

function brl(value: number): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0)
}

function csvEscape(value: string): string {
  if (value.includes(',') || value.includes('"') || value.includes('\n')) {
    return `"${value.replaceAll('"', '""')}"`
  }

  return value
}
