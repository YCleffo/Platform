import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import { launchBrowser, navigateClientSide } from './e2e-support.mjs';

const warmup = 12;
const checkpoints = [30, 60, 100];
const options = [
  ['baseline', process.env.PERF_BASELINE_URL, process.env.PERF_BASELINE_SHA],
  ['candidate', process.env.PERF_CANDIDATE_URL, process.env.PERF_CANDIDATE_SHA],
];
for (const [label, value, sha] of options) {
  const url = new URL(value);
  assert.equal(value, url.origin, label + ' URL must be an origin');
  assert.match(url.protocol, /^https?:$/, label + ' preview must use HTTP(S)');
  assert.equal(url.hostname, '127.0.0.1', label + ' preview must use IPv4 loopback');
  assert.match(sha, /^[a-f0-9]{40}$/, label + ' SHA must be the exact build revision');
}
assert.notEqual(options[0][1], options[1][1], 'Use separate preview origins');
const viewport = { width: 1440, height: 900 };
const date = '2026-11-04T00:00:00Z';
const accountId = '019c0000-0000-7000-8000-000000000001';
const accountPath = '/accounts/' + accountId;
const account = {
  id: accountId, code: 'perf-fixture', display_name: 'Performance fixture', legal_name: 'Fixture company',
  status: 'active', onboarding_state: 'ready_for_subscription', role: 'owner',
  created_at: date, updated_at: date,
  permissions: { read_account: true, view_members: true, manage_members: true, manage_ownership: true, manage_billing: true, manage_technical: true },
};
const list = { items: [], total: 0, limit: 25, offset: 0 };
const coreSession = {
  status: 'authenticated', accessToken: 'synthetic-only', refreshToken: 'synthetic-only',
  sessionId: 'fixture', accessExpiresAt: '2099-01-01T00:00:00Z', sessionExpiresAt: '2099-01-01T00:00:00Z',
};
const commercialPerson = {
  id: '019c0000-0000-7000-8000-000000000020', email: 'fixture@example.test',
  status: 'active', email_verified_at: date, last_login_at: date, created_at: date, updated_at: date,
};
const fixture = new Map([
  ['/api/auth/login', coreSession],
  ['/api/auth/me', { account: { id: 'fixture-account', personId: null }, roles: ['core.superadmin'], permissions: [], locale: 'en', timezone: 'UTC', sessionId: 'fixture' }],
  ['/api/auth/me/settings', { schema_version: 2, settings: { interface: { animations: true, transparency: true }, notifications: { browser: false, sound: false, email: false }, privacy: { show_online_status: false } } }],
  ['/api/public/config', { settings: {} }],
  ['/api/core/modules', [{ id: 'module-1', code: 'education', name: 'Education', description: 'Learning module', version: '1.2.3', settingsSchemaVersion: 2, enabled: false, installedAt: date, enabledAt: null, disabledAt: date }]],
  ['/api/core/settings/system', []],
  ['/api/core/feature-flags', []],
  ['/api/control/auth/login', { access_token: 'synthetic-only', refresh_token: 'synthetic-only' }],
  ['/api/control/me', commercialPerson],
  ['/api/control/accounts', { ...list, items: [account], total: 1 }],
  ['/api/control/catalog/plans', { plans: [] }],
  ['/api/control' + accountPath, account],
  ['/api/control' + accountPath + '/billing-state', {
    billing_profile: { legal_name: 'Fixture company', country_code: 'RU', contact_name: '', contact_email: '', tax_identifier: null, address: {} },
    subscription_id: null, pending_invoice_id: null, pending_payment_id: null, latest_invoice_id: null, latest_payment_id: null, plan_change_id: null,
    history: list,
  }],
  ['/api/control' + accountPath + '/entitlements', { commercial_account_id: accountId, subscription: null, features: {} }],
]);
const suites = [
  {
    name: 'core-admin', login: '/admin/login?redirect=/admin', email: '#admin-identifier', password: '#admin-password',
    landing: '/admin', nav: '.admin__sidebar a.admin__link', routes: [
      { path: '/admin', selector: '.admin-dashboard' },
      { path: '/admin/modules', selector: '.config-card' },
      { path: '/admin/settings', selector: '.config-tabs' },
    ],
  },
  {
    name: 'commercial-account', login: '/login', email: '#login-email', password: '#login-password',
    landing: '/cabinet', nav: '.account-layout__tabs a', routes: [
      { path: accountPath, selector: '.overview' },
      { path: accountPath + '/invitations', text: 'No invitations yet.' },
      { path: accountPath + '/billing', selector: '#billing-legal-name' },
    ],
  },
];
const browser = await launchBrowser();
const report = {
  browserVersion: browser.version(), viewport, locale: 'en', theme: 'light', warmup, checkpoints,
  fixture: 'Only deterministic local synthetic HTTP responses. No request reaches a backend.',
  runs: [],
};

async function sample(page, cdp, transitions, collectGarbage, stabilize) {
  await stabilize();
  if (collectGarbage) await cdp.send('HeapProfiler.collectGarbage');
  const { metrics } = await cdp.send('Performance.getMetrics');
  const dom = await cdp.send('Memory.getDOMCounters');
  const globalListenerTypes = {};
  try {
    for (const name of ['window', 'document']) {
      const object = await cdp.send('Runtime.evaluate', { expression: name, objectGroup: 'navigation-sample' });
      const { listeners } = await cdp.send('DOMDebugger.getEventListeners', { objectId: object.result.objectId });
      const counts = new Map();
      for (const listener of listeners) counts.set(listener.type, (counts.get(listener.type) ?? 0) + 1);
      globalListenerTypes[name] = Object.fromEntries([...counts].sort());
    }
  } finally {
    await cdp.send('Runtime.releaseObjectGroup', { objectGroup: 'navigation-sample' });
  }
  const browserSample = await page.evaluate(() => {
    return {
      liveDocumentNodes: document.querySelectorAll('*').length,
      longTasks: window.navigationLongTasks,
    };
  });
  return { transitions, heapBytes: metrics.find(metric => metric.name === 'JSHeapUsedSize').value, ...dom, ...browserSample, globalListenerTypes };
}

