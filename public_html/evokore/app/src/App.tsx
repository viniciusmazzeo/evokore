import { useEffect, useState } from 'react'
import { useTheme } from '@/hooks/useTheme'
import { AppLayout } from '@/layouts/AppLayout'
import { LoginPage } from '@/pages/LoginPage'
import { CadastroPage } from '@/pages/CadastroPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { LogsDashboardPage } from '@/pages/LogsDashboardPage'
import { PlanSalesPage } from '@/pages/PlanSalesPage'
import { PlanSalesDashboardPage } from '@/pages/PlanSalesDashboardPage'
import { TesteEvoPage } from '@/pages/TesteEvoPage'
import { TrialClassesPage } from '@/pages/TrialClassesPage'
import { TrialClassesDashboardPage } from '@/pages/TrialClassesDashboardPage'
import { ApiError, fetchAdminSession, logoutAdmin, type AdminSessionUser } from '@/services/adminApi'

function App() {
  const [currentPage, setCurrentPage] = useState<
    | 'dashboard'
    | 'dashboard-plan-sales'
    | 'dashboard-trial-classes'
    | 'dashboard-logs'
    | 'test-plan-sales'
    | 'test-trial-classes'
    | 'test-evo'
    | 'cadastro'
  >('dashboard')
  const { theme, setTheme } = useTheme()
  const [sessionLoading, setSessionLoading] = useState(true)
  const [authenticated, setAuthenticated] = useState(false)
  const [user, setUser] = useState<AdminSessionUser | null>(null)

  useEffect(() => {
    let active = true

    async function loadSession() {
      try {
        const sessionUser = await fetchAdminSession()
        if (!active) return
        setUser(sessionUser)
        setAuthenticated(true)
      } catch (err) {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) {
          setAuthenticated(false)
          setUser(null)
        } else {
          setAuthenticated(false)
          setUser(null)
        }
      } finally {
        if (active) {
          setSessionLoading(false)
        }
      }
    }

    loadSession()

    return () => {
      active = false
    }
  }, [])

  function handleChangePage(
    page:
      | 'dashboard'
      | 'dashboard-plan-sales'
      | 'dashboard-trial-classes'
      | 'dashboard-logs'
      | 'test-plan-sales'
      | 'test-trial-classes'
      | 'test-evo'
      | 'cadastro',
  ) {
    setCurrentPage(page)
  }

  function handleLoginSuccess() {
    setSessionLoading(false)
    setAuthenticated(true)
    fetchAdminSession()
      .then((sessionUser) => setUser(sessionUser))
      .catch(() => setUser(null))
  }

  async function handleLogout() {
    try {
      await logoutAdmin()
    } finally {
      setAuthenticated(false)
      setUser(null)
    }
  }

  if (sessionLoading) {
    return (
      <main className="mx-auto flex min-h-screen max-w-[420px] items-center justify-center px-4 py-10">
        <p className="text-sm text-muted-foreground">Carregando sessao...</p>
      </main>
    )
  }

  if (!authenticated) {
    return <LoginPage onSuccess={handleLoginSuccess} />
  }

  return (
    <AppLayout
      currentPage={currentPage}
      onChangePage={handleChangePage}
      theme={theme}
      onToggleTheme={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
      username={user?.display_name || user?.username}
      onLogout={handleLogout}
    >
      {currentPage === 'dashboard' ? <DashboardPage /> : null}
      {currentPage === 'dashboard-plan-sales' ? <PlanSalesDashboardPage /> : null}
      {currentPage === 'dashboard-trial-classes' ? <TrialClassesDashboardPage /> : null}
      {currentPage === 'dashboard-logs' ? <LogsDashboardPage /> : null}
      {currentPage === 'test-plan-sales' ? <PlanSalesPage /> : null}
      {currentPage === 'test-trial-classes' ? <TrialClassesPage /> : null}
      {currentPage === 'test-evo' ? <TesteEvoPage /> : null}
      {currentPage === 'cadastro' ? <CadastroPage /> : null}
    </AppLayout>
  )
}

export default App
