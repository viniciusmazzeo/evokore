import type { PropsWithChildren } from 'react'
import { AppHeader } from '@/components/AppHeader'

type AppLayoutProps = PropsWithChildren<{
  currentPage:
    | 'dashboard'
    | 'dashboard-plan-sales'
    | 'dashboard-trial-classes'
    | 'dashboard-logs'
    | 'test-plan-sales'
    | 'test-trial-classes'
    | 'test-evo'
    | 'cadastro'
  onChangePage: (
    page:
      | 'dashboard'
      | 'dashboard-plan-sales'
      | 'dashboard-trial-classes'
      | 'dashboard-logs'
      | 'test-plan-sales'
      | 'test-trial-classes'
      | 'test-evo'
      | 'cadastro',
  ) => void
  theme: 'light' | 'dark'
  onToggleTheme: () => void
  username?: string
  onLogout: () => void
}>

export function AppLayout({
  children,
  currentPage,
  onChangePage,
  theme,
  onToggleTheme,
  username,
  onLogout,
}: AppLayoutProps) {
  return (
    <div className="min-h-screen bg-muted/40">
      <AppHeader
        currentPage={currentPage}
        onChangePage={onChangePage}
        theme={theme}
        onToggleTheme={onToggleTheme}
        username={username}
        onLogout={onLogout}
      />
      <main className="mx-auto w-full max-w-[1400px] px-4 py-8">{children}</main>
    </div>
  )
}
