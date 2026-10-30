import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const coreTarget = env.VITE_CORE_PROXY_TARGET || 'http://localhost:8080';
  const commercialTarget = env.VITE_COMMERCIAL_PROXY_TARGET || 'http://localhost:8090';

  return {
    plugins: [vue()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      port: 5173,
      proxy: {
        '/api/control': {
          target: commercialTarget,
          changeOrigin: true,
        },
        '/api': {
          target: coreTarget,
          changeOrigin: true,
        },
      },
    },
  };
});
