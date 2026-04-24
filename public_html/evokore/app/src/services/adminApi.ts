export class ApiError extends Error {
  status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

export type Client = {
  id: number
  name: string
  legal_name?: string | null
  status?: string
}

export type Unit = {
  id: number
  client_id: number
  unit_code: string
  unit_name: string
  status?: string
  timezone?: string
  client_name?: string
}

export type UnitCredential = {
  id: number
  unit_id: number
  unit_name: string
  unit_code: string
  evo_dns: string
  token_hint: string
  is_active: number
}

export type UnitAccessToken = {
  id: number
  unit_id: number
  unit_name: string
  unit_code: string
  token_hint: string
  is_active: number
  expires_at: string | null
  last_used_at: string | null
  rotated_at: string | null
  token?: string
}

export type IntegrationLog = {
  id: number
  client_id?: number | null
  client_name?: string | null
  unit_id?: number | null
  provider: string
  endpoint: string
  method?: string | null
  http_status: number
  latency_ms?: number | null
  request_id?: string | null
  success: number
  error_code?: string | null
  meta_json?: string | null
  created_at: string
  unit_name?: string | null
  unit_code?: string | null
  error_message?: string | null
}

export type EvoPlanOption = {
  id: string
  name: string
  value: number
  currency: string
}

export type DashboardSummary = {
  links_generated: number
  delinquent_count: number
  delinquent_amount: number
  regularized_count: number
  regularized_amount: number
  conversion_by_links: number
  conversion_by_amount: number
}

export type DashboardUnitRow = {
  unit_id: number
  unit_name: string
  links_generated: number
  delinquent_count: number
  delinquent_amount: number
  regularized_count: number
  regularized_amount: number
  conversion_by_links: number
}

export type DashboardResponse = {
  data: {
    summary: DashboardSummary
    by_unit: DashboardUnitRow[]
    period?: {
      start_date: string
      end_date: string
    }
    meta?: {
      reason?: string
    }
  }
}

export type PlanSalesDashboardData = {
  summary: {
    total_sales: number
    total_value: number
    paid_sales: number
    paid_value: number
    links_generated: number
    conversion_by_count: number
    conversion_by_value: number
  }
  by_unit: Array<{
    unit_id: number
    unit_name: string
    total_sales: number
    total_value: number
    paid_sales: number
    paid_value: number
    links_generated: number
    conversion_by_count: number
    conversion_by_value: number
  }>
}

export type TrialDashboardData = {
  summary: {
    total_trials: number
    scheduled_trials: number
    completed_trials: number
    canceled_trials: number
    completion_rate: number
  }
  by_unit: Array<{
    unit_id: number
    unit_name: string
    total_trials: number
    scheduled_trials: number
    completed_trials: number
    canceled_trials: number
    completion_rate: number
  }>
}

type JsonResponse<T> = {
  data: T
}

export type AdminSessionUser = {
  id: number
  username: string
  display_name: string
  role: string
}

async function parseResponseBody(response: Response): Promise<unknown> {
  const raw = await response.text()
  try {
    return raw ? (JSON.parse(raw) as unknown) : null
  } catch {
    return null
  }
}

function toApiError(response: Response, body: unknown): ApiError {
  let error = `Request failed with status ${response.status}`
  if (typeof body === 'object' && body !== null) {
    const b = body as { error?: unknown; message?: unknown; detail?: unknown }
    if (typeof b.error === 'string' && b.error !== '') {
      error = b.error
    }
    if (typeof b.message === 'string' && b.message !== '') {
      error = `${error}: ${b.message}`
    } else if (typeof b.detail === 'string' && b.detail !== '') {
      error = `${error}: ${b.detail}`
    }
  }
  return new ApiError(response.status, error)
}

async function apiGet<T>(
  path: string,
  options?: {
    tokenOverride?: string
  },
): Promise<T> {
  const headers: Record<string, string> = {}
  const token = (options?.tokenOverride ?? '').trim()
  if (token) {
    headers['x-api-key'] = token
  }

  const response = await fetch(path, {
    method: 'GET',
    credentials: 'include',
    headers,
  })
  const body = await parseResponseBody(response)

  if (response.ok) {
    return body as T
  }

  throw toApiError(response, body)
}

async function apiPost<T>(path: string, payload: unknown, tokenOverride?: string): Promise<T> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  }
  const token = (tokenOverride ?? '').trim()
  if (token) {
    headers['x-api-key'] = token
  }

  const response = await fetch(path, {
    method: 'POST',
    credentials: 'include',
    headers,
    body: JSON.stringify(payload),
  })
  const body = await parseResponseBody(response)

  if (response.ok) {
    return body as T
  }

  throw toApiError(response, body)
}

