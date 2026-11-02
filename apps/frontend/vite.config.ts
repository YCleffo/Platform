import { readFileSync } from 'node:fs';
import http from 'node:http';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const coreTarget = env.VITE_CORE_PROXY_TARGET || 'http://127.0.0.1:8082';
  const commercialTarget = env.VITE_COMMERCIAL_PROXY_TARGET || 'http://localhost:8090';
  const httpsKey = process.env.VITE_HTTPS_KEY;
  const httpsCert = process.env.VITE_HTTPS_CERT;
  const allowedHosts = (process.env.VITE_ALLOWED_HOSTS || 'e2e.platform.test')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean);
  const proxyAgent = new http.Agent({ keepAlive: false });
  const configureProxy = (proxy: { on: (event: string, handler: (error: unknown, request: unknown, response: unknown) => void) => void }): void => {
    proxy.on('error', (_error, _request, response) => {
      const res = response as { headersSent?: boolean; writableEnded?: boolean; writeHead?: (status: number, headers: Record<string, string>) => void; end?: (body?: string) => void };
      if (res.writeHead !== undefined && res.end !== undefined && res.headersSent !== true && res.writableEnded !== true) {
        res.writeHead(502, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: { code: 'proxy_unavailable', message: 'Proxy connection failed.' } }));
      }
    });
  };

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
          agent: proxyAgent,
          configure: configureProxy,
        },
        '/api': {
          target: coreTarget,
          changeOrigin: true,
          agent: proxyAgent,
          configure: configureProxy,
        },
      },
    },
  };
});
