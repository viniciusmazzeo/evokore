import { useCallback, useEffect, useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  type Client,
  type AdminDashboardSummary,
  ApiError,
  createUnitCredential,
  createUnit,
  getDashboardSummary,
  getUnitAccessToken,
  listClients,
  listLogs,
  listUnitCredentials,
  listUnits,
  rotateUnitAccessToken,
  type Unit,
  type UnitAccessToken,
  type UnitCredential,
} from '@/services/adminApi'

export function HomePage() {
  const [clients, setClients] = useState<Client[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [selectedClientId, setSelectedClientId] = useState<number>(1)
  const [selectedUnitId, setSelectedUnitId] = useState<number>(0)
  const [createdToken, setCreatedToken] = useState<string | null>(null)
  const [activeToken, setActiveToken] = useState<UnitAccessToken | null>(null)
  const [activeCredential, setActiveCredential] = useState<UnitCredential | null>(
    null,
  )
  const [dashboardSummary, setDashboardSummary] = useState<AdminDashboardSummary | null>(
    null,
  )
  const [logs, setLogs] = useState<
    Awaited<ReturnType<typeof listLogs>>['data']
  >([])
  const [logsMeta, setLogsMeta] = useState<
    Awaited<ReturnType<typeof listLogs>>['meta'] | null
  >(null)
  const [logProvider, setLogProvider] = useState<string>('')
  const [logSuccess, setLogSuccess] = useState<'0' | '1' | ''>('')
  const [logUnitId, setLogUnitId] = useState<number>(0)

  const [unitCode, setUnitCode] = useState('')
  const [unitName, setUnitName] = useState('')
  const [unitTimezone, setUnitTimezone] = useState('America/Sao_Paulo')
  const [evoDns, setEvoDns] = useState('')
  const [evoToken, setEvoToken] = useState('')
  const [expiresAt, setExpiresAt] = useState('')
  const [dashboardDateFrom, setDashboardDateFrom] = useState('')
  const [dashboardDateTo, setDashboardDateTo] = useState('')
  const [feedback, setFeedback] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const loadClients = useCallback(async () => {
    try {
      const response = await listClients()
      setClients(response.data)
      if (response.data.length > 0 && selectedClientId === 0) {
        setSelectedClientId(response.data[0].id)
      }
    } catch (err) {
      handleError(err, 'Falha ao carregar clientes')
    }
  }, [selectedClientId])

  const loadUnits = useCallback(async (clientId: number) => {
    try {
      const response = await listUnits(clientId)
      setUnits(response.data)
      if (!response.data.find((unit) => unit.id === selectedUnitId)) {
        setSelectedUnitId(response.data[0]?.id ?? 0)
      }
    } catch (err) {
      handleError(err, 'Falha ao carregar unidades')
    }
  }, [selectedUnitId])

  const loadActiveToken = useCallback(async (unitId: number) => {
    try {
      const response = await getUnitAccessToken(unitId)
      setActiveToken(response)
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        setActiveToken(null)
        return
      }
      handleError(err, 'Falha ao carregar token da unidade')
    }
  }, [])

  const loadActiveCredential = useCallback(async (unitId: number) => {
    try {
      const response = await listUnitCredentials({ unitId, isActive: 1 })
      const credential = response.data[0] ?? null
      setActiveCredential(credential)
      if (credential) {
        setEvoDns(credential.evo_dns)
      }
    } catch (err) {
      handleError(err, 'Falha ao carregar credencial EVO da unidade')
    }
  }, [])

  useEffect(() => {
    void loadClients()
  }, [loadClients])

  useEffect(() => {
    if (selectedClientId > 0) {
      void loadUnits(selectedClientId)
    }
  }, [loadUnits, selectedClientId])

  useEffect(() => {
    if (selectedUnitId > 0) {
      void loadActiveToken(selectedUnitId)
      void loadActiveCredential(selectedUnitId)
    } else {
      setActiveToken(null)
      setActiveCredential(null)
      setEvoDns('')
      setEvoToken('')
    }
  }, [loadActiveCredential, loadActiveToken, selectedUnitId])

  async function handleCreateUnit() {
    setFeedback(null)
    setError(null)

    if (!selectedClientId || !unitCode.trim() || !unitName.trim()) {
      setError('Preencha cliente, codigo da unidade e nome da unidade')
      return
    }

    setLoading(true)
    try {
      const response = await createUnit({
        client_id: selectedClientId,
        unit_code: unitCode.trim(),
        unit_name: unitName.trim(),
        timezone: unitTimezone.trim() || 'America/Sao_Paulo',
        status: 'ACTIVE',
      })
      setFeedback(
        `Unidade criada: ${response.unit_name} (id ${response.id})`,
      )
      setUnitCode('')
      setUnitName('')
      await loadUnits(selectedClientId)
      setSelectedUnitId(response.id)
    } catch (err) {
      handleError(err, 'Falha ao criar unidade')
    } finally {
      setLoading(false)
    }
  }

  async function handleRotateToken() {
    setFeedback(null)
    setError(null)
    setCreatedToken(null)

    if (!selectedUnitId) {
      setError('Selecione uma unidade primeiro')
      return
    }

    setLoading(true)
    try {
      const response = await rotateUnitAccessToken(selectedUnitId, expiresAt || undefined)
      setCreatedToken(response.token ?? null)
      setFeedback(`Token rotacionado para a unidade ${response.unit_code}`)
      await loadActiveToken(selectedUnitId)
    } catch (err) {
      handleError(err, 'Falha ao rotacionar token da unidade')
    } finally {
      setLoading(false)
    }
  }

  async function handleSaveUnitCredential() {
    setFeedback(null)
    setError(null)

    if (!selectedUnitId) {
      setError('Selecione uma unidade primeiro')
      return
    }
    if (!evoDns.trim() || !evoToken.trim()) {
      setError('Preencha DNS EVO e token EVO')
      return
    }

    setLoading(true)
    try {
      await createUnitCredential({
        unit_id: selectedUnitId,
        evo_dns: evoDns.trim(),
        token: evoToken.trim(),
        is_active: 1,
      })
      setFeedback('Credencial EVO atualizada para a unidade selecionada')
      setEvoToken('')
      await loadActiveCredential(selectedUnitId)
    } catch (err) {
      handleError(err, 'Falha ao salvar credencial EVO')
    } finally {
      setLoading(false)
    }
  }

  async function handleLoadLogs() {
    setFeedback(null)
    setError(null)
    setLoading(true)
    try {
      const response = await listLogs({
        unitId: logUnitId || undefined,
        provider: logProvider || undefined,
        success: logSuccess,
        page: 1,
        perPage: 20,
      })
      setLogs(response.data)
      setLogsMeta(response.meta)
      setFeedback(`${response.data.length} registros de log carregados`)
    } catch (err) {
      handleError(err, 'Falha ao carregar logs')
    } finally {
      setLoading(false)
    }
  }

  async function handleLoadDashboardSummary() {
    setFeedback(null)
    setError(null)
    setLoading(true)
    try {
      const response = await getDashboardSummary({
        clientId: selectedClientId || undefined,
        unitId: selectedUnitId || undefined,
        dateFrom: dashboardDateFrom || undefined,
        dateTo: dashboardDateTo || undefined,
      })
      setDashboardSummary(response.data)
      setFeedback('Dashboard atualizado com sucesso')
    } catch (err) {
      handleError(err, 'Falha ao carregar dashboard')
    } finally {
      setLoading(false)
    }
  }

  function formatCurrency(value: number) {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL',
    }).format(value)
  }

  function handleError(err: unknown, fallback: string) {
    if (err instanceof ApiError) {
      setError(`${err.status} - ${err.message}`)
      return
    }
    setError(fallback)
  }

  return (
    <section className="ek-section space-y-xl">
      <header className="space-y-sm">
        <Badge variant="secondary">EvoKore Admin</Badge>
        <h1>Clientes, Unidades, Tokens e Logs</h1>
        <p className="text-body text-muted-foreground">
          Tela de validacao manual dos fluxos administrativos.
        </p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Escopo</CardTitle>
          <CardDescription>
            Selecione cliente e unidade para as operacoes.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-md md:grid-cols-2">
          <label className="grid gap-xs text-small">
            Cliente
            <select
              className="h-10 rounded-md border border-input bg-background px-3"
              value={selectedClientId}
              onChange={(event) => setSelectedClientId(Number(event.target.value))}
            >
              {clients.map((client) => (
                <option key={client.id} value={client.id}>
                  {client.name}
                </option>
              ))}
            </select>
          </label>
          <label className="grid gap-xs text-small">
            Unidade
            <select
              className="h-10 rounded-md border border-input bg-background px-3"
              value={selectedUnitId}
              onChange={(event) => setSelectedUnitId(Number(event.target.value))}
            >
              <option value={0}>Selecione uma unidade</option>
              {units.map((unit) => (
                <option key={unit.id} value={unit.id}>
                  {unit.unit_name} ({unit.unit_code})
                </option>
              ))}
            </select>
          </label>
        </CardContent>
      </Card>

      <div className="ek-grid">
        <Card className="min-w-0">
          <CardHeader>
            <CardTitle>Cadastrar Unidade</CardTitle>
            <CardDescription>
              Crie uma unidade vinculada ao cliente selecionado.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-sm">
            <Input
              placeholder="Codigo da unidade (PAN-ABC-001)"
              value={unitCode}
              onChange={(event) => setUnitCode(event.target.value)}
            />
            <Input
              placeholder="Nome da unidade"
              value={unitName}
              onChange={(event) => setUnitName(event.target.value)}
            />
            <Input
              placeholder="Fuso horario"
              value={unitTimezone}
              onChange={(event) => setUnitTimezone(event.target.value)}
            />
            <Button onClick={handleCreateUnit} disabled={loading}>
              Cadastrar Unidade
            </Button>
          </CardContent>
        </Card>

        <Card className="min-w-0">
          <CardHeader>
            <CardTitle>Credencial EVO da Unidade</CardTitle>
            <CardDescription>
              Salve DNS e token usados para integrar esta unidade no EVO.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-sm">
            <Input
              placeholder="DNS EVO (ex.: PANOBIANCOS)"
              value={evoDns}
              onChange={(event) => setEvoDns(event.target.value)}
            />
            <Input
              placeholder="Token EVO da unidade"
              value={evoToken}
              onChange={(event) => setEvoToken(event.target.value)}
            />
            <Button
              onClick={handleSaveUnitCredential}
              disabled={loading || !selectedUnitId}
            >
              Salvar Credencial EVO
            </Button>
            {activeCredential ? (
              <div className="rounded-md border bg-muted/40 p-sm text-small">
                <p>DNS ativo: {activeCredential.evo_dns}</p>
                <p>Token ativo (dica): {activeCredential.token_hint}</p>
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card className="min-w-0">
          <CardHeader>
            <CardTitle>Token de Acesso da Unidade</CardTitle>
            <CardDescription>
              Gere o token para integracao no n8n por unidade.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-sm">
            <Input
              placeholder="Expira em (AAAA-MM-DD HH:mm:ss)"
              value={expiresAt}
              onChange={(event) => setExpiresAt(event.target.value)}
            />
            <Button onClick={handleRotateToken} disabled={loading || !selectedUnitId}>
              Rotacionar Token da Unidade
            </Button>
            {activeToken ? (
              <div className="rounded-md border bg-muted/40 p-sm text-small">
                <p>Dica do token ativo: {activeToken.token_hint}</p>
                <p>Expira em: {activeToken.expires_at ?? 'Sem expiracao'}</p>
              </div>
            ) : null}
            {createdToken ? (
              <div className="overflow-hidden rounded-md border border-success/30 bg-success/10 p-sm text-small text-success">
                <p>Token gerado (copie agora):</p>
                <p className="mt-xs break-all whitespace-normal font-mono text-xs text-success">
                  {createdToken}
                </p>
              </div>
            ) : null}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Dashboard de Inadimplencia</CardTitle>
          <CardDescription>
            Resumo por cliente, unidade e periodo para acompanhamento operacional.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-md">
          <div className="grid gap-sm md:grid-cols-4">
            <label className="grid gap-xs text-small">
              Data inicial
              <Input
                type="date"
                value={dashboardDateFrom}
                onChange={(event) => setDashboardDateFrom(event.target.value)}
              />
            </label>
            <label className="grid gap-xs text-small">
              Data final
              <Input
                type="date"
                value={dashboardDateTo}
                onChange={(event) => setDashboardDateTo(event.target.value)}
              />
            </label>
            <div className="md:col-span-2 flex items-end">
              <Button
                onClick={handleLoadDashboardSummary}
                disabled={loading}
                className="w-full"
              >
                Atualizar Dashboard
              </Button>
            </div>
          </div>

          <div className="grid gap-sm md:grid-cols-2 lg:grid-cols-4">
            <div className="rounded-md border bg-muted/30 p-sm">
              <p className="text-small text-muted-foreground">Inadimplentes</p>
              <p className="text-h3">
                {dashboardSummary?.kpis.delinquent_count ?? 0}
              </p>
            </div>
            <div className="rounded-md border bg-muted/30 p-sm">
              <p className="text-small text-muted-foreground">Valor inadimplente</p>
              <p className="text-h3">
                {formatCurrency(dashboardSummary?.kpis.delinquent_amount ?? 0)}
              </p>
            </div>
            <div className="rounded-md border bg-muted/30 p-sm">
              <p className="text-small text-muted-foreground">Regularizados</p>
              <p className="text-h3">
                {dashboardSummary?.kpis.regularized_count ?? 0}
              </p>
            </div>
            <div className="rounded-md border bg-muted/30 p-sm">
              <p className="text-small text-muted-foreground">Valor regularizado</p>
              <p className="text-h3">
                {formatCurrency(dashboardSummary?.kpis.regularized_amount ?? 0)}
              </p>
            </div>
          </div>

          <div className="grid gap-sm md:grid-cols-3">
            <div className="rounded-md border bg-muted/30 p-sm">
              <p className="text-small text-muted-foreground">
                Conversao por quantidade
              </p>
              <p className="text-h3">
                {dashboardSummary?.kpis.conversion_count_pct ?? 0}%
              </p>
            </div>
            <div className="rounded-md border bg-muted/30 p-sm">
              <p className="text-small text-muted-foreground">Conversao por valor</p>
              <p className="text-h3">
                {dashboardSummary?.kpis.conversion_amount_pct ?? 0}%
              </p>
            </div>
            <div className="rounded-md border bg-muted/30 p-sm">
              <p className="text-small text-muted-foreground">
                Links de pagamento enviados
              </p>
              <p className="text-h3">
                {dashboardSummary?.kpis.payment_link_sent_count ?? 0}
              </p>
            </div>
          </div>

          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Unidade</TableHead>
                <TableHead>Inadimplentes</TableHead>
                <TableHead>Valor inadimplente</TableHead>
                <TableHead>Regularizados</TableHead>
                <TableHead>Valor regularizado</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {(dashboardSummary?.top_units.length ?? 0) === 0 ? (
                <TableRow>
                  <TableCell colSpan={5}>
                    Nenhum dado de dashboard para os filtros selecionados
                  </TableCell>
                </TableRow>
              ) : (
                dashboardSummary!.top_units.map((row) => (
                  <TableRow key={row.unit_id}>
                    <TableCell>
                      {row.unit_name} ({row.unit_code})
                    </TableCell>
                    <TableCell>{row.delinquent_count}</TableCell>
                    <TableCell>{formatCurrency(row.delinquent_amount)}</TableCell>
                    <TableCell>{row.regularized_count}</TableCell>
                    <TableCell>{formatCurrency(row.regularized_amount)}</TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Logs de Integracao</CardTitle>
          <CardDescription>
            Filtre os logs por provedor, unidade e status.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-md">
          <div className="grid gap-sm md:grid-cols-5">
            <label className="grid gap-xs text-small">
              Provedor
              <select
                className="h-10 rounded-md border border-input bg-background px-3"
                value={logProvider}
                onChange={(event) => setLogProvider(event.target.value)}
              >
                <option value="">Todos</option>
                <option value="EVO">EVO</option>
                <option value="N8N">N8N</option>
                <option value="SYSTEM">SYSTEM</option>
              </select>
            </label>
            <label className="grid gap-xs text-small">
              Status
              <select
                className="h-10 rounded-md border border-input bg-background px-3"
                value={logSuccess}
                onChange={(event) => setLogSuccess(event.target.value as '0' | '1' | '')}
              >
                <option value="">Todos</option>
                <option value="1">Sucesso</option>
                <option value="0">Erro</option>
              </select>
            </label>
            <label className="grid gap-xs text-small">
              Unidade
              <select
                className="h-10 rounded-md border border-input bg-background px-3"
                value={logUnitId}
                onChange={(event) => setLogUnitId(Number(event.target.value))}
              >
                <option value={0}>Todas as unidades</option>
                {units.map((unit) => (
                  <option key={unit.id} value={unit.id}>
                    {unit.unit_name} ({unit.unit_code})
                  </option>
                ))}
              </select>
            </label>
            <div className="md:col-span-2 flex items-end">
              <Button onClick={handleLoadLogs} disabled={loading} className="w-full">
                Carregar Logs
              </Button>
            </div>
          </div>

          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Data/Hora</TableHead>
                <TableHead>Provedor</TableHead>
                <TableHead>Endpoint</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Unidade</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {logs.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5}>
                    Nenhum log encontrado para os filtros selecionados
                  </TableCell>
                </TableRow>
              ) : (
                logs.map((log) => (
                  <TableRow key={log.id}>
                    <TableCell>{log.created_at}</TableCell>
                    <TableCell>{log.provider}</TableCell>
                    <TableCell>{log.endpoint}</TableCell>
                    <TableCell>
                      {log.success === 1 ? (
                        <Badge variant="success">{log.http_status ?? 'OK'}</Badge>
                      ) : (
                        <Badge variant="error">
                          {log.http_status ?? 'ERR'} {log.error_message ?? ''}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell>{log.unit_name ?? '-'}</TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
          {logsMeta ? (
            <p className="text-small text-muted-foreground">
              Pagina {logsMeta.page} de {logsMeta.total_pages} - total {logsMeta.total}
            </p>
          ) : null}
        </CardContent>
      </Card>

      {feedback ? (
        <Card>
          <CardContent className="pt-6">
            <Badge variant="success">Sucesso</Badge>
            <p className="mt-sm text-small">{feedback}</p>
          </CardContent>
        </Card>
      ) : null}

      {error ? (
        <Card>
          <CardContent className="pt-6">
            <Badge variant="error">Erro</Badge>
            <p className="mt-sm text-small text-error">{error}</p>
          </CardContent>
        </Card>
      ) : null}
    </section>
  )
}