export async function loginAdmin(
  username: string,
  password: string,
): Promise<AdminSessionUser> {
  const response = await apiPost<{ data: { user: AdminSessionUser } }>(
    '/admin/auth/login',
    { username, password },
  )
  return response.data.user
}

export async function fetchAdminSession(): Promise<AdminSessionUser> {
  const response = await apiGet<{ data: { user: AdminSessionUser } }>(
    '/admin/auth/me',
  )
  return response.data.user
}

export async function logoutAdmin(): Promise<void> {
  await apiPost<{ data: { ok: boolean } }>('/admin/auth/logout', {})
}

export async function fetchClients(): Promise<Client[]> {
  const response = await apiGet<JsonResponse<Client[]>>('/admin/clients')
  return response.data ?? []
}

export async function createClient(payload: {
  name: string
  legal_name?: string
  status?: 'ACTIVE' | 'INACTIVE'
}): Promise<Client> {
  const response = await apiPost<JsonResponse<Client>>('/admin/clients', payload)
  return response.data
}

export async function fetchUnits(clientId?: number): Promise<Unit[]> {
  const query = clientId ? `?client_id=${clientId}` : ''
  const response = await apiGet<JsonResponse<Unit[]>>(`/admin/units${query}`)
  return response.data ?? []
}

export async function createUnit(payload: {
  client_id: number
  unit_code: string
  unit_name: string
  status?: 'ACTIVE' | 'INACTIVE'
  timezone?: string
}): Promise<Unit> {
  const response = await apiPost<JsonResponse<Unit>>('/admin/units', payload)
  return response.data
}

export async function fetchUnitCredentials(unitId?: number, isActive?: 0 | 1): Promise<UnitCredential[]> {
  const query = new URLSearchParams()
  if (unitId) {
    query.set('unit_id', String(unitId))
  }
  if (typeof isActive === 'number') {
    query.set('is_active', String(isActive))
  }

  const suffix = query.toString() ? `?${query.toString()}` : ''
  const response = await apiGet<JsonResponse<UnitCredential[]>>(`/admin/unit-credentials${suffix}`)
  return response.data ?? []
}

export async function saveUnitCredential(payload: {
  unit_id: number
  evo_dns: string
  token: string
  is_active?: 0 | 1
}): Promise<UnitCredential> {
  const response = await apiPost<JsonResponse<UnitCredential>>('/admin/unit-credentials', payload)
  return response.data
}

export async function getUnitAccessToken(unitId: number): Promise<UnitAccessToken> {
  const response = await apiGet<JsonResponse<UnitAccessToken>>(`/admin/units/${unitId}/access-token`)
  return response.data
}

export async function rotateUnitAccessToken(unitId: number, expiresAt?: string): Promise<UnitAccessToken> {
  const response = await apiPost<JsonResponse<UnitAccessToken>>(`/admin/units/${unitId}/access-token`, {
    expires_at: expiresAt || null,
  })
  return response.data
}

export async function fetchIntegrationLogs(params?: {
  client_id?: number
  provider?: string
  success?: 0 | 1
  unit_id?: number
  http_status?: number
  date_from?: string
  date_to?: string
  q?: string
  page?: number
  per_page?: number
}): Promise<{ data: IntegrationLog[]; meta?: { page: number; total: number; total_pages: number } }> {
  const query = new URLSearchParams()
  if (params?.client_id) query.set('client_id', String(params.client_id))
  if (params?.provider) query.set('provider', params.provider)
  if (typeof params?.success === 'number') query.set('success', String(params.success))
  if (params?.unit_id) query.set('unit_id', String(params.unit_id))
  if (params?.http_status) query.set('http_status', String(params.http_status))
  if (params?.date_from) query.set('date_from', params.date_from)
  if (params?.date_to) query.set('date_to', params.date_to)
  if (params?.q) query.set('q', params.q)
  query.set('page', String(params?.page ?? 1))
  query.set('per_page', String(params?.per_page ?? 20))

  return apiGet<{ data: IntegrationLog[]; meta?: { page: number; total: number; total_pages: number } }>(
    `/admin/logs?${query.toString()}`,
  )
}

