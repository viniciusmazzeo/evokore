import { useCallback, useEffect, useMemo, useState } from 'react'

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
  type Client,
  fetchClients,
  fetchUnits,
  rotateUnitAccessToken,
  type Unit,
} from '@/services/adminApi'
import {
  getFinancialLinkByCpf,
  getFinancialStatusByCpf,
} from '@/services/financialApi'
import { ApiError } from '@/services/http'

export function TesteEvoPage() {
  const [clients, setClients] = useState<Client[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [selectedClientId, setSelectedClientId] = useState<number>(1)
  const [selectedUnitId, setSelectedUnitId] = useState<number>(0)
  const [cpf, setCpf] = useState('')
  const [unitToken, setUnitToken] = useState('')
  const [statusResult, setStatusResult] = useState<Record<string, unknown> | null>(
    null,
  )
  const [linkResult, setLinkResult] = useState<Record<string, unknown> | null>(null)
  const [feedback, setFeedback] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const selectedUnit = useMemo(
    () => units.find((unit) => unit.id === selectedUnitId) ?? null,
    [selectedUnitId, units],
  )

  const handleError = useCallback((err: unknown, fallback: string) => {
    if (err instanceof ApiError) {
      setError(`${err.status} - ${err.message}`)
      return
    }
    setError(fallback)
  }, [])

  const loadClients = useCallback(async () => {
    try {
      const response = await fetchClients()
      setClients(response)
      if (response.length > 0 && selectedClientId === 0) {
        setSelectedClientId(response[0].id)
      }
    } catch (err) {
      handleError(err, 'Falha ao carregar clientes')
    }
  }, [handleError, selectedClientId])

  const loadUnits = useCallback(
    async (clientId: number) => {
      try {
        const response = await fetchUnits(clientId)
        setUnits(response)
        if (!response.find((unit) => unit.id === selectedUnitId)) {
          setSelectedUnitId(response[0]?.id ?? 0)
        }
      } catch (err) {
        handleError(err, 'Falha ao carregar unidades')
      }
    },
    [handleError, selectedUnitId],
  )

  useEffect(() => {
    void loadClients()
  }, [loadClients])

  useEffect(() => {
    if (selectedClientId > 0) {
      void loadUnits(selectedClientId)
    }
  }, [loadUnits, selectedClientId])

  useEffect(() => {
    setUnitToken('')
    setStatusResult(null)
    setLinkResult(null)
  }, [selectedUnitId])

  async function handleGenerateTokenForTest() {
    setFeedback(null)
    setError(null)
    setLoading(true)
    try {
      if (!selectedUnitId) {
        setError('Selecione uma unidade para gerar token')
        return
      }
      const response = await rotateUnitAccessToken(selectedUnitId)
      const token = response.token ?? ''
      setUnitToken(token)
      setFeedback(
        `Token de teste gerado para ${response.unit_code}. Use este token nas consultas abaixo.`,
      )
    } catch (err) {
      handleError(err, 'Falha ao gerar token de teste')
    } finally {
      setLoading(false)
    }
  }

  async function ensureUnitToken(): Promise<string | null> {
    if (!selectedUnitId) {
      setError('Selecione uma unidade para testar')
      return null
    }

    if (unitToken.trim() !== '') {
      return unitToken.trim()
    }

    const response = await rotateUnitAccessToken(selectedUnitId)
    const generated = response.token ?? ''
    if (!generated) {
      setError('Nao foi possivel gerar token automatico para a unidade')
      return null
    }
    setUnitToken(generated)
    return generated
  }

  function normalizeCpf(value: string): string {
    return value.replace(/\D/g, '')
  }

  async function handleStatusTest() {
    setFeedback(null)
    setError(null)
    setLoading(true)
    try {
      const cpfDigits = normalizeCpf(cpf)
      if (cpfDigits.length !== 11) {
        setError('Informe um CPF valido com 11 digitos')
        return
      }
      const token = await ensureUnitToken()
      if (!token) {
        return
      }

      try {
        const response = await getFinancialStatusByCpf(token, cpfDigits)
        setStatusResult(response as unknown as Record<string, unknown>)
        setFeedback('Consulta de status concluida com sucesso')
      } catch (err) {
        if (
          err instanceof ApiError &&
          err.status === 401 &&
          err.message.toLowerCase().includes('unit token')
        ) {
          const refreshed = await rotateUnitAccessToken(selectedUnitId)
          const newToken = refreshed.token ?? ''
          if (newToken === '') {
            throw err
          }
          setUnitToken(newToken)
          const retry = await getFinancialStatusByCpf(newToken, cpfDigits)
          setStatusResult(retry as unknown as Record<string, unknown>)
          setFeedback(
            'Token expirado detectado. Novo token gerado e consulta concluida com sucesso.',
          )
          return
        }
        throw err
      }
    } catch (err) {
      handleError(err, 'Falha ao consultar status financeiro')
    } finally {
      setLoading(false)
    }
  }

  async function handleLinkTest() {
    setFeedback(null)
    setError(null)
    setLoading(true)
    try {
      const cpfDigits = normalizeCpf(cpf)
      if (cpfDigits.length !== 11) {
        setError('Informe um CPF valido com 11 digitos')
        return
      }
      const token = await ensureUnitToken()
      if (!token) {
        return
      }

      try {
        const response = await getFinancialLinkByCpf(token, cpfDigits)
        setLinkResult(response as unknown as Record<string, unknown>)
        setFeedback('Consulta de link de pagamento concluida com sucesso')
      } catch (err) {
        if (
          err instanceof ApiError &&
          err.status === 401 &&
          err.message.toLowerCase().includes('unit token')
        ) {
          const refreshed = await rotateUnitAccessToken(selectedUnitId)
          const newToken = refreshed.token ?? ''
          if (newToken === '') {
            throw err
          }
          setUnitToken(newToken)
          const retry = await getFinancialLinkByCpf(newToken, cpfDigits)
          setLinkResult(retry as unknown as Record<string, unknown>)
          setFeedback(
            'Token expirado detectado. Novo token gerado e consulta de link concluida com sucesso.',
          )
          return
        }
        throw err
      }
    } catch (err) {
      handleError(err, 'Falha ao consultar link de pagamento')
    } finally {
      setLoading(false)
    }
  }

  return (
    <section className="w-full space-y-xl">
      <header className="space-y-sm">
        <Badge variant="secondary">Teste EVO</Badge>
        <h1>Laboratorio de Integracao n8n/EVO</h1>
        <p className="text-body text-muted-foreground">
          Teste manual da ponte: token da unidade para consulta por CPF e retorno
          financeiro e link.
        </p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Configuracao do Teste</CardTitle>
          <CardDescription>
            Selecione cliente/unidade, gere token de teste e informe CPF para as
            chamadas.
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

          <label className="grid gap-xs text-small md:col-span-2">
            CPF para teste
            <Input
              placeholder="Somente numeros (11 digitos)"
              value={cpf}
              onChange={(event) => setCpf(event.target.value)}
            />
          </label>

          <label className="grid gap-xs text-small md:col-span-2">
            Token da unidade (opcional para teste manual)
            <Input
              placeholder="Se vazio, sera gerado automaticamente ao testar"
              value={unitToken}
              onChange={(event) => setUnitToken(event.target.value)}
            />
          </label>

          <div className="flex items-end md:col-span-2">
            <div className="grid w-full gap-sm md:grid-cols-3">
              <Button
                onClick={handleGenerateTokenForTest}
                disabled={loading || !selectedUnitId}
                variant="outline"
              >
                Gerar Token de Teste
              </Button>
              <Button onClick={handleStatusTest} disabled={loading}>
                Testar Financial Status
              </Button>
              <Button onClick={handleLinkTest} disabled={loading}>
                Testar Financial Link
              </Button>
            </div>
          </div>

          {selectedUnit ? (
            <p className="text-small text-muted-foreground md:col-span-2">
              Unidade selecionada: {selectedUnit.unit_name} ({selectedUnit.unit_code})
            </p>
          ) : null}
        </CardContent>
      </Card>

      <div className="grid gap-md lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Retorno: Financial Status</CardTitle>
            <CardDescription>
              Resultado da consulta de status por CPF.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <pre className="max-h-[360px] overflow-auto rounded-md border bg-muted/40 p-sm text-xs">
              {statusResult
                ? JSON.stringify(statusResult, null, 2)
                : 'Nenhum retorno ainda.'}
            </pre>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Retorno: Financial Link</CardTitle>
            <CardDescription>
              Resultado da consulta de link de pagamento por CPF.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <pre className="max-h-[360px] overflow-auto rounded-md border bg-muted/40 p-sm text-xs">
              {linkResult
                ? JSON.stringify(linkResult, null, 2)
                : 'Nenhum retorno ainda.'}
            </pre>
          </CardContent>
        </Card>
      </div>

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
