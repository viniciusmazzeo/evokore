import { useMemo, useState } from 'react'

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
import { apiGet, ApiError } from '@/services/http'

type FinancialStatusResponse = {
  cpf: string
  memberId: number
  nome_cliente: string | null
  total_debito_ativo_brl: string
  dias_atraso_atual: number
  checkoutLinkFullDebt: string | null
}

export function HomePage() {
  const [memberId, setMemberId] = useState('')
  const [cpf, setCpf] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<FinancialStatusResponse | null>(null)

  const queryPath = useMemo(() => {
    const params = new URLSearchParams()
    if (memberId.trim()) params.set('memberId', memberId.trim())
    if (cpf.trim()) params.set('cpf', cpf.trim())
    return `/financial/status?${params.toString()}`
  }, [memberId, cpf])

  async function handleConsultar() {
    setError(null)
    setResult(null)

    if (!memberId.trim() && !cpf.trim()) {
      setError('Informe memberId ou CPF.')
      return
    }

    setLoading(true)
    try {
      const data = await apiGet<FinancialStatusResponse>(queryPath)
      setResult(data)
    } catch (err) {
      if (err instanceof ApiError) {
        setError(`${err.status} - ${err.message}`)
      } else {
        setError('Erro inesperado ao consultar o backend.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <section className="ek-section space-y-xl">
      <header className="space-y-sm">
        <Badge variant="secondary">EvoKore Local Validation</Badge>
        <h1>Consulta de Status Financeiro</h1>
        <p className="max-w-2xl text-body text-muted-foreground">
          Teste real da API usando proxy do Vite e token no header x-api-key.
        </p>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Parametros</CardTitle>
          <CardDescription>
            Preencha memberId ou CPF e clique em Consultar.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-md sm:grid-cols-3">
          <Input
            placeholder="memberId (ex: 3149471)"
            value={memberId}
            onChange={(event) => setMemberId(event.target.value)}
          />
          <Input
            placeholder="CPF (ex: 54325699813)"
            value={cpf}
            onChange={(event) => setCpf(event.target.value)}
          />
          <Button onClick={handleConsultar} disabled={loading}>
            {loading ? 'Consultando...' : 'Consultar'}
          </Button>
        </CardContent>
      </Card>

      {error ? (
        <Card>
          <CardContent className="pt-6">
            <Badge variant="error">Erro</Badge>
            <p className="mt-sm text-small text-error">{error}</p>
          </CardContent>
        </Card>
      ) : null}

      {result ? (
        <Card>
          <CardHeader>
            <CardTitle>Resultado</CardTitle>
            <CardDescription>
              Resposta da rota /financial/status
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Cliente</TableHead>
                  <TableHead>CPF</TableHead>
                  <TableHead>Member ID</TableHead>
                  <TableHead>Dias atraso</TableHead>
                  <TableHead>Debito ativo</TableHead>
                  <TableHead>Link</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow>
                  <TableCell>{result.nome_cliente ?? '-'}</TableCell>
                  <TableCell>{result.cpf || '-'}</TableCell>
                  <TableCell>{result.memberId || '-'}</TableCell>
                  <TableCell>{result.dias_atraso_atual}</TableCell>
                  <TableCell>{result.total_debito_ativo_brl}</TableCell>
                  <TableCell>
                    {result.checkoutLinkFullDebt ? (
                      <a
                        className="text-primary underline"
                        href={result.checkoutLinkFullDebt}
                        target="_blank"
                        rel="noreferrer"
                      >
                        Abrir
                      </a>
                    ) : (
                      '-'
                    )}
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      ) : null}
    </section>
  )
}
