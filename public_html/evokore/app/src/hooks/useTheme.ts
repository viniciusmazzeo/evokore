import { useEffect, useState } from 'react'

type Theme = 'light' | 'dark'

export function useTheme() {
  const [theme, setTheme] = useState<Theme>(() => {
    const saved = localStorage.getItem('niokore-theme')
    if (saved === 'light' || saved === 'dark') {
      return saved
    }
    return 'dark'
  })

  useEffect(() => {
    document.documentElement.classList.toggle('dark', theme === 'dark')
    localStorage.setItem('niokore-theme', theme)
  }, [theme])

  return { theme, setTheme }
}
