import type { PropsWithChildren } from 'react'
import { AppHeader } from '@/components/AppHeader'

export function AppLayout({ children }: PropsWithChildren) {
  return (
    <div className="min-h-screen bg-muted/40">
      <AppHeader />
      <main className="mx-auto w-full max-w-5xl px-4 py-10">{children}</main>
    </div>
  )
}
