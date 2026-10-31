import { mkdirSync, writeFileSync } from 'node:fs';
import { evaluateMutationGate } from './e2e-guard.mjs';
import { registerAndVerify } from './e2e-support.mjs';
import { runStage } from './load-support.mjs';

const gate = await evaluateMutationGate(process.env, { verifyRuntime: !process.argv.includes('--check-guard') });
if (process.argv.includes('--check-guard')) {
  if (gate.allowed) throw new Error('Load guard unexpectedly allowed an unconfigured runtime');
  console.log('LOAD GUARD PASSED: refuses without explicit disposable runtime and nonce proof.');
  process.exit(0);
}
if (!gate.allowed) throw new Error(`Load test refused: ${gate.reasons.join('; ')}`);
const api = process.env.CONTROL_API_URL;
const mailpit = process.env.MAILPIT_URL;
const password = process.env.E2E_PASSWORD;
const profile = process.env.LOAD_PROFILE ?? 'standard';
if (!['smoke', 'standard', 'stress'].includes(profile)) throw new Error('Unknown LOAD_PROFILE');
const stages = profile === 'smoke' ? [1, 5] : profile === 'stress' ? [1, 5, 20, 50, 100] : [1, 5, 20, 50];
const durationMs = profile === 'smoke' ? 5_000 : 15_000;
const email = `load-${Date.now()}@example.test`;
await registerAndVerify({ api, mailpit, email, password });
async function json(path, options = {}) {
  const response = await fetch(api + path, { ...options, signal: AbortSignal.timeout(15_000) });
  const body = await response.json();
  return { response, body };
}
const login = await json('/api/control/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password }) });
if (login.response.status !== 200 || typeof login.body.access_token !== 'string') throw new Error('Load fixture login failed');
const headers = { Authorization: `Bearer ${login.body.access_token}`, 'Content-Type': 'application/json' };
const account = await json('/api/control/accounts', { method: 'POST', headers: { ...headers, 'Idempotency-Key': crypto.randomUUID() }, body: JSON.stringify({ code: `load-${Date.now()}`, display_name: 'Disposable load fixture' }) });
if (account.response.status !== 201 || typeof account.body.id !== 'string') throw new Error(`Load account fixture failed: HTTP ${account.response.status}`);
const routes = [
  { path: '/api/control/catalog/plans', valid: body => Array.isArray(body.plans) && body.plans.filter(plan => plan.code?.startsWith('load.')).length >= 3 && body.plans.every(plan => Array.isArray(plan.prices) && typeof plan.features === 'object') },
  { path: '/api/control/me', valid: body => body.email === email && typeof body.id === 'string' },
  { path: '/api/control/accounts?limit=20&offset=0', valid: body => Array.isArray(body.items) && body.items.some(item => item.id === account.body.id) && body.total >= 1 },
  { path: '/api/control/auth/sessions', valid: body => Array.isArray(body.items) && body.items.some(item => item.current === true) },
];
for (const route of routes) {
  const { response, body } = await json(route.path, { headers });
  if (response.status !== 200 || !route.valid(body)) throw new Error(`Invalid baseline response: ${route.path}`);
}
const results = [];
for (const concurrency of stages) {
  const budget = { p95: 1000, errorRate: .01, minRequests: Math.max(20, concurrency * 2) };
  const stage = await runStage({ concurrency, durationMs, budget, request: async index => {
    const route = routes[index % routes.length];
    try {
      const { response, body } = await json(route.path, { headers });
      return { ok: response.status === 200 && route.valid(body), status: response.status, route: route.path };
    } catch { return { ok: false, status: 'network-or-json-error', route: route.path }; }
  } });
  results.push(stage);
  console.log(JSON.stringify({ concurrency, requests: stage.requests, errors: stage.errors, p95: Math.round(stage.p95 ?? 0),
    worstRouteP95: Math.round(Math.max(0, ...Object.values(stage.routes).map(route => route.p95 ?? 0))),
    failedRoutes: Object.entries(stage.routes).filter(([, route]) => !route.passed).map(([path]) => path), rps: Math.round(stage.rps), passed: stage.passed }));
}
mkdirSync('screenshots', { recursive: true });
const report = JSON.stringify({ profile, generatedAt: new Date().toISOString(), durationMs, workload: 'Authenticated read mix: populated catalog, identity, nonempty account list, active sessions; one shared user/session; no request retry', stages: results, passed: results.every(item => item.passed) }, null, 2);
writeFileSync('screenshots/load-commercial.json', report);
writeFileSync(`screenshots/load-commercial-${profile}.json`, report);
if (!results.every(item => item.passed)) throw new Error('Load thresholds failed; see screenshots/load-commercial.json');
console.log('COMMERCIAL LOAD PASSED. This is local closed-workload evidence, not a production capacity claim.');
