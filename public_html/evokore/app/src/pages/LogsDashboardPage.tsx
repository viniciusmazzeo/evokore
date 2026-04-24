import { useEffect, useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  type Client,
  type IntegrationLog,
  type Unit,
  ApiError,
  fetchClients,
  fetchIntegrationLogs,
  fetchUnits,
} from '@/services/adminApi'

const now = new Date()
const defaultStart = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10)
const defaultEnd = now.toISOString().slice(0, 10)

type Meta = {
  page: number
  total: number
  total_pages: number
}

const defaultMeta: Meta = {
  page: 1,
  total: 0,
  total_pages: 1,
}

export function LogsDashboardPage() {
  const [clients, setClients] = useState<Client[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [logs, setLogs] = useState<IntegrationLog[]>([])
  const [meta, setMeta] = useState<Meta>(defaultMeta)
  const [loading, setLoading] = useState(false)
  const [message, setMessage] = useState('Use os filtros e clique em atualizar.')
  const [messageType, setMessageType] = useState<'info' | 'success' | 'error'>('info')

  const [clientId, setClientId] = useState<number | undefined>()
  const [unitId, setUnitId] = useState<number | undefined>()
  const [provider, setProvider] = useState<string>('')
  const [success, setSuccess] = useState<string>('')
  const [startDate, setStartDate] = useState(defaultStart)
  const [endDate, setEndDate] = useState(defaultEnd)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selectedLog, setSelectedLog] = useState<IntegrationLog | null>(null)

  const parsedMetaJson = useMemo(() => {
    if (!selectedLog?.meta_json) {
      return null
    }
    try {
      return JSON.parse(selectedLog.meta_json) as unknown
    } catch {
      return selectedLog.meta_json
    }
  }, [selectedLog])

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
      setUnitId(undefined)
    } catch (error) {
      handleError(error)
    }
  }

  async function loadLogs(targetPage = 1) {
    setLoading(true)
    setMessage('Carregando logs...')
    setMessageType('info')

    try {
      const data = await fetchIntegrationLogs({
        client_id: clientId,
        unit_id: unitId,
        provider: provider || undefined,
        success: success === '0' || success === '1' ? (Number(success) as 0 | 1) : undefined,
        date_from: startDate || undefined,
        date_to: endDate || undefined,
        q: search.trim() || undefined,
        page: targetPage,
        per_page: 20,
      })

      setLogs(data.data ?? [])
      setMeta({
        page: data.meta?.page ?? 1,
        total: data.meta?.total ?? 0,
        total_pages: data.meta?.total_pages ?? 1,
      })
      setPage(data.meta?.page ?? 1)
      setMessage(`Logs carregados: ${data.meta?.total ?? data.data?.length ?? 0}`)
      setMessageType('success')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  function handleError(error: unknown) {
    let text = 'Falha ao carregar logs.'
    if (error instanceof ApiError) {
      text = `${error.status} - ${error.message}`
    } else if (error instanceof Error) {
      text = error.message
    }
    setMessage(text)
    setMessageType('error')
  }

  return (
    <section className="space-y-6">
      <header className="space-y-2">
        <Badge variant="secondary">Dashboard</Badge>
        <h1>Dashboard de Logs do Sistema</h1>
        <p className="text-body text-muted-foreground">
          Acompanhe requisicoes das integracoes, erros e latencia em tempo real.
        </p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Filtros</CardTitle>
          <CardDescription>Filtre por cliente, unidade, provedor, resultado e periodo.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={clientId ?? ''}
            onChange={(event) => {
              setClientId(event.target.value ? Number(event.target.value) : undefined)
              setPage(1)
            }}
          >
            <option value="">Todos os clientes</option>
            {clients.map((client) => (
              <option key={client.id} value={client.id}>
                {client.name}
              </option>
            ))}
          </select>

          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={unitId ?? ''}
            onChange={(event) => {
              setUnitId(event.target.value ? Number(event.target.value) : undefined)
              setPage(1)
            }}
          >
            <option value="">Todas as unidades</option>
            {units.map((unit) => (
              <option key={unit.id} value={unit.id}>
                {unit.unit_name} ({unit.unit_code})
              </option>
            ))}
          </select>

          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={provider}
            onChange={(event) => {
              setProvider(event.target.value)
              setPage(1)
            }}
          >
            <option value="">Todos os provedores</option>
            <option value="EVO">EVO</option>
            <option value="N8N">N8N</option>
            <option value="SYSTEM">SYSTEM</option>
          </select>

          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={success}
            onChange={(event) => {
              setSuccess(event.target.value)
              setPage(1)
            }}
          >
            <option value="">Todos os status</option>
            <option value="1">Sucesso</option>
            <option value="0">Erro</option>
          </select>

          <Input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} />
          <Input type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} />
          <Input
            placeholder="Buscar endpoint, request_id ou erro"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
          <Button onClick={() => void loadLogs(1)} disabled={loading}>
            {loading ? 'Carregando...' : 'Atualizar Logs'}
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <div>
            <CardTitle>Eventos de integracao</CardTitle>
            <CardDescription>
              Pagina {meta.page} de {meta.total_pages} - total {meta.total}
            </CardDescription>
          </div>
          <div className="flex gap-2">
            <Button
              variant="outline"
              onClick={() => void loadLogs(Math.max(1, page - 1))}
              disabled={loading || page <= 1}
            >
              Anterior
            </Button>
            <Button
              variant="outline"
              onClick={() => void loadLogs(Math.min(meta.total_pages, page + 1))}
              disabled={loading || page >= meta.total_pages}
            >
              Proxima
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Data/Hora</TableHead>
                <TableHead>Cliente</TableHead>
                <TableHead>Unidade</TableHead>
                <TableHead>Provider</TableHead>
                <TableHead>Metodo</TableHead>
                <TableHead>Endpoint</TableHead>
                <TableHead>HTTP</TableHead>
                <TableHead>Latencia</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Erro</TableHead>
                <TableHead>Detalhes</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {logs.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={11}>Nenhum log para os filtros selecionados.</TableCell>
                </TableRow>
              ) : (
                logs.map((log) => (
                  <TableRow key={log.id}>
                    <TableCell>{formatDate(log.created_at)}</TableCell>
                    <TableCell>{log.client_name ?? '-'}</TableCell>
                    <TableCell>{log.unit_name ?? '-'}</TableCell>
                    <TableCell>{log.provider}</TableCell>
                    <TableCell>{log.method ?? '-'}</TableCell>
                    <TableCell className="max-w-[320px] truncate">{log.endpoint}</TableCell>
                    <TableCell>{log.http_status}</TableCell>
                    <TableCell>{log.latency_ms ? `${log.latency_ms} ms` : '-'}</TableCell>
                    <TableCell>
                      <Badge variant={log.success === 1 ? 'success' : 'error'}>
                        {log.success === 1 ? 'Sucesso' : 'Erro'}
                      </Badge>
                    </TableCell>
                    <TableCell className="max-w-[300px] truncate">{log.error_message ?? '-'}</TableCell>
                    <TableCell>
                      <Button variant="outline" onClick={() => setSelectedLog(log)}>
                        Ver
                      </Button>
                    </TableCell>
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

      {selectedLog ? (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
          onClick={() => setSelectedLog(null)}
          role="button"
          tabIndex={0}
        >
          <Card
            className="max-h-[90vh] w-full max-w-5xl overflow-hidden"
            onClick={(event) => event.stopPropagation()}
          >
            <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
              <div>
                <CardTitle>Detalhes do log #{selectedLog.id}</CardTitle>
                <CardDescription>
                  {selectedLog.provider} - {selectedLog.method ?? '-'} - {selectedLog.endpoint}
                </CardDescription>
              </div>
              <Button variant="outline" onClick={() => setSelectedLog(null)}>
                Fechar
              </Button>
            </CardHeader>
            <CardContent className="max-h-[72vh] overflow-auto space-y-4">
              <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <Detail label="Data/Hora" value={formatDate(selectedLog.created_at)} />
                <Detail label="Cliente" value={selectedLog.client_name ?? '-'} />
                <Detail label="Unidade" value={selectedLog.unit_name ?? '-'} />
                <Detail label="Codigo unidade" value={selectedLog.unit_code ?? '-'} />
                <Detail label="HTTP" value={String(selectedLog.http_status)} />
                <Detail label="Latencia" value={selectedLog.latency_ms ? `${selectedLog.latency_ms} ms` : '-'} />
                <Detail label="Status" value={selectedLog.success === 1 ? 'Sucesso' : 'Erro'} />
                <Detail label="Error code" value={selectedLog.error_code ?? '-'} />
                <Detail label="Request ID" value={selectedLog.request_id ?? '-'} />
              </div>

              <div className="space-y-2">
                <p className="text-sm font-medium">Mensagem de erro</p>
                <pre className="rounded-md border bg-background p-3 text-xs whitespace-pre-wrap">
                  {selectedLog.error_message ?? '-'}
                </pre>
              </div>

              <div className="space-y-2">
                <p className="text-sm font-medium">Meta JSON</p>
                <pre className="rounded-md border bg-background p-3 text-xs whitespace-pre-wrap">
                  {parsedMetaJson ? JSON.stringify(parsedMetaJson, null, 2) : selectedLog.meta_json ?? '-'}
                </pre>
              </div>
            </CardContent>
          </Card>
        </div>
      ) : null}
    </section>
  )
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border p-3">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="mt-1 text-sm">{value}</p>
    </div>
  )
}

function formatDate(value: string): string {
  if (!value) {
    return '-'
  }
  const normalized = value.includes('T') ? value : value.replace(' ', 'T')
  const date = new Date(normalized)
  if (Number.isNaN(date.getTime())) {
    return value
  }
  return date.toLocaleString('pt-BR')
}
