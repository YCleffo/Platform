// Narrow local smoke/measurement, not the repository E2E suite.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { launchBrowser, selectDropdown, navigateClientSide } from '../../apps/frontend/scripts/e2e-support.mjs';
import AxeBuilder from '../../apps/frontend/node_modules/@axe-core/playwright/dist/index.mjs';

const baseline = process.argv.includes('--baseline');
const origin = baseline ? 'http://127.0.0.1:5179' : 'http://127.0.0.1:5178';
const root = new URL('../../runtime/education-local/', import.meta.url);
const seed = JSON.parse(readFileSync(new URL('data/access.json', root), 'utf8'));
const env = Object.fromEntries(readFileSync(new URL('local.env', root), 'utf8').trim().split(/\r?\n/).map(line => line.split(/=(.*)/s).slice(0, 2)));
const expected = createHash('sha256').update(env.EDUCATION_LOCAL_PROOF).digest('hex');
for (const api of [origin, 'http://127.0.0.1:8088']) {
  const response = await fetch(`${api}/api/health/isolation`);
  assert.equal(response.status, 200, 'A proven isolated runtime is required.');
  assert.equal((await response.json()).data.nonce, expected, 'Unexpected backend target.');
}
const out = new URL('smoke/', root); mkdirSync(out, { recursive: true });
const result = { baseline, origin, generatedAt: new Date().toISOString(), navigation: [], widths: [], api: [], scenarios: [], errors: [] };
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
await context.addInitScript(() => { localStorage.setItem('platform.locale', 'ru'); localStorage.setItem('platform.theme', 'light'); });
const page = await context.newPage();
page.setDefaultTimeout(25000);
page.on('pageerror', e => result.errors.push(e.message));
let access; let clientHeaders = {}; let educationRequests = 0;
page.on('request', r => {
  if (new URL(r.url()).pathname.startsWith('/api/education')) educationRequests++;
  if (new URL(r.url()).pathname === '/api/auth/login') clientHeaders = Object.fromEntries(Object.entries(r.headers()).filter(([k]) => k.startsWith('x-client-')));
});
page.on('response', async r => {
  if (new URL(r.url()).pathname === '/api/auth/login' && r.ok()) access = (await r.json()).data.accessToken;
});
async function api(path, { method = 'GET', body, status = 200, org = seed.organizationId } = {}) {
  assert.ok(access, 'Real Core login required.');
  const start = performance.now();
  const response = await context.request.fetch(`${origin}/api/education${path}`, { method,
    headers: { ...clientHeaders, Authorization: `Bearer ${access}`, 'X-Education-Organization': org },
    ...(body === undefined ? {} : { data: body }) });
  const value = await response.json();
  assert.equal(response.status(), status, `${method} ${path}: ${JSON.stringify(value.error ?? value)}`);
  assert.match(response.headers()['cache-control'], /no-store/);
  if (method === 'GET') result.api.push({ path: path.replace(/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}/g, ':id'), ms: +(performance.now() - start).toFixed(1) });
  return value.data;
}
async function goto(path) { await navigateClientSide(page, path); }
async function readyToday() { await page.locator('.edu-quick__action').first().waitFor(); }
async function inspect(label, { axe = false } = {}) {
  await page.evaluate(() => document.fonts.ready);
  const overflow = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth,
    offenders: [...document.querySelectorAll('main *')].filter(e => e.getBoundingClientRect().right > innerWidth + 1 && getComputedStyle(e).position !== 'absolute').slice(0, 8).map(e => e.tagName + '.' + e.className) }));
  const entry = { label, ...overflow };
  if (axe) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    entry.axe = scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }));
  }
  result.widths.push(entry);
  await page.screenshot({ path: fileURLToPath(new URL(`${baseline ? 'baseline-' : ''}${label}.png`, out)), fullPage: true });
  assert.ok(overflow.document <= overflow.viewport + 1, label + ': page overflow');
  if (entry.axe) assert.deepEqual(entry.axe, [], label + ': accessibility violations');
}
try {
  await page.goto(origin + '/education/login');
  await page.locator('#education-email').fill(seed.accounts.teacher.email);
  await page.locator('#education-password').fill(seed.accounts.teacher.password);
  await page.locator('#education-password').press('Enter');
  await page.waitForURL('**/today'); await readyToday();
  result.scenarios.push('Real Core login and Education workspace loaded.');
  for (let round = 0; round < 6; round++) {
    for (const path of ['/calendar', '/today']) {
      const before = educationRequests; const start = performance.now();
      await page.locator(`a[href="${path}"]`).filter({ visible: true }).first().click();
      if (path === '/calendar') await page.locator('#education-calendar-date').waitFor(); else await readyToday();
      // Includes browser action scheduling, route commit, and the first displayed content.
      const ms = +(performance.now() - start).toFixed(1);
      await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
      result.navigation.push({ round, path, ms, requests: educationRequests - before });
    }
  }
  for (let i = 0; i < 12; i++) await api('/groups');
  if (!baseline) {
    const progress = await api(`/groups/${seed.groups[0].id}/progress`);
    assert.ok(progress.programs.some(p => p.completedLessons > 0));
    await api(`/groups/${seed.groups[0].id}`, { org: seed.otherOrganizationId, status: 403 });
    result.scenarios.push('Saved curriculum progress and organization boundary verified through real HTTP.');
    for (const width of [375, 768, 1280, 1920]) {
      await page.setViewportSize({ width, height: 900 });
      for (const theme of ['light', 'dark']) {
        await page.evaluate(async value => { const { useTheme } = await import('/src/app/theme/index.ts'); useTheme().setTheme(value); }, theme);
        await goto('/calendar?view=week'); await page.locator('.edu-time-grid').waitFor();
        await inspect(`calendar-${width}-${theme}`, { axe: true });
        await goto(`/groups/${seed.groups[0].id}`); await page.locator('#education-group-name').waitFor();
        await page.locator('.edu-progress').first().waitFor();
        await inspect(`group-${width}-${theme}`, { axe: width === 1280 });
      }
    }
    await page.setViewportSize({ width: 1280, height: 900 });
    await goto(`/programs/${seed.curriculum.program.id}`);
    await page.locator('#new-module-name').waitFor(); await inspect('program-1280-dark', { axe: true });
    // Exercise the complete new teacher workflow through forms and the same API.
    const suffix = Date.now().toString(36);
    await goto('/programs');
    await page.getByRole('button', { name: 'Создать программу', exact: true }).click();
    await page.locator('#program-name').fill('Программа с нуля ' + suffix);
    await page.locator('#program-description').fill('Проверка полного учебного сценария через формы.');
    await page.locator('form').getByRole('button', { name: 'Сохранить', exact: true }).click();
    await page.waitForURL(url => /^\/programs\/[a-f0-9-]+$/.test(url.pathname));
    const flowProgramId = new URL(page.url()).pathname.split('/').at(-1);
    await page.locator('#new-module-name').fill('Модуль с нуля');
    await page.locator('form').filter({ has: page.locator('#new-module-name') }).getByRole('button').click();
    await page.locator('input[id^="new-lesson-"]').waitFor();
    const module = (await api(`/programs/${flowProgramId}`)).modules[0];
    await page.locator(`#new-lesson-${module.id}`).fill('Урок с нуля');
    await page.locator('form').filter({ has: page.locator(`#new-lesson-${module.id}`) }).getByRole('button').click();
    await page.locator('.edu-module__lessons strong').filter({ hasText: 'Урок с нуля' }).waitFor();
    const flowLesson = (await api(`/programs/${flowProgramId}`)).modules[0].lessons[0];
    await goto('/groups/new');
    const groupName = 'Проверка с нуля ' + suffix;
    await page.locator('#education-create-title').fill(groupName);
    await page.locator('form button[type=submit]').click(); await page.waitForURL(url => /^\/groups\/[a-f0-9-]+$/.test(url.pathname));
    const group = (await api('/groups')).items.find(g => g.name === groupName); assert.ok(group);
    await goto(`/students/new?group=${group.id}`);
    await page.locator('#education-create-group').waitFor();
    if ((await page.locator('#education-create-group').getAttribute('aria-disabled')) !== 'true') await selectDropdown(page, 'education-create-group', group.id);
    await page.locator('#student-given-name').fill('Елена');
    await page.locator('#student-family-name').fill('Проверочная');
    await page.locator('form button[type=submit]').click(); await page.waitForURL(`**/groups/${group.id}`);
    const roster = (await api(`/groups/${group.id}/students`)).items; assert.equal(roster.length, 1);
    await goto(`/groups/${group.id}`); await page.locator('#group-program').waitFor();
    await selectDropdown(page, 'group-program', flowProgramId);
    await page.getByRole('button', { name: 'Назначить программу', exact: true }).click();
    await page.locator('.edu-progress').first().waitFor();
    const start = new Date(Date.now() - 24 * 3600000);
    const wall = instant => {
      const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Yekaterinburg', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(instant).map(p => [p.type, p.value]));
      return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
    };
    await goto(`/lessons/new?group=${group.id}`);
    await page.locator('#education-create-title').fill('Проверка результата');
    await selectDropdown(page, 'education-create-group', group.id);
    await page.locator('#education-curriculum-lesson').waitFor();
    await selectDropdown(page, 'education-curriculum-lesson', flowLesson.id);
    await page.locator('#education-starts-at').fill(wall(start));
    await page.locator('#education-ends-at').fill(wall(new Date(start.getTime() + 30 * 60000)));
    await page.locator('form button[type=submit]').click();
    await page.waitForURL(url => /^\/lessons\/[a-f0-9-]+$/.test(url.pathname));
    const lesson = { id: new URL(page.url()).pathname.split('/').at(-1) };
    await page.getByRole('link', { name: 'Список', exact: true }).click();
    await selectDropdown(page, `education-attendance-${roster[0].id}`, 'present');
    await page.getByRole('link', { name: 'Открыть занятие', exact: true }).click();
    await page.getByRole('button', { name: 'Завершить занятие', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Завершить занятие', exact: true }).click();
    await page.getByText('Завершено', { exact: true }).first().waitFor();
    await goto(`/lessons/${lesson.id}/results`);
    await selectDropdown(page, `education-result-${roster[0].id}`, 'mastered');
    await page.evaluate(() => {
      const button = [...document.querySelectorAll('button')].find(node => node.textContent.trim() === 'Сохранить результат');
      window.__educationReaction = new Promise(resolve => button.addEventListener('click', () => {
        const started = performance.now();
        requestAnimationFrame(() => resolve({ durationMs: performance.now() - started, disabled: button.disabled }));
      }, { once: true, capture: true }));
    });
    await Promise.all([
      page.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname.endsWith(`/results/${roster[0].id}`) && r.ok()),
      page.getByRole('button', { name: 'Сохранить результат', exact: true }).click(),
    ]);
    result.visualReaction = await page.evaluate(() => window.__educationReaction);
    assert.equal(result.visualReaction.disabled, true, 'Save must show its pending state at the next paint.');
    const after = await api(`/groups/${group.id}/progress`); assert.equal(after.programs[0].completedLessons, 1);
    const studentProgress = await api(`/students/${roster[0].id}/curriculum-progress`); assert.equal(studentProgress.programs[0].completedLessons, 1);
    result.scenarios.push('Full workflow in UI: create program/module/lesson, group and Core student; assign program, schedule lesson, mark attendance, complete, save result; HTTP projections confirm group/student progress.');
    result.telemetry = await page.evaluate(async () => (await import('/src/modules/education/cache.ts')).educationMeasurements.snapshot());
    const material = await context.request.get(`${origin}/api/education/files/${seed.curriculum.material.id}`, {
      headers: { ...clientHeaders, Authorization: `Bearer ${access}`, 'X-Education-Organization': seed.organizationId } });
    assert.equal(material.status(), 200); assert.match(await material.text(), /Local Education lesson material/);
    // A fresh browser context proves that student-only login works without a
    // teacher assignment, and that no teacher ID can widen its access.
    const studentContext = await browser.newContext({ viewport: { width: 375, height: 900 }, reducedMotion: 'reduce' });
    const studentPage = await studentContext.newPage(); studentPage.setDefaultTimeout(25000);
    await studentContext.addInitScript(() => { localStorage.setItem('platform.locale', 'ru'); });
    let studentToken; let studentHeaders = {};
    studentPage.on('request', request => { if (new URL(request.url()).pathname === '/api/auth/login') studentHeaders = Object.fromEntries(Object.entries(request.headers()).filter(([key]) => key.startsWith('x-client-'))); });
    studentPage.on('response', async response => { if (new URL(response.url()).pathname === '/api/auth/login' && response.ok()) studentToken = (await response.json()).data.accessToken; });
    studentPage.on('pageerror', error => result.errors.push(error.message));
    await studentPage.goto(origin + '/education/login');
    await studentPage.locator('#education-email').fill(seed.curriculum.studentAccount.email);
    await studentPage.locator('#education-password').fill(seed.curriculum.studentAccount.password);
    await studentPage.locator('#education-password').press('Enter'); await studentPage.waitForURL('**/student/home');
    await studentPage.locator('a[href="/student/progress"]').filter({ visible: true }).first().click();
    await studentPage.locator('.edu-progress').first().waitFor();
    await studentPage.screenshot({ path: fileURLToPath(new URL('student-progress-375.png', out)), fullPage: true });
    assert.ok(await studentPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    const restricted = await studentContext.request.get(`${origin}/api/education/students/${roster[0].id}/curriculum-progress`, {
      headers: { ...studentHeaders, Authorization: `Bearer ${studentToken}`, 'X-Education-Organization': seed.organizationId } });
    assert.equal(restricted.status(), 403);
    await studentContext.close();
    result.scenarios.push('Private lesson material downloaded; student-only Core login opens own cabinet/progress; teacher student-ID endpoint denied.');
    await page.setViewportSize({ width: 375, height: 900 });
    await goto('/calendar'); await page.locator('.edu-time-grid').waitFor();
    await page.evaluate(() => {
      document.documentElement.style.setProperty('--color-primary-500', '#7c3aed');
      document.documentElement.style.setProperty('--color-link', document.documentElement.dataset.theme === 'dark' ? '#c4b5fd' : '#6d28d9');
    });
    await inspect('accent-375', { axe: true });
    await page.evaluate(() => { document.documentElement.style.removeProperty('--color-primary-500'); document.documentElement.style.removeProperty('--color-link'); });
    await page.locator('.edu-skip').focus(); await page.keyboard.press('Enter');
    assert.equal(await page.locator('#education-main').evaluate(element => element === document.activeElement), true);
    await goto('/groups/00000000-0000-7000-8000-000000000000');
    await page.getByRole('alert').waitFor(); await inspect('real-not-found-375');
    const emptyContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const emptyPage = await emptyContext.newPage(); emptyPage.setDefaultTimeout(25000);
    await emptyPage.goto(origin + '/education/login');
    await emptyPage.locator('#education-email').fill(seed.accounts.outsider.email);
    await emptyPage.locator('#education-password').fill(seed.accounts.outsider.password);
    await emptyPage.locator('#education-password').press('Enter');
    await emptyPage.locator('.edu-onboarding').waitFor();
    await emptyPage.screenshot({ path: fileURLToPath(new URL('empty-organization-1280.png', out)), fullPage: true });
    await emptyContext.close();
    result.scenarios.push('Shared accent tokens, keyboard skip-to-content, real 404 error and empty-organization first-run actions verified.');
  } else await inspect('today-1280-light');
} catch (error) {
  result.failure = error.stack; process.exitCode = 1;
  await page.screenshot({ path: fileURLToPath(new URL(`${baseline ? 'baseline-' : ''}failure.png`, out)), fullPage: true }).catch(() => {});
} finally {
  result.educationRequests = educationRequests;
  writeFileSync(new URL(`${baseline ? 'baseline' : 'current'}.json`, out), JSON.stringify(result, null, 2));
  await browser.close();
}
console.log(JSON.stringify({ baseline, navigation: result.navigation, api: result.api, widths: result.widths,
  scenarios: result.scenarios, errors: result.errors, failure: result.failure }, null, 2));
