import assert from 'node:assert/strict';
import dns from 'node:dns';
import { createHmac, randomBytes, createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { evaluateMutationGate } from './e2e-guard.mjs';
import { registerAndVerify } from './e2e-support.mjs';

export const env = process.env;
export const APP = env.APP_URL;
export const API = env.CONTROL_API_URL;
export const suffix = randomBytes(5).toString('hex');
export const observations = [];
let databaseContainer;
let appContainer;
function docker(args, input) {
  try { return execFileSync('docker', args, { input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }).trim(); }
  catch (error) {
    if (env.E2E_DOCKER_ERROR_FILE) writeFileSync(env.E2E_DOCKER_ERROR_FILE, String(error.stdout ?? '') + String(error.stderr ?? ''));
    throw new Error('Isolated Docker operation failed; credentials and arguments suppressed.');
  }
}
export async function guard() {
  if (process.argv.includes('--check-guard')) {
    const gate = await evaluateMutationGate(env, { verifyRuntime: false });
    assert.equal(gate.allowed, false, 'Guard check requires an unconfigured runtime');
    console.log('ACCEPTANCE GUARD PASSED: refuses without explicit isolation and nonce proof.');
    process.exit(0);
  }
  // A process-local reserved test alias, useful on Windows without editing the machine hosts file.
  if (env.E2E_LOOPBACK_ALIAS === '1') {
    assert.equal(env.E2E_RUNTIME, 'isolated-ci');
    const lookup = dns.lookup;
    dns.lookup = function (host, options, callback) {
      if (host !== 'e2e.platform.test') return lookup.apply(this, arguments);
      if (typeof options === 'function') { callback = options; options = {}; }
      queueMicrotask(() => options?.all ? callback(null, [{ address: '127.0.0.1', family: 4 }]) : callback(null, '127.0.0.1', 4));
    };
  }
  const gate = await evaluateMutationGate(env);
  assert.ok(gate.allowed, gate.reasons.join('; '));
  assert.ok(env.COMPOSE_PROJECT_NAME && env.COMPOSE_PROJECT_NAME !== 'platform', 'A dedicated COMPOSE_PROJECT_NAME is required');
  for (const service of ['control-postgres-test', 'control-plane-test']) {
    const ids = docker(['ps', '-q', '--filter', `label=com.docker.compose.project=${env.COMPOSE_PROJECT_NAME}`, '--filter', `label=com.docker.compose.service=${service}`]).split('\n');
    assert.equal(ids.length, 1); assert.ok(ids[0]);
    const meta = JSON.parse(docker(['inspect', ids[0]]))[0];
    assert.equal(meta.Config.Labels['com.docker.compose.project'], env.COMPOSE_PROJECT_NAME);
    if (env.BILLING_TEST_CLOCK_DIRECTORY) {
      assert.equal(meta.Config.Labels['org.platform.testing.clock'], '1');
      assert.ok(meta.Config.Env.includes('PLATFORM_TEST_CLOCK=1'));
      assert.ok(meta.Config.Env.includes('CONTROL_E2E_ISOLATION_NONCE=' + env.E2E_ISOLATION_PROOF));
      const mount = meta.Mounts.find((item) => item.Destination === '/run/platform-test-clock');
      assert.ok(mount && !mount.RW);
      const source = mount.Source.replaceAll('\\', '/').toLowerCase().replace(/^\/run\/desktop\/mnt\/host\/([a-z])\//, '$1:/');
      assert.equal(source, env.BILLING_TEST_CLOCK_DIRECTORY.replaceAll('\\', '/').toLowerCase());
    }
    if (service === 'control-postgres-test') {
      assert.ok(meta.Config.Env.includes('POSTGRES_DB=platform_commercial_test')); databaseContainer = ids[0];
    } else {
      assert.ok(meta.Config.Env.includes('CONTROL_TEST_ISOLATED=1'));
      assert.ok(meta.Config.Env.includes('CONTROL_BROWSER_PAYMENT_FIXTURE=1')); appContainer = ids[0];
    }
  }
}
export function sql(query) {
  assert.ok(databaseContainer);
  return docker(['exec', '-i', databaseContainer, 'sh', '-c', 'exec psql -X -qAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'], query);
}
const php = () => env.BILLING_TEST_CLOCK_DIRECTORY ? ['/opt/platform-test/entrypoint', 'php'] : ['php'];
export function fixture(mode, extra = {}) {
  const ownerUrl = `postgresql://${encodeURIComponent(env.CONTROL_TEST_USER)}:${encodeURIComponent(env.CONTROL_TEST_PASSWORD)}@control-postgres-test:5432/platform_commercial_test?serverVersion=18&charset=utf8`;
  return JSON.parse(docker(['exec', '-i', appContainer, ...php(), 'tests/Support/browser-financial-fixture.php'], JSON.stringify({ mode, suffix, owner_database_url: ownerUrl, ...extra })));
}
export function command(name, args = []) { return docker(['exec', appContainer, ...php(), 'bin/console', name, ...args]); }
export function startScheduler() { return docker(['exec', '-d', appContainer, ...php(), 'bin/console', 'control:scheduler:run']); }
export function setBillingClock(value) {
  assert.ok(env.BILLING_TEST_CLOCK_DIRECTORY && appContainer && databaseContainer);
  assert.match(value, /^\+\d+s\n$/);
  // Pause both clock readers while updating the small shared file. This also
  // works with Docker Desktop mounts that cannot atomically replace open files.
  const paused = [];
  try {
    for (const id of [appContainer, databaseContainer]) { docker(['pause', id]); paused.push(id); }
    writeFileSync(join(env.BILLING_TEST_CLOCK_DIRECTORY, 'time'), value, 'ascii');
  } finally { for (const id of paused.reverse()) docker(['unpause', id]); }
}
export function provider(change = null) {
  const path = '/tmp/platform-browser-provider-' + createHash('sha256').update(env.E2E_ISOLATION_PROOF).digest('hex') + '.json';
  const program = '$p=$argv[1];$f=fopen($p,"c+");flock($f,LOCK_EX);$v=stream_get_contents($f);$s=$v?json_decode($v,true,flags:JSON_THROW_ON_ERROR):[];$c=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);if(is_array($c)){$s=array_replace_recursive($s,$c);rewind($f);ftruncate($f,0);fwrite($f,json_encode($s,JSON_THROW_ON_ERROR));}echo json_encode($s,JSON_THROW_ON_ERROR);flock($f,LOCK_UN);fclose($f);';
  return JSON.parse(docker(['exec', '-i', appContainer, ...php(), '-r', program, path], JSON.stringify(change)));
}
export async function api(path, { method = 'GET', body, token, expected = 200, key } = {}) {
  const headers = { Accept: 'application/json' };
  if (token) headers.Authorization = 'Bearer ' + token;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (key) headers['Idempotency-Key'] = key;
  let response;
  try { response = await fetch(API + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), signal: AbortSignal.timeout(25000) }); }
  catch { throw new Error('Acceptance API unavailable; request credentials suppressed.'); }
  const value = response.status === 204 ? null : await response.json();
  observations.push({ method, path, status: response.status });
  assert.ok((Array.isArray(expected) ? expected : [expected]).includes(response.status), `${method} ${path}: HTTP ${response.status} ${value?.error?.code ?? 'unexpected status'}`);
  return value;
}
export function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = [...secret].map((c) => alphabet.indexOf(c).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const bytes = Buffer.alloc(8); bytes.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const mac = createHmac('sha1', key).update(bytes).digest();
  return String((mac.readUInt32BE(mac.at(-1) & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}
export async function actor(name) {
  const email = `${name}-${suffix}@example.test`;
  await registerAndVerify({ api: API, mailpit: env.MAILPIT_URL, email, password: env.E2E_PASSWORD });
  const login = await api('/api/control/auth/login', { method: 'POST', body: { email, password: env.E2E_PASSWORD } });
  const token = login.access_token;
  const me = await api('/api/control/me', { token });
  const setup = await api('/api/control/auth/mfa/totp/setup', { method: 'POST', body: {}, token, expected: 201 });
  const codes = await api('/api/control/auth/mfa/totp/confirm', { method: 'POST', token, body: { factor_id: setup.factor_id, code: totp(setup.secret) } });
  return { email, id: me.id, token, recovery: codes.recovery_codes };
}
export async function uiLogin(page, actor, operator = false) {
  await page.goto(APP + (operator ? '/operator' : '/login'));
  await page.locator(operator ? '#operator-email' : '#login-email').fill(actor.email);
  await page.locator(operator ? '#operator-password' : '#login-password').fill(env.E2E_PASSWORD);
  await page.locator(operator ? '.operator-console__login button[type=submit]' : 'form button[type=submit]').click();
  if (operator) {
    await page.locator('#operator-code').waitFor();
    await page.locator('#operator-recovery').check();
    await page.locator('#operator-code').fill(actor.recovery.shift());
    await page.locator('.operator-console__login button[type=submit]').click();
    await page.locator('.operator-console__tabs').waitFor();
  } else {
    await page.locator('#mfa-recovery').fill(actor.recovery.shift());
    await page.locator('form button[type=submit]').click();
    await page.waitForURL((url) => url.pathname === '/cabinet');
  }
}
