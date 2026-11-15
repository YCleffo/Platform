import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(new URL('../../../apps/frontend/package.json', import.meta.url));
const Ajv = require('ajv');
const document = JSON.parse(readFileSync(new URL('../../../contracts/openapi/education-parent.yaml', import.meta.url), 'utf8'));
const runtime = new URL('../../../runtime/', import.meta.url);
const fixture = JSON.parse(readFileSync(new URL('education-local/data/access.json', runtime), 'utf8'));
const parent = JSON.parse(readFileSync(new URL('education05/local-parent-access.json', runtime), 'utf8'));
const settings = Object.fromEntries(readFileSync(new URL('education-local/local.env', runtime), 'utf8').trim().split(/\r?\n/).map(line => line.split(/=(.*)/s).slice(0, 2)));
const origin = 'http://127.0.0.1:8088';
const health = await (await fetch(origin + '/api/health/isolation')).json();
assert.equal(health.data?.nonce, createHash('sha256').update(settings.EDUCATION_LOCAL_PROOF).digest('hex'), 'Only the isolated fixture server is allowed.');

function convert(value) {
  if (Array.isArray(value)) return value.map(convert);
  if (!value || typeof value !== 'object') return value;
  const result = Object.fromEntries(Object.entries(value).map(([key, item]) => [key, convert(item)]));
  if (result.nullable && typeof result.type === 'string') result.type = [result.type, 'null'];
  delete result.nullable;
  return result;
}
const ajv = new Ajv({ allErrors: true, schemaId: 'auto', unknownFormats: 'ignore', format: false, logger: false });
ajv.addSchema(convert(document), 'education-parent');
async function login(account) {
  const response = await fetch(origin + '/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identifierType: 'email', identifier: account.email, password: account.password }) });
  assert.equal(response.status, 200, 'Core login must succeed');
  return (await response.json()).data.accessToken;
}
const parentToken = await login(parent);
const teacherToken = await login(fixture.accounts.teacher);
const evidence = [];
async function check(path, contractPath, token, org = fixture.organizationId) {
  const response = await fetch(origin + '/api/education' + path, { headers: { Authorization: 'Bearer ' + token, 'X-Education-Organization': org }, cache: 'no-store' });
  assert.equal(response.status, 200, contractPath);
  assert.match(response.headers.get('cache-control') ?? '', /no-store/);
  const body = await response.json();
  const schema = convert(document.paths[contractPath].get.responses['200'].content['application/json'].schema);
  const validate = ajv.compile({ ...schema, components: convert(document.components) });
  assert.ok(validate(body), contractPath + ': ' + ajv.errorsText(validate.errors));
  evidence.push(contractPath);
  return body.data;
}
const context = await check('/parent/context', '/parent/context', parentToken);
assert.ok(context.children.length > 0, 'Keep the sibling relation active after the revocation smoke.');
const child = context.children[0];
const base = '/parent/children/' + child.id;
const template = '/parent/children/{studentId}';
await check(base, template, parentToken, child.organizationId);
for (const section of ['home', 'schedule', 'diary', 'homework', 'progress', 'notifications']) {
  await check(base + '/' + section, template + '/' + section, parentToken, child.organizationId);
}
await check('/family/candidates?q=QA', '/family/candidates', teacherToken);
await check('/students/' + child.id + '/guardians', '/students/{studentId}/guardians', teacherToken, child.organizationId);
await check('/students/' + child.id + '/guardians/history', '/students/{studentId}/guardians/history', teacherToken, child.organizationId);
writeFileSync(new URL('education05/parent-contract-evidence.json', runtime), JSON.stringify({ checked: evidence }, null, 2));
console.log(`CONTRACT PASS: ${evidence.length} real HTTP response envelopes and family DTOs, private cache headers.`);
