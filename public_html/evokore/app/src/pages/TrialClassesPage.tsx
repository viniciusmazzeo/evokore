import { useEffect, useMemo, useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  type Client,
  type Unit,
  ApiError,
  createTrialClassForAdminTest,
  fetchClients,
  fetchUnitTrialTimeSlots,
  fetchUnits,
  updateTrialStatusForAdminTest,
} from '@/services/adminApi'

function getTodayLocalDate(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const defaultDate = getTodayLocalDate()

export function TrialClassesPage() {
  const [clients, setClients] = useState<Client[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [clientId, setClientId] = useState<number>()
  const [unitId, setUnitId] = useState<number>()

  const [cpf, setCpf] = useState('')
  const [customerName, setCustomerName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [preferredDate, setPreferredDate] = useState(defaultDate)
  const [preferredTime, setPreferredTime] = useState('')
  const [service, setService] = useState('Aula Experimental')
  const [activity, setActivity] = useState('Aula Experimental')
  const [trialTimeSlots, setTrialTimeSlots] = useState<string[]>([])

  const [trialIdToUpdate, setTrialIdToUpdate] = useState('')
  const [trialStatusToUpdate, setTrialStatusToUpdate] = useState('COMPLETED')
  const [trialNote, setTrialNote] = useState('')

  const [result, setResult] = useState<unknown>(null)
  const [message, setMessage] = useState('Teste o fluxo de aula experimental enviado pelo n8n.')
  const [messageType, setMessageType] = useState<'info' | 'success' | 'error'>('info')
  const [loading, setLoading] = useState(false)

  const selectedUnit = useMemo(() => units.find((u) => u.id === unitId) ?? null, [units, unitId])

  useEffect(() => {
    void loadClients()
  }, [])

  useEffect(() => {
    if (!clientId) {
      setUnits([])
      setUnitId(undefined)
      setTrialTimeSlots([])
      setPreferredTime('')
      return
    }
    void loadUnits(clientId)
  }, [clientId])

  useEffect(() => {
    if (!unitId || !preferredDate) {
      setTrialTimeSlots([])
      setPreferredTime('')
      return
    }
    void loadTrialTimeSlots(unitId, preferredDate)
  }, [unitId, preferredDate])

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

  async function loadTrialTimeSlots(currentUnitId: number, date: string) {
    try {
      const slots = await fetchUnitTrialTimeSlots(currentUnitId, { date })
      setTrialTimeSlots(slots)
      setPreferredTime((prev) => {
        if (prev && slots.includes(prev)) {
          return prev
        }
        return slots[0] ?? ''
      })
    } catch {
      setTrialTimeSlots([])
      setPreferredTime('')
    }
  }

  async function handleTestTrial() {
    if (!unitId || !cpf || !customerName || !preferredDate || !preferredTime) {
      setMessage('Preencha CPF, nome do cliente, data, horario e selecione uma unidade valida.')
      setMessageType('error')
      return
    }

    setLoading(true)
    try {
      const response = await createTrialClassForAdminTest({
        unit_id: unitId,
        cpf,
        customer_name: customerName,
        phone,
        email,
        preferred_date: preferredDate,
        preferred_time: preferredTime,
        service,
        activity,
      })

      setResult(response)
      setMessage('Aula experimental enviada para API com sucesso.')
      setMessageType('success')
    } catch (error) {
      handleError(error)
    } finally {
      setLoading(false)
    }
  }

  async function handleUpdateTrialStatus() {
    if (!unitId || !trialStatusToUpdate) {
      setMessage('Selecione uma unidade valida e informe status da aula.')
      setMessageType('error')
      return
    }

    setLoading(true)
    try {
      const response = await updateTrialStatusForAdminTest({
        unit_id: unitId,
        trial_id: trialIdToUpdate ? Number(trialIdToUpdate) : undefined,
        cpf: cpf || undefined,
        preferred_date: preferredDate || undefined,
        status: trialStatusToUpdate,
        trial_date: preferredDate || undefined,
        trial_time: preferredTime || undefined,
        status_note: trialNote || undefined,
      })

      setResult(response)
      setMessage('Status da aula experimental atualizado com sucesso.')
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
        <Badge variant="secondary">Fluxo 2</Badge>
        <h1>Aula Experimental (n8n -&gt; API -&gt; EVO)</h1>
        <p className="text-body text-muted-foreground">
          Quando o cliente nao aceita o plano, cadastramos a aula experimental no EVO e seguimos o status via callback.
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
          <CardTitle>Teste do fluxo de aula experimental</CardTitle>
          <CardDescription>Preencha os dados e envie para simular a chamada do n8n.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <Input placeholder="CPF (somente numeros)" value={cpf} onChange={(e) => setCpf(e.target.value)} />
          <Input placeholder="Nome do cliente" value={customerName} onChange={(e) => setCustomerName(e.target.value)} />
          <Input placeholder="Telefone" value={phone} onChange={(e) => setPhone(e.target.value)} />
          <Input placeholder="E-mail" value={email} onChange={(e) => setEmail(e.target.value)} />
          <Input type="date" value={preferredDate} onChange={(e) => setPreferredDate(e.target.value)} />
          <Input placeholder="Servico (ex: Aula Experimental de Yoga)" value={service} onChange={(e) => setService(e.target.value)} />
          <Input placeholder="Atividade (ex: Yoga Iniciante)" value={activity} onChange={(e) => setActivity(e.target.value)} />
          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={preferredTime}
            onChange={(e) => setPreferredTime(e.target.value)}
          >
            <option value="">Selecione horario da unidade</option>
            {trialTimeSlots.map((slot) => (
              <option key={slot} value={slot}>
                {slot}
              </option>
            ))}
          </select>
          <p className="text-xs text-muted-foreground xl:col-span-2">
            Horarios carregados por unidade e data. Se nao houver vagas disponiveis, a lista pode ficar vazia.
          </p>
          <Button className="xl:col-span-2" onClick={handleTestTrial} disabled={loading}>
            {loading ? 'Enviando...' : 'Testar aula experimental'}
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Retorno de status da aula (callback n8n/EVO)</CardTitle>
          <CardDescription>Simule conclusao/cancelamento da aula experimental.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
          <Input
            placeholder="trial_id (opcional)"
            value={trialIdToUpdate}
            onChange={(e) => setTrialIdToUpdate(e.target.value)}
          />
          <select
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={trialStatusToUpdate}
            onChange={(e) => setTrialStatusToUpdate(e.target.value)}
          >
            <option value="COMPLETED">COMPLETED</option>
            <option value="DONE">DONE</option>
            <option value="SCHEDULED">SCHEDULED</option>
            <option value="CONFIRMED">CONFIRMED</option>
            <option value="CANCELED">CANCELED</option>
            <option value="NO_SHOW">NO_SHOW</option>
          </select>
          <Input
            placeholder="Observacao (opcional)"
            value={trialNote}
            onChange={(e) => setTrialNote(e.target.value)}
          />
          <Button className="xl:col-span-3" onClick={handleUpdateTrialStatus} disabled={loading}>
            Atualizar status da aula
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Ultimo retorno do teste de aula</CardTitle>
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
