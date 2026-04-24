import { ApiError } from '@/services/http'

const API_BASE_URL = import.meta.env.VITE_API_URL ?? ''

type FinancialStatusResponse = {
  cpf: string
  memberId: number
  unit_id: number
  unit_code: string
  nome_cliente: string | null
  aluno_encontrado: boolean
  total_debito_ativo: number
  total_debito_ativo_brl: string
  debtAmount: number
  dias_atraso_atual: number
  checkoutLinkFullDebt: string | null
}

type FinancialLinkResponse = {
  ok: boolean
  cpf: string | null
  memberId: number | null
  unit_id: number
  unit_code: string
  nome_cliente: string | null
  debtAmount: number
  debtAmountBrl?: string
  dias_atraso_atual: number
  checkoutLinkFullDebt: string | null
  message: string
}

async function requestWithUnitToken<T>(
  path: string,
  unitToken: string,
  query: Record<string, string>,
): Promise<T> {
  const params = new URLSearchParams(query).toString()
  const url = `${API_BASE_URL}${path}${params ? `?${params}` : ''}`

  const response = await fetch(url, {
    method: 'GET',
    headers: {
      'x-api-key': unitToken,
      'x-request-id': `ui_${Date.now()}`,
    },
  })

  if (!response.ok) {
    let message = `Request failed with status ${response.status}`
    try {
      const data = (await response.json()) as { error?: string }
      if (data?.error) {
        message = data.error
      }
    } catch {
      // Keep fallback message.
    }
    throw new ApiError(response.status, message)
  }

  return (await response.json()) as T
}

export function getFinancialStatusByCpf(
  unitToken: string,
  cpf: string,
): Promise<FinancialStatusResponse> {
  return requestWithUnitToken<FinancialStatusResponse>(
    '/financial/status',
    unitToken,
    { cpf },
  )
}

export function getFinancialLinkByCpf(
  unitToken: string,
  cpf: string,
): Promise<FinancialLinkResponse> {
  return requestWithUnitToken<FinancialLinkResponse>(
    '/financial/link',
    unitToken,
    { cpf },
  )
}

