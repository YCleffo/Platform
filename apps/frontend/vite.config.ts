import { readFileSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const coreTarget = env.VITE_CORE_PROXY_TARGET || 'http://localhost:8080';
  const commercialTarget = env.VITE_COMMERCIAL_PROXY_TARGET || 'http://localhost:8090';
  const httpsKey = process.env.VITE_HTTPS_KEY;
  const httpsCert = process.env.VITE_HTTPS_CERT;
  const allowedHosts = (process.env.VITE_ALLOWED_HOSTS || 'e2e.platform.test')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean);

  return {
    plugins: [vue()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      port: 5173,
      allowedHosts,
      https:
        httpsKey && httpsCert
          ? { key: readFileSync(httpsKey), cert: readFileSync(httpsCert) }
          : undefined,
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
