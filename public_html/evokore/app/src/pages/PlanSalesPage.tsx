import { useEffect, useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  type Client,
  type EvoPlanOption,
  type Unit,
  ApiError,
  createPlanSaleForAdminTest,
  fetchClients,
  fetchUnitEvoPlans,
  fetchUnits,
  updatePlanSaleStatusForAdminTest,
} from '@/services/adminApi'

export function PlanSalesPage() {
  const [clients, setClients] = useState<Client[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [clientId, setClientId] = useState<number>()
  const [unitId, setUnitId] = useState<number>()

  const [cpf, setCpf] = useState('')
  const [customerName, setCustomerName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [plans, setPlans] = useState<EvoPlanOption[]>([])
  const [selectedPlanId, setSelectedPlanId] = useState('')
  const [saleIdToUpdate, setSaleIdToUpdate] = useState('')
  const [saleStatusToUpdate, setSaleStatusToUpdate] = useState('PAID')
  const [paidValueToUpdate, setPaidValueToUpdate] = useState('')
  const [statusNote, setStatusNote] = useState('')

  const [result, setResult] = useState<unknown>(null)
  const [message, setMessage] = useState('Use o formulario para testar o fluxo de venda vindo do n8n.')
  const [messageType, setMessageType] = useState<'info' | 'success' | 'error'>('info')
  const [loading, setLoading] = useState(false)

  const selectedUnit = useMemo(() => units.find((u) => u.id === unitId) ?? null, [units, unitId])
  const selectedPlan = useMemo(() => plans.find((p) => p.id === selectedPlanId) ?? null, [plans, selectedPlanId])

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

  useEffect(() => {
    if (!unitId) {
      setPlans([])
      setSelectedPlanId('')
      return
    }
    void loadUnitPlans(unitId)
  }, [unitId])

  async function loadClients() {
    try {
      const data = await fetchClients()
      setClients(data)
      if (data[0]) {
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
      if (data[0]) {
        setUnitId(data[0].id)
      }
    } catch (error) {
      handleError(error)
    }
  }

  async function loadUnitPlans(currentUnitId: number) {
    try {
      const data = await fetchUnitEvoPlans(currentUnitId)
      setPlans(data)
      setSelectedPlanId(data[0]?.id ?? '')
    } catch (error) {
      // Keep last list to avoid transient EVO errors wiping plans.
      handleError(error)
    }
  }

  async function handleTestSale() {
    if (!unitId || !cpf || !customerName || !selectedPlan) {
      setMessage('Preencha CPF, nome do cliente e selecione um plano.')
      setMessageType('error')
      return
    }

    setLoading(true)
    try {
      const response = await createPlanSaleForAdminTest({
        unit_id: unitId,
        cpf,
        customer_name: customerName,
        phone,
        email,
        plan_id: selectedPlan.id,
        plan_name: selectedPlan.name,
        plan_value: Number(selectedPlan.value),
      })

      setResult(response)
      setMessage('Venda de plano enviada para API com sucesso.')
      setMessageType('success')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  async function handleUpdateSaleStatus() {
    if (!unitId || !saleStatusToUpdate) {
      setMessage('Informe unidade e status da venda.')
      setMessageType('error')
      return
    }

    setLoading(true)
    try {
      const response = await updatePlanSaleStatusForAdminTest({
        unit_id: unitId,
        sale_id: saleIdToUpdate ? Number(saleIdToUpdate) : undefined,
        cpf: cpf || undefined,
        plan_name: selectedPlan?.name || undefined,
        status: saleStatusToUpdate,
        paid_value: paidValueToUpdate ? Number(paidValueToUpdate) : undefined,
        paid_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
        status_note: statusNote || undefined,
      })
      setResult(response)
      setMessage('Status da venda atualizado com sucesso.')
      setMessageType('success')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  function handleError(error: unknown) {
    let text = 'Erro inesperado.'
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
        <Badge variant="secondary">Fluxo 1</Badge>
        <h1>Venda de Planos (n8n -&gt; API -&gt; EVO)</h1>
        <p className="text-body text-muted-foreground">
          Teste manual do fluxo de venda usando a unidade selecionada e credenciais salvas no banco.
        </p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Escopo do teste</CardTitle>
          <CardDescription>Selecione cliente/unidade. Nao e necessario informar token manualmente.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-2">
          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={clientId ?? ''}
            onChange={(e) => setClientId(e.target.value ? Number(e.target.value) : undefined)}
          >
            <option value="">Selecione cliente</option>
            {clients.map((client) => (
              <option key={client.id} value={client.id}>
                {client.name}
              </option>
            ))}
          </select>

          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={unitId ?? ''}
            onChange={(e) => setUnitId(e.target.value ? Number(e.target.value) : undefined)}
          >
            <option value="">Selecione unidade</option>
            {units.map((unit) => (
              <option key={unit.id} value={unit.id}>
                {unit.unit_name} ({unit.unit_code})
              </option>
            ))}
          </select>

          <p className="text-sm text-muted-foreground md:col-span-2">
            Unidade selecionada: {selectedUnit ? `${selectedUnit.unit_name} (${selectedUnit.unit_code})` : 'Nenhuma'}
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Teste do fluxo de venda</CardTitle>
          <CardDescription>Preencha os dados e envie para simular a chamada do n8n.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <Input placeholder="CPF (somente numeros)" value={cpf} onChange={(e) => setCpf(e.target.value)} />
          <Input placeholder="Nome do cliente" value={customerName} onChange={(e) => setCustomerName(e.target.value)} />
          <Input placeholder="Telefone" value={phone} onChange={(e) => setPhone(e.target.value)} />
          <Input placeholder="E-mail" value={email} onChange={(e) => setEmail(e.target.value)} />
          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={selectedPlanId}
            onChange={(e) => setSelectedPlanId(e.target.value)}
          >
            <option value="">Selecione um plano da EVO</option>
            {plans.map((plan) => (
              <option key={plan.id} value={plan.id}>
                {plan.name} - {brl(plan.value)}
              </option>
            ))}
          </select>
          <Input placeholder="Valor do plano" value={selectedPlan ? brl(selectedPlan.value) : ''} readOnly />
          <Button className="xl:col-span-2" onClick={handleTestSale} disabled={loading}>
            {loading ? 'Enviando...' : 'Testar venda de plano'}
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Retorno de status da venda (callback n8n/EVO)</CardTitle>
          <CardDescription>Use para simular confirmacao de pagamento.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
          <Input
            placeholder="sale_id (opcional)"
            value={saleIdToUpdate}
            onChange={(e) => setSaleIdToUpdate(e.target.value)}
          />
          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={saleStatusToUpdate}
            onChange={(e) => setSaleStatusToUpdate(e.target.value)}
          >
            <option value="PAID">PAID</option>
            <option value="CONFIRMED">CONFIRMED</option>
            <option value="PENDING">PENDING</option>
            <option value="FAILED">FAILED</option>
            <option value="CANCELED">CANCELED</option>
          </select>
          <Input
            placeholder="paid_value (opcional)"
            value={paidValueToUpdate}
            onChange={(e) => setPaidValueToUpdate(e.target.value)}
          />
          <Input
            placeholder="Observacao (opcional)"
            value={statusNote}
            onChange={(e) => setStatusNote(e.target.value)}
          />
          <Button className="xl:col-span-2" onClick={handleUpdateSaleStatus} disabled={loading}>
            Atualizar status da venda
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Ultimo retorno do teste de venda</CardTitle>
        </CardHeader>
        <CardContent>
          <pre className="max-h-64 overflow-auto rounded-md border bg-background p-3 text-xs">
            {result ? JSON.stringify(result, null, 2) : 'Nenhum retorno ainda.'}
          </pre>
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

function brl(value: number): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0)
}
