import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target =
    env.VITE_BACKEND_PROXY_TARGET ||
    'http://localhost/evokore/public_html/evokore'

  return {
    plugins: [react()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
    server: {
      proxy: {
        '/admin': {
          target,
          changeOrigin: true,
        },
        '/n8n': {
          target,
          changeOrigin: true,
        },
        '/financial': {
          target,
          changeOrigin: true,
        },
      },
    },
  }
})
