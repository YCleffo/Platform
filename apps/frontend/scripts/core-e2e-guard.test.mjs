import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { evaluateCoreMutationGate } from './core-e2e-guard.mjs';

const env = { E2E_RUNTIME: 'isolated-ci', E2E_ISOLATED_DB: '1', E2E_ALLOW_MUTATIONS: '1', E2E_PASSWORD: 'supplied-by-runner',
  E2E_ISOLATION_PROOF: 'a'.repeat(64), E2E_ALLOWED_HOSTS: '127.0.0.1', APP_URL: 'http://127.0.0.1:5176',
  CORE_API_URL: 'http://127.0.0.1:18084', MAILPIT_URL: 'http://127.0.0.1:18028' };
const proof = createHash('sha256').update(env.E2E_ISOLATION_PROOF).digest('hex');
const reply = (nonce = proof) => ({ status: 200, json: async () => ({ status: 'success', data: { nonce } }) });
for (const key of ['E2E_RUNTIME', 'E2E_ISOLATED_DB', 'E2E_ALLOW_MUTATIONS', 'E2E_PASSWORD', 'E2E_ISOLATION_PROOF', 'E2E_ALLOWED_HOSTS', 'APP_URL', 'CORE_API_URL', 'MAILPIT_URL']) {
  test(`refuses a missing ${key} before any HTTP request`, async () => {
    const result = await evaluateCoreMutationGate({ ...env, [key]: '' }, async () => { assert.fail('No HTTP request allowed'); });
    assert.equal(result.allowed, false);
  });
}
test('requires matching proof from both Core and the frontend proxy', async () => {
  const calls = [];
  const result = await evaluateCoreMutationGate(env, async url => { calls.push(url); return reply(); });
  assert.equal(result.allowed, true); assert.equal(calls.length, 2);
  assert.equal((await evaluateCoreMutationGate(env, async url => reply(url.includes(':5176') ? 'working-runtime' : proof))).allowed, false);
});
test('refuses working API responses, wrong nonce and unavailable proof', async () => {
  for (const nonce of ['', 'stale-nonce']) assert.equal((await evaluateCoreMutationGate(env, async () => reply(nonce))).allowed, false);
  assert.equal((await evaluateCoreMutationGate(env, async () => { throw new Error('offline'); })).allowed, false);
});
