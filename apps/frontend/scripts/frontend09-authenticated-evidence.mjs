import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';

const enabled = process.env.FRONTEND09_AUTH_EVIDENCE === '1';
const tags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

function installObserver() {
  if (window.frontend09AuthenticatedMetrics) return;
  const supported = globalThis.PerformanceObserver?.supportedEntryTypes ?? [];
  const metrics = {
    longTasks: supported.includes('longtask') ? { count: 0, maxMs: 0 } : null,
    layoutShiftWithoutInput: supported.includes('layout-shift') ? 0 : null,
  };
  window.frontend09AuthenticatedMetrics = metrics;
  if (metrics.longTasks) {
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        metrics.longTasks.count++;
        metrics.longTasks.maxMs = Math.max(metrics.longTasks.maxMs, entry.duration);
      }
    }).observe({ type: 'longtask', buffered: true });
  }
  if (metrics.layoutShiftWithoutInput !== null) {
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        if (!entry.hadRecentInput) metrics.layoutShiftWithoutInput += entry.value;
      }
    }).observe({ type: 'layout-shift', buffered: true });
  }
}

/** Call only from existing isolated-runtime E2E suites, after their mutation guard. */
export async function observeAuthenticated(page) {
  if (!enabled) return;
  await page.addInitScript(installObserver);
  await page.evaluate(installObserver);
}

export async function captureAuthenticated(page, { suite, screen, mask = [] }) {
  if (!enabled) return;
  const location = new URL(page.url());
  assert.equal(location.origin, new URL(process.env.APP_URL).origin, 'Unexpected capture origin');
  assert.equal(process.env.E2E_RUNTIME, 'isolated-ci', 'Authenticated captures require isolated data');
  assert.match(suite, /^[a-z0-9-]+$/);
  assert.match(screen, /^[a-z0-9-]+$/);
  await page.locator('main').first().waitFor();
  await page.waitForFunction(() => !document.documentElement.hasAttribute('data-theme-changing'));
  const layout = await page.evaluate(async () => {
    await document.fonts.ready;
    const root = document.documentElement;
    return {
      language: root.lang.split('-')[0],
      theme: root.dataset.theme,
      viewport: [innerWidth, innerHeight],
      documentWidth: root.scrollWidth,
      clientWidth: root.clientWidth,
      heapSampleBytes: performance.memory?.usedJSHeapSize ?? null,
      metrics: window.frontend09AuthenticatedMetrics ?? null,
    };
  });
  assert.match(layout.language, /^(ru|en)$/);
  assert.match(layout.theme, /^(light|dark)$/);
  const axe = await new AxeBuilder({ page }).withTags(tags).analyze();
  const results = (rules) => rules.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }));
  const file = `${screen}-${layout.viewport.join('x')}-${layout.language}-${layout.theme}.png`;
  const directory = `screenshots/frontend09-final/authenticated/${suite}`;
  mkdirSync(directory, { recursive: true });
  const image = await page.screenshot({
    path: `${directory}/${file}`,
    animations: 'disabled',
    mask: mask.map((selector) => page.locator(selector)),
  });
  writeFileSync(`${directory}/${file.replace(/\.png$/, '.json')}`, `${JSON.stringify({
    suite,
    screen,
    file,
    sha256: createHash('sha256').update(image).digest('hex'),
    layout,
    violations: results(axe.violations),
    incomplete: results(axe.incomplete),
  }, null, 2)}\n`);
}

export async function measureAuthenticatedNavigation(page, { suite, targets }) {
  if (!enabled) return;
  assert.match(suite, /^[a-z0-9-]+$/);
  assert.ok(targets.length >= 2, 'Navigation measurements require distinct routes');
  const performanceSession = await page.context().newCDPSession(page);
  const tracked = new Set(['JSHeapUsedSize', 'JSEventListeners', 'Nodes']);
  async function sample() {
    const { metrics } = await performanceSession.send('Performance.getMetrics');
    return Object.fromEntries(metrics.filter(({ name }) => tracked.has(name)).map(({ name, value }) => [name, value]));
  }
  try {
    await performanceSession.send('Performance.enable');
    const before = await sample();
    const durations = [];
    const offset = targets[0].path === new URL(page.url()).pathname ? 1 : 0;
    for (let index = 0; index < 30; index++) {
      const target = targets[(index + offset) % targets.length];
      const start = performance.now();
      await target.locator().click();
      await page.waitForURL((url) => url.pathname === target.path);
      if (target.ready) await target.ready().waitFor();
      durations.push(performance.now() - start);
    }
    const after = await sample();
    const sorted = [...durations].sort((left, right) => left - right);
    const directory = `screenshots/frontend09-final/authenticated/${suite}`;
    mkdirSync(directory, { recursive: true });
    writeFileSync(`${directory}/navigation-metrics.json`, `${JSON.stringify({
      paths: targets.map(({ path }) => path),
      transitions: durations.length,
      p50Ms: sorted[Math.floor(sorted.length / 2)],
      maxMs: sorted.at(-1),
      before,
      after,
    }, null, 2)}\n`);
  } finally {
    await performanceSession.detach();
  }
}
