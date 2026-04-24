type AppHeaderProps = {
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
}

export function AppHeader({
  currentPage,
  onChangePage,
  theme,
  onToggleTheme,
  username,
  onLogout,
}: AppHeaderProps) {
  const currentSection = currentPage.startsWith('dashboard')
    ? 'dash'
    : currentPage.startsWith('test')
      ? 'teste'
      : 'cadastro'

  return (
    <header className="border-b bg-background/80 backdrop-blur">
      <div className="mx-auto flex w-full max-w-[1400px] flex-col gap-3 px-4 py-3">
        <div className="flex items-center justify-between gap-4">
          <div className="flex items-center gap-6">
            <img
              src={theme === 'dark' ? '/branding/logo-dark.svg' : '/branding/logo-light.svg'}
              alt="NioKore"
              className="h-9 w-auto"
            />
            <nav className="flex items-center gap-2 overflow-x-auto whitespace-nowrap pr-1">
              <button
                className={`rounded-md px-3 py-1.5 text-sm transition ${
                  currentSection === 'dash'
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-secondary'
                }`}
                onClick={() => onChangePage('dashboard')}
                type="button"
              >
                Dash
              </button>
              <button
                className={`rounded-md px-3 py-1.5 text-sm transition ${
                  currentSection === 'teste'
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-secondary'
                }`}
                onClick={() => onChangePage('test-plan-sales')}
                type="button"
              >
                Teste
              </button>
              <button
                className={`rounded-md px-3 py-1.5 text-sm transition ${
                  currentSection === 'cadastro'
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-secondary'
                }`}
                onClick={() => onChangePage('cadastro')}
                type="button"
              >
                Cadastro
              </button>
            </nav>
          </div>
          <div className="flex items-center gap-3">
            {username ? <span className="text-sm text-muted-foreground">{username}</span> : null}
            <button
              className="rounded-md border border-input px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-secondary"
              onClick={onToggleTheme}
              type="button"
            >
              {theme === 'dark' ? 'Tema Claro' : 'Tema Escuro'}
            </button>
            <button
              className="rounded-md border border-input px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-secondary"
              onClick={onLogout}
              type="button"
            >
              Sair
            </button>
            <span className="text-sm text-muted-foreground">n8n + EVO</span>
          </div>
        </div>

        {currentSection === 'dash' ? (
          <nav className="flex max-w-full items-center gap-2 overflow-x-auto whitespace-nowrap pr-1">
            <button
              className={`rounded-md px-3 py-1.5 text-sm transition ${
                currentPage === 'dashboard'
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-secondary'
              }`}
              onClick={() => onChangePage('dashboard')}
              type="button"
            >
              Inadimplentes
            </button>
            <button
              className={`rounded-md px-3 py-1.5 text-sm transition ${
                currentPage === 'dashboard-plan-sales'
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-secondary'
              }`}
              onClick={() => onChangePage('dashboard-plan-sales')}
              type="button"
            >
              Vendas
            </button>
            <button
              className={`rounded-md px-3 py-1.5 text-sm transition ${
                currentPage === 'dashboard-trial-classes'
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-secondary'
              }`}
              onClick={() => onChangePage('dashboard-trial-classes')}
              type="button"
            >
              Aulas
            </button>
            <button
              className={`rounded-md px-3 py-1.5 text-sm transition ${
                currentPage === 'dashboard-logs'
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-secondary'
              }`}
              onClick={() => onChangePage('dashboard-logs')}
              type="button"
            >
              Logs
            </button>
          </nav>
        ) : null}

        {currentSection === 'teste' ? (
          <nav className="flex max-w-full items-center gap-2 overflow-x-auto whitespace-nowrap pr-1">
            <button
              className={`rounded-md px-3 py-1.5 text-sm transition ${
                currentPage === 'test-plan-sales'
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-secondary'
              }`}
              onClick={() => onChangePage('test-plan-sales')}
              type="button"
            >
              Teste Vendas
            </button>
            <button
              className={`rounded-md px-3 py-1.5 text-sm transition ${
                currentPage === 'test-trial-classes'
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-secondary'
              }`}
              onClick={() => onChangePage('test-trial-classes')}
              type="button"
            >
              Teste Aulas
            </button>
            <button
              className={`rounded-md px-3 py-1.5 text-sm transition ${
                currentPage === 'test-evo'
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-secondary'
              }`}
              onClick={() => onChangePage('test-evo')}
              type="button"
            >
              Teste Inadimplentes
            </button>
          </nav>
        ) : null}
      </div>
    </header>
  )
}
