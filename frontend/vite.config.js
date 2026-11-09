import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue2'

// Vue 2.7 用の Vite 設定。
// 開発時(npm run dev)は /api をローカルの backend(8080) へプロキシする。
// 本番は nginx が /api をリバースプロキシするためこの設定は使われない。
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
  },
})