export async function fetchUnitEvoPlans(unitId: number): Promise<EvoPlanOption[]> {
  const response = await apiGet<{
    data: {
      unit_id: number
      unit_name: string
      unit_code: string
      plans: EvoPlanOption[]
    }
  }>(`/admin/units/${unitId}/evo-plans`)
  return response.data?.plans ?? []
}

export async function fetchUnitTrialTimeSlots(
  unitId: number,
  params?: {
    date?: string
    idBranch?: number
  },
): Promise<string[]> {
  const query = new URLSearchParams()
  if (params?.date) {
    query.set('date', params.date)
  }
  if (params?.idBranch) {
    query.set('id_branch', String(params.idBranch))
  }

  const suffix = query.toString() ? `?${query.toString()}` : ''
  const response = await apiGet<{
    data: {
      unit_id: number
      unit_name: string
      unit_code: string
      date?: string
      time_slots: string[]
      source?: string
      warning?: string | null
    }
  }>(`/admin/units/${unitId}/trial-time-slots${suffix}`)
  return response.data?.time_slots ?? []
}

export type DashboardSummaryKpis = {
  delinquent_count: number
  delinquent_amount: number
  regularized_count: number
  regularized_amount: number
  payment_link_sent_count: number
  conversion_count_pct: number
  conversion_amount_pct: number
}

export type DashboardTopUnitRow = {
  unit_id: number
  unit_name: string
  unit_code: string
  delinquent_count: number
  delinquent_amount: number
  regularized_count: number
  regularized_amount: number
}

export type AdminDashboardSummary = {
  kpis: DashboardSummaryKpis
  top_units: DashboardTopUnitRow[]
}

export async function fetchDashboard(params: {
  startDate: string
  endDate: string
  clientId?: number
  unitId?: number
}): Promise<DashboardResponse['data']> {
  const query = new URLSearchParams({
    start_date: params.startDate,
    end_date: params.endDate,
  })

  if (params.clientId) {
    query.set('client_id', String(params.clientId))
  }
  if (params.unitId) {
    query.set('unit_id', String(params.unitId))
  }

  const response = await apiGet<DashboardResponse>(`/admin/dashboard?${query.toString()}`)
  return response.data
}

export async function fetchPlanSalesDashboard(params: {
  startDate: string
  endDate: string
  clientId?: number
  unitId?: number
}): Promise<PlanSalesDashboardData> {
  const query = new URLSearchParams({
    start_date: params.startDate,
    end_date: params.endDate,
  })
  if (params.clientId) {
    query.set('client_id', String(params.clientId))
  }
  if (params.unitId) {
    query.set('unit_id', String(params.unitId))
  }

  const response = await apiGet<{ data: PlanSalesDashboardData }>(
    `/admin/dashboard/plan-sales?${query.toString()}`,
  )
  return response.data
}

export async function fetchTrialDashboard(params: {
  startDate: string
  endDate: string
  clientId?: number
  unitId?: number
}): Promise<TrialDashboardData> {
  const query = new URLSearchParams({
    start_date: params.startDate,
    end_date: params.endDate,
  })
  if (params.clientId) {
    query.set('client_id', String(params.clientId))
  }
  if (params.unitId) {
    query.set('unit_id', String(params.unitId))
  }

  const response = await apiGet<{ data: TrialDashboardData }>(
    `/admin/dashboard/trials?${query.toString()}`,
  )
  return response.data
}

export async function createPlanSaleForTest(
  unitToken: string,
  payload: {
    cpf: string
    customer_name: string
    phone?: string
    email?: string
    plan_name: string
    plan_value: number
  },
): Promise<unknown> {
  return apiPost('/n8n/sales/plan', payload, unitToken)
}

export async function createPlanSaleForAdminTest(payload: {
  unit_id: number
  cpf: string
  customer_name: string
  phone?: string
  email?: string
  plan_id?: string
  plan_name: string
  plan_value: number
}): Promise<unknown> {
  return apiPost('/admin/tests/plan-sale', payload)
}