function summarize(durations) {
  const sorted = [...durations].sort((left, right) => left - right);
  const percentile = (fraction) => sorted[Math.ceil(sorted.length * fraction) - 1];
  return { medianMs: percentile(0.5), p95Ms: percentile(0.95), maxMs: sorted.at(-1) };
}

async function measure(revision, origin, sha, suite, collectGarbage) {
  const context = await browser.newContext({ viewport, reducedMotion: 'reduce', serviceWorkers: 'block' });
  const apiMethods = new Set();
  const mismatches = [];
  await context.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) return route.abort();
    if (!url.pathname.startsWith('/api/')) return route.continue();
    const commercial = url.pathname.startsWith('/api/control/');
    const method = request.method();
    apiMethods.add(method + ' ' + url.pathname);
    if (method !== 'GET' && !(method === 'POST' && url.pathname.endsWith('/auth/login'))) {
      mismatches.push(method + ' ' + url.pathname);
      return route.abort();
    }
    const data = fixture.get(url.pathname) ?? (commercial ? list : { ...list, pagination: list });
    return route.fulfill({ json: commercial ? data : { status: 'success', data } });
  });
  await context.addInitScript(() => {
    localStorage.setItem('platform.locale', 'en');
    localStorage.setItem('platform.theme', 'light');
    window.navigationLongTasks = { count: 0, maxMs: 0 };
    const observer = new PerformanceObserver((entries) => {
      for (const entry of entries.getEntries()) {
        const tasks = window.navigationLongTasks;
        tasks.count++;
        tasks.maxMs = Math.max(tasks.maxMs, entry.duration);
      }
    });
    observer.observe({ type: 'longtask', buffered: true });
  });
  try {
    const page = await context.newPage();
    const errors = [];
    const pending = new Set();
    let requestCount = 0;
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      pending.add(request);
      requestCount++;
    });
    page.on('requestfinished', request => pending.delete(request));
    page.on('requestfailed', request => pending.delete(request));
    page.setDefaultTimeout(7000);
    await page.goto(origin + suite.login);
    await page.locator(suite.email).fill('fixture@example.test');
    await page.locator(suite.password).fill('Synthetic-performance-fixture');
    await page.locator('form button[type=submit]').click();
    await page.waitForURL(url => url.pathname === suite.landing);
    const home = suite.routes[0];
    if (home.path !== suite.landing) await navigateClientSide(page, home.path);
    await page.locator(home.selector).waitFor();
    const cdp = await context.newCDPSession(page);
    await cdp.send('Performance.enable');
    const cycle = [suite.routes[1], home, suite.routes[2], home];
    async function stabilize() {
      await page.evaluate(() => document.fonts.ready);
      for (let attempt = 0; attempt < 120; attempt++) {
        const count = requestCount;
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        if (pending.size === 0 && requestCount === count) return;
      }
      assert.fail('Fixture requests did not settle before the performance checkpoint');
    }
    async function transition(index) {
      const target = cycle[index % cycle.length];
      const start = performance.now();
      const link = page.locator(suite.nav).and(page.locator('a[href="' + target.path + '"]'));
      await link.click();
      await page.waitForURL(url => url.pathname === target.path);
      const content = target.selector ? page.locator(target.selector).first() : page.getByText(target.text, { exact: true });
      await content.waitFor();
      await link.and(page.locator('[aria-current="page"]')).waitFor();
      return { path: target.path, ms: performance.now() - start };
    }
    for (let index = 0; index < warmup; index++) await transition(index);
    await stabilize();
    await page.evaluate(() => { window.navigationLongTasks = { count: 0, maxMs: 0 }; });
    const snapshots = [await sample(page, cdp, 0, collectGarbage, stabilize)];
    const navigation = [];
    for (let index = 0; index < checkpoints.at(-1); index++) {
      navigation.push(await transition(index));
      if (checkpoints.includes(index + 1)) snapshots.push(await sample(page, cdp, index + 1, collectGarbage, stabilize));
    }
    assert.deepEqual(mismatches, [], 'Unexpected mutation attempted against fixtures');
    assert.deepEqual(errors, [], 'Uncaught page errors');
    const run = {
      revision, sha, suite: suite.name, collectGarbage, apiMethods: [...apiMethods].sort(), snapshots, navigation,
      timings: summarize(navigation.map(({ ms }) => ms)),
      timingByRoute: Object.fromEntries(suite.routes.map(({ path }) => [path, summarize(navigation.filter(item => item.path === path).map(({ ms }) => ms))])),
    };
    report.runs.push(run);
    console.log(JSON.stringify({ revision, suite: suite.name, collectGarbage, timings: run.timings, snapshots }));
  } finally {
    await context.close();
  }
}

try {
  for (const suite of suites) {
    for (const collectGarbage of [false, true]) {
      const pair = collectGarbage ? [...options].reverse() : options;
      for (const [label, origin, sha] of pair) await measure(label, origin, sha, suite, collectGarbage);
    }
  }
} finally {
  const output = 'screenshots/frontend09-closeout';
  mkdirSync(output, { recursive: true });
  writeFileSync(output + '/navigation-performance.json', JSON.stringify(report, null, 2) + '\n');
  await browser.close();
}
