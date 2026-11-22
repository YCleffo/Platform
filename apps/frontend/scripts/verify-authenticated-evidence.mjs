import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { launchBrowser } from './e2e-support.mjs';

// This fixture intercepts every request. It makes no calls to the app or a backend.
const origin = 'http://127.0.0.1:1';
Object.assign(process.env, { APP_URL: origin, E2E_RUNTIME: 'isolated-ci', FRONTEND09_AUTH_EVIDENCE: '1' });
const { captureAuthenticated } = await import('./frontend09-authenticated-evidence.mjs');
const workingDirectory = process.cwd();
const directory = await mkdtemp(join(tmpdir(), 'platform-authenticated-evidence-'));
let browser;
try {
  process.chdir(directory);
  browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 393, height: 852 } });
  for (const { screen, button, width, error } of [
    { screen: 'invalid-name', button: '', width: '100%', error: /axe violations: button-name/ },
    { screen: 'overflow', button: 'Continue', width: '800px', error: /horizontal overflow/ },
    { screen: 'corrected', button: 'Continue', width: '100%' },
  ]) {
    const url = `${origin}/${screen}?secret=fixture-only`;
    await page.route(url, (route) => route.fulfill({
      contentType: 'text/html',
      body: `<!doctype html><html lang="en" data-theme="light"><head><title>Evidence fixture</title></head>
        <body><main><h1>Evidence fixture</h1><div style="width:${width}"><button>${button}</button></div></main></body></html>`,
    }), { times: 1 });
    await page.goto(url);
    const capture = captureAuthenticated(page, { suite: 'fixture-role', screen });
    if (error) await assert.rejects(capture, error);
    else await capture;

    const prefix = `screenshots/frontend09-final/authenticated/fixture-role/${screen}-393x852-en-light`;
    const report = JSON.parse(await readFile(`${prefix}.json`, 'utf8'));
    assert.equal(report.status, error ? 'FAIL' : 'PASS');
    assert.equal(report.route, `/${screen}`);
    assert.equal(report.overflow, screen === 'overflow');
    assert.equal(report.violations.some(({ id }) => id === 'button-name'), screen === 'invalid-name');
    assert.ok(Array.isArray(report.incomplete));
    assert.equal(createHash('sha256').update(await readFile(`${prefix}.png`)).digest('hex'), report.sha256);
  }
  console.log('PASS: axe and overflow independently reject invalid states after writing evidence, corrected state passes.');
} finally {
  await browser?.close();
  process.chdir(workingDirectory);
  await rm(directory, { recursive: true, force: true });
}
