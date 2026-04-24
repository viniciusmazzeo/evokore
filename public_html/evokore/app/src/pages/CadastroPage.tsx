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
  type UnitAccessToken,
  type UnitCredential,
  ApiError,
  createClient,
  createUnit,
  fetchClients,
  fetchIntegrationLogs,
  fetchUnitCredentials,
  fetchUnits,
  getUnitAccessToken,
  rotateUnitAccessToken,
  saveUnitCredential,
} from '@/services/adminApi'

export function CadastroPage() {
  const [clients, setClients] = useState<Client[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [selectedClientId, setSelectedClientId] = useState<number>()
  const [selectedUnitId, setSelectedUnitId] = useState<number>()

  const [newClientName, setNewClientName] = useState('')
  const [newClientLegalName, setNewClientLegalName] = useState('')

  const [newUnitCode, setNewUnitCode] = useState('')
  const [newUnitName, setNewUnitName] = useState('')
  const [newUnitTimezone, setNewUnitTimezone] = useState('America/Sao_Paulo')

  const [credentialDns, setCredentialDns] = useState('')
  const [credentialToken, setCredentialToken] = useState('')
  const [activeCredential, setActiveCredential] = useState<UnitCredential | null>(null)

  const [tokenExpiresAt, setTokenExpiresAt] = useState('')
  const [activeAccessToken, setActiveAccessToken] = useState<UnitAccessToken | null>(null)
  const [lastGeneratedToken, setLastGeneratedToken] = useState('')

  const [logs, setLogs] = useState<IntegrationLog[]>([])
  const [logProvider, setLogProvider] = useState('EVO')
  const [logSuccess, setLogSuccess] = useState('all')

  const [message, setMessage] = useState('Selecione cliente e unidade para operar.')
  const [messageType, setMessageType] = useState<'info' | 'success' | 'error'>('info')
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    void loadClients()
  }, [])

  useEffect(() => {
    if (!selectedClientId) {
      setUnits([])
      setSelectedUnitId(undefined)
      return
    }
    void loadUnits(selectedClientId)
  }, [selectedClientId])

  useEffect(() => {
    if (!selectedUnitId) {
      setActiveCredential(null)
      setActiveAccessToken(null)
      setLogs([])
      return
    }
    void loadUnitContext(selectedUnitId)
  }, [selectedUnitId])

  const selectedUnitLabel = useMemo(() => {
    const unit = units.find((item) => item.id === selectedUnitId)
    if (!unit) return 'Nenhuma unidade selecionada'
    return `${unit.unit_name} (${unit.unit_code})`
  }, [selectedUnitId, units])

  async function loadClients() {
    try {
      const data = await fetchClients()
      setClients(data)
      if (data[0]) {
        setSelectedClientId(data[0].id)
      }
    } catch (error) {
      handleError(error)
    }
  }

  async function loadUnits(clientId: number) {
    try {
      const data = await fetchUnits(clientId)
      setUnits(data)
      setSelectedUnitId(data[0]?.id)
    } catch (error) {
      handleError(error)
    }
  }

  async function loadUnitContext(unitId: number) {
    await Promise.all([loadCredential(unitId), loadAccessToken(unitId), loadLogs(unitId)])
  }

  async function loadCredential(unitId: number) {
    try {
      const list = await fetchUnitCredentials(unitId, 1)
      const credential = list[0] ?? null
      setActiveCredential(credential)
      if (credential) {
        setCredentialDns(credential.evo_dns)
      }
    } catch {
      setActiveCredential(null)
    }
  }

  async function loadAccessToken(unitId: number) {
    try {
      const token = await getUnitAccessToken(unitId)
      setActiveAccessToken(token)
    } catch {
      setActiveAccessToken(null)
    }
  }

  async function loadLogs(unitId?: number) {
    try {
      const data = await fetchIntegrationLogs({
        provider: logProvider,
        success: logSuccess === 'all' ? undefined : (Number(logSuccess) as 0 | 1),
        unit_id: unitId,
        page: 1,
        per_page: 20,
      })
      setLogs(data.data ?? [])
    } catch {
      setLogs([])
    }
  }

  async function handleCreateClient() {
    if (!newClientName.trim()) {
      setMessageType('error')
      setMessage('Nome do cliente e obrigatorio.')
      return
    }

    setLoading(true)
    try {
      await createClient({
        name: newClientName.trim(),
        legal_name: newClientLegalName.trim() || undefined,
        status: 'ACTIVE',
      })
      setNewClientName('')
      setNewClientLegalName('')
      await loadClients()
      setMessageType('success')
      setMessage('Cliente cadastrado com sucesso.')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  async function handleCreateUnit() {
    if (!selectedClientId || !newUnitCode.trim() || !newUnitName.trim()) {
      setMessageType('error')
      setMessage('Cliente, codigo e nome da unidade sao obrigatorios.')
      return
    }

    setLoading(true)
    try {
      await createUnit({
        client_id: selectedClientId,
        unit_code: newUnitCode.trim(),
        unit_name: newUnitName.trim(),
        status: 'ACTIVE',
        timezone: newUnitTimezone.trim() || 'America/Sao_Paulo',
      })

      setNewUnitCode('')
      setNewUnitName('')
      await loadUnits(selectedClientId)
      setMessageType('success')
      setMessage('Unidade cadastrada com sucesso.')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  async function handleSaveCredential() {
    if (!selectedUnitId || !credentialDns.trim() || !credentialToken.trim()) {
      setMessageType('error')
      setMessage('Unidade, DNS e token EVO sao obrigatorios.')
      return
    }

    setLoading(true)
    try {
      const saved = await saveUnitCredential({
        unit_id: selectedUnitId,
        evo_dns: credentialDns.trim(),
        token: credentialToken.trim(),
        is_active: 1,
      })
      setCredentialToken('')
      setActiveCredential(saved)
      setMessageType('success')
      setMessage('Credencial EVO salva com sucesso.')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  async function handleRotateToken() {
    if (!selectedUnitId) {
      setMessageType('error')
      setMessage('Selecione uma unidade para gerar token.')
      return
    }

    setLoading(true)
    try {
      const rotated = await rotateUnitAccessToken(selectedUnitId, tokenExpiresAt.trim() || undefined)
      setActiveAccessToken(rotated)
      setLastGeneratedToken(rotated.token ?? '')
      setMessageType('success')
      setMessage('Token da unidade rotacionado com sucesso.')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  async function handleLoadLogs() {
    await loadLogs(selectedUnitId)
    setMessageType('info')
    setMessage(`Logs carregados: ${logs.length}`)
  }

  function handleError(error: unknown) {
    let text = 'Erro inesperado.'
    if (error instanceof ApiError) {
      text = `${error.status} - ${error.message}`
    } else if (error instanceof Error) {
      text = error.message
    }
    setMessageType('error')
    setMessage(text)
  }

  return (
    <section className="space-y-6">
      <header className="space-y-2">
        <Badge variant="secondary">Cadastro e Operacao</Badge>
        <h1>Clientes, Unidades, Tokens e Logs</h1>
        <p className="text-body text-muted-foreground">
          Cadastre clientes e unidades, configure credencial EVO e gere token de acesso para n8n.
        </p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Escopo</CardTitle>
          <CardDescription>Selecione cliente e unidade para as operacoes.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-2">
          <div className="space-y-2">
            <label className="text-sm font-medium">Cliente</label>
            <select
              className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
              value={selectedClientId ?? ''}
              onChange={(event) => setSelectedClientId(event.target.value ? Number(event.target.value) : undefined)}
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
              value={selectedUnitId ?? ''}
              onChange={(event) => setSelectedUnitId(event.target.value ? Number(event.target.value) : undefined)}
            >
              {units.map((unit) => (
                <option key={unit.id} value={unit.id}>
                  {unit.unit_name} ({unit.unit_code})
                </option>
              ))}
            </select>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 xl:grid-cols-4">
        <Card>
          <CardHeader>
            <CardTitle>Cadastrar Cliente</CardTitle>
            <CardDescription>Base para novas franquias.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Input placeholder="Nome do cliente" value={newClientName} onChange={(e) => setNewClientName(e.target.value)} />
            <Input
              placeholder="Razao social (opcional)"
              value={newClientLegalName}
              onChange={(e) => setNewClientLegalName(e.target.value)}
            />
            <Button className="w-full" onClick={handleCreateClient} disabled={loading}>
              Cadastrar Cliente
            </Button>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Cadastrar Unidade</CardTitle>
            <CardDescription>Crie unidade vinculada ao cliente.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Input placeholder="Codigo da unidade (PAN-ABC-001)" value={newUnitCode} onChange={(e) => setNewUnitCode(e.target.value)} />
            <Input placeholder="Nome da unidade" value={newUnitName} onChange={(e) => setNewUnitName(e.target.value)} />
            <Input placeholder="America/Sao_Paulo" value={newUnitTimezone} onChange={(e) => setNewUnitTimezone(e.target.value)} />
            <Button className="w-full" onClick={handleCreateUnit} disabled={loading}>
              Cadastrar Unidade
            </Button>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Credencial EVO da Unidade</CardTitle>
            <CardDescription>Salve DNS e token EVO da unidade selecionada.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Input placeholder="DNS EVO da unidade" value={credentialDns} onChange={(e) => setCredentialDns(e.target.value)} />
            <Input placeholder="Token EVO da unidade" value={credentialToken} onChange={(e) => setCredentialToken(e.target.value)} />
            <Button className="w-full" onClick={handleSaveCredential} disabled={loading}>
              Salvar Credencial EVO
            </Button>
            <div className="rounded-md border border-border p-3 text-sm text-muted-foreground">
              <p>DNS ativo: {activeCredential?.evo_dns ?? '-'}</p>
              <p>Token ativo (dica): {activeCredential?.token_hint ?? '-'}</p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Token de Acesso da Unidade</CardTitle>
            <CardDescription>Token que o n8n usa no header x-api-key.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Input
              placeholder="Expira em (AAAA-MM-DD HH:mm:ss)"
              value={tokenExpiresAt}
              onChange={(e) => setTokenExpiresAt(e.target.value)}
            />
            <Button className="w-full" onClick={handleRotateToken} disabled={loading}>
              Rotacionar Token da Unidade
            </Button>
            <div className="rounded-md border border-border p-3 text-sm">
              <p>Dica do token ativo: {activeAccessToken?.token_hint ?? '-'}</p>
              <p>Expira em: {activeAccessToken?.expires_at ?? 'Sem expiracao'}</p>
            </div>
            {lastGeneratedToken ? (
              <div className="rounded-md border border-success/40 bg-success/10 p-3 text-sm text-success">
                <p>Token gerado (copie agora):</p>
                <p className="break-all">{lastGeneratedToken}</p>
              </div>
            ) : null}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Logs de Integracao</CardTitle>
          <CardDescription>Filtre por provedor, status e unidade.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-3 md:grid-cols-3">
            <select
              className="h-10 rounded-md border border-input bg-background px-3 text-sm"
              value={logProvider}
              onChange={(event) => setLogProvider(event.target.value)}
            >
              <option value="EVO">EVO</option>
              <option value="N8N">N8N</option>
              <option value="SYSTEM">SYSTEM</option>
            </select>
            <select
              className="h-10 rounded-md border border-input bg-background px-3 text-sm"
              value={logSuccess}
              onChange={(event) => setLogSuccess(event.target.value)}
            >
              <option value="all">Todos</option>
              <option value="1">Sucesso</option>
              <option value="0">Erro</option>
            </select>
            <Button onClick={() => void handleLoadLogs()}>Carregar Logs</Button>
          </div>

          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Data/Hora</TableHead>
                <TableHead>Provider</TableHead>
                <TableHead>Endpoint</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Unidade</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {logs.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5}>Nenhum log carregado</TableCell>
                </TableRow>
              ) : (
                logs.map((log) => (
                  <TableRow key={log.id}>
                    <TableCell>{log.created_at}</TableCell>
                    <TableCell>{log.provider}</TableCell>
                    <TableCell>{log.endpoint}</TableCell>
                    <TableCell>{log.success === 1 ? 'Sucesso' : `Erro (${log.http_status})`}</TableCell>
                    <TableCell>{log.unit_name ?? '-'}</TableCell>
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
          <p className="mt-1 text-xs text-muted-foreground">Unidade selecionada: {selectedUnitLabel}</p>
        </CardContent>
      </Card>
    </section>
  )
}

