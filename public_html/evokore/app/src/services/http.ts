const API_BASE_URL = import.meta.env.VITE_API_URL ?? ''
const API_TOKEN = import.meta.env.VITE_FINANCIAL_ENDPOINT_TOKEN ?? ''

export class ApiError extends Error {
  status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

export async function apiGet<T>(path: string): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    headers: {
      'x-api-key': API_TOKEN,
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
      // Keep default message when response is not JSON.
    }

    throw new ApiError(response.status, message)
  }

  return (await response.json()) as T
}
