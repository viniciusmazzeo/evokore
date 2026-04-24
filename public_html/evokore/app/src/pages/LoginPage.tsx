import { useState, type FormEvent } from 'react'
import { ApiError, loginAdmin } from '@/services/adminApi'

type LoginPageProps = {
  onSuccess: () => void
}

export function LoginPage({ onSuccess }: LoginPageProps) {
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setLoading(true)

    try {
      await loginAdmin(username.trim(), password)
      onSuccess()
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message)
      } else {
        setError('Falha ao autenticar. Tente novamente.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <main className="mx-auto flex min-h-screen w-full max-w-[520px] items-center px-4 py-10">
      <section className="w-full rounded-2xl border border-border bg-card p-6 shadow-sm">
        <div className="mb-5">
          <img
            src="/branding/logo-dark.svg"
            alt="NioKore"
            className="mb-4 h-10 w-auto dark:block"
          />
          <h1 className="text-2xl font-semibold">Login administrativo</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Acesse o painel com seu usuário e senha.
          </p>
        </div>

        <form className="space-y-4" onSubmit={handleSubmit}>
          <label className="block text-sm">
            <span className="mb-1 block text-muted-foreground">Usuário</span>
            <input
              className="w-full rounded-md border border-input bg-background px-3 py-2 outline-none ring-offset-background focus:ring-2 focus:ring-ring"
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              autoComplete="username"
              required
            />
          </label>

          <label className="block text-sm">
            <span className="mb-1 block text-muted-foreground">Senha</span>
            <input
              className="w-full rounded-md border border-input bg-background px-3 py-2 outline-none ring-offset-background focus:ring-2 focus:ring-ring"
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="current-password"
              required
            />
          </label>

          {error ? (
            <p className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-400">
              {error}
            </p>
          ) : null}

          <button
            className="w-full rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-70"
            disabled={loading}
            type="submit"
          >
            {loading ? 'Entrando...' : 'Entrar'}
          </button>
        </form>
      </section>
    </main>
  )
}