export async function createTrialClassForTest(
  unitToken: string,
  payload: {
    cpf: string
    customer_name: string
    phone?: string
    email?: string
    preferred_date: string
    preferred_time?: string
    service?: string
    activity?: string
    activity_exist?: boolean
    id_branch?: number
  },
): Promise<unknown> {
  return apiPost('/n8n/sales/trial', payload, unitToken)
}

export async function createTrialClassForAdminTest(payload: {
  unit_id: number
  cpf: string
  customer_name: string
  phone?: string
  email?: string
  preferred_date: string
  preferred_time?: string
  service?: string
  activity?: string
  activity_exist?: boolean
  id_branch?: number
  status?: string
}): Promise<unknown> {
  return apiPost('/admin/tests/trial-class', payload)
}
export async function updatePlanSaleStatusForTest(
  unitToken: string,
  payload: {
    sale_id?: number
    cpf?: string
    plan_name?: string
    status: string
    paid_value?: number
    paid_at?: string
    payment_link?: string
    status_note?: string
  },
): Promise<unknown> {
  return apiPost('/n8n/sales/plan/status', payload, unitToken)
}

export async function updatePlanSaleStatusForAdminTest(payload: {
  unit_id: number
  sale_id?: number
  cpf?: string
  plan_name?: string
  status: string
  paid_value?: number
  paid_at?: string
  status_note?: string
}): Promise<unknown> {
  return apiPost('/admin/tests/plan-sale/status', payload)
}

export async function updateTrialStatusForTest(
  unitToken: string,
  payload: {
    trial_id?: number
    cpf?: string
    preferred_date?: string
    status: string
    trial_date?: string
    trial_time?: string
    status_note?: string
  },
): Promise<unknown> {
  return apiPost('/n8n/sales/trial/status', payload, unitToken)
}

export async function updateTrialStatusForAdminTest(payload: {
  unit_id: number
  trial_id?: number
  cpf?: string
  preferred_date?: string
  status: string
  trial_date?: string
  trial_time?: string
  status_note?: string
}): Promise<unknown> {
  return apiPost('/admin/tests/trial-class/status', payload)
}

// Compatibilidade com telas antigas (HomePage legado)
export async function listClients(): Promise<{ data: Client[] }> {
  return { data: await fetchClients() }
}

export async function listUnits(clientId?: number): Promise<{ data: Unit[] }> {
  return { data: await fetchUnits(clientId) }
}

export async function listUnitCredentials(params?: {
  unitId?: number
  isActive?: 0 | 1
}): Promise<{ data: UnitCredential[] }> {
  return {
    data: await fetchUnitCredentials(params?.unitId, params?.isActive),
  }
}

export async function createUnitCredential(payload: {
  unit_id: number
  evo_dns: string
  token: string
  is_active?: 0 | 1
}): Promise<{ data: UnitCredential }> {
  return { data: await saveUnitCredential(payload) }
}

export async function listLogs(params?: {
  provider?: string
  success?: '' | '0' | '1'
  unitId?: number
  page?: number
  perPage?: number
}): Promise<{ data: IntegrationLog[]; meta?: { page: number; total: number; total_pages: number } }> {
  const success =
    params?.success === '1' ? 1 : params?.success === '0' ? 0 : undefined

  return fetchIntegrationLogs({
    provider: params?.provider,
    success,
    unit_id: params?.unitId,
    page: params?.page,
    per_page: params?.perPage,
  })
}

export async function getDashboardSummary(params?: {
  clientId?: number
  unitId?: number
  dateFrom?: string
  dateTo?: string
}): Promise<{ data: AdminDashboardSummary }> {
  const query = new URLSearchParams()
  if (params?.clientId) query.set('client_id', String(params.clientId))
  if (params?.unitId) query.set('unit_id', String(params.unitId))
  if (params?.dateFrom) query.set('date_from', params.dateFrom)
  if (params?.dateTo) query.set('date_to', params.dateTo)

  const suffix = query.toString() ? `?${query.toString()}` : ''
  return apiGet<{ data: AdminDashboardSummary }>(
    `/admin/dashboard-summary${suffix}`,
  )
}

