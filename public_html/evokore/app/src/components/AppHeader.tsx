export function AppHeader() {
  return (
    <header className="border-b bg-background/80 backdrop-blur">
      <div className="mx-auto flex h-16 w-full max-w-5xl items-center justify-between px-4">
        <strong className="text-sm uppercase tracking-[0.2em]">Evokore</strong>
        <span className="text-sm text-muted-foreground">
          Frontend Vite + React + TS
        </span>
      </div>
    </header>
  )
}
