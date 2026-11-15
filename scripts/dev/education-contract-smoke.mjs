import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(new URL('../../apps/frontend/package.json', import.meta.url));
const Ajv = require('ajv');
const document = JSON.parse(readFileSync(new URL('../../contracts/openapi/education.yaml', import.meta.url), 'utf8'));
const local = new URL('../../runtime/education-local/', import.meta.url);
const seed = JSON.parse(readFileSync(new URL('data/access.json', local), 'utf8'));
const settings = Object.fromEntries(readFileSync(new URL('local.env', local), 'utf8').trim().split(/\r?\n/).map(line => line.split(/=(.*)/s).slice(0, 2)));
const origin = 'http://127.0.0.1:8088';
const isolation = await (await fetch(origin + '/api/health/isolation')).json();
assert.equal(isolation.data?.nonce, createHash('sha256').update(settings.EDUCATION_LOCAL_PROOF).digest('hex'));
function convert(value) {
  if (Array.isArray(value)) return value.map(convert);
  if (!value || typeof value !== 'object') return value;
  const result = Object.fromEntries(Object.entries(value).map(([key, item]) => [key, convert(item)]));
  if (result.nullable && typeof result.type === 'string') result.type = [result.type, 'null'];
  delete result.nullable;
  return result;
}
function checkReferences(value) {
  if (!value || typeof value !== 'object') return;
  for (const [key, item] of Object.entries(value)) {
    if (key === '$ref' && item.startsWith('#/')) {
      let target = document;
      for (const part of item.slice(2).split('/')) target = target[part];
      assert.ok(target, 'Unresolved OpenAPI reference: ' + item);
    } else checkReferences(item);
  }
}
checkReferences(document);
const validator = new Ajv({ allErrors: true, schemaId: 'auto', unknownFormats: 'ignore', format: false, logger: false });
validator.addSchema(convert(document), 'education');
const login = await fetch(origin + '/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ identifier: seed.accounts.teacher.email, password: seed.accounts.teacher.password }) });
assert.equal(login.status, 200);
const token = (await login.json()).data.accessToken;
for (const [path, schema] of [
  ['/programs', 'EducationProgramPage'], [`/programs/${seed.curriculum.program.id}`, 'EducationProgram'],
  [`/groups/${seed.groups[0].id}/programs`, 'GroupProgramList'], [`/groups/${seed.groups[0].id}/progress`, 'CurriculumProgress'],
  [`/groups/${seed.groups[0].id}/teachers`, 'GroupTeachers'], [`/lessons/${seed.curriculum.scheduled[0].id}`, 'Lesson'],
]) {
  const response = await fetch(origin + '/api/education' + path, {
    headers: { Authorization: 'Bearer ' + token, 'X-Education-Organization': seed.organizationId }, cache: 'no-store' });
  assert.equal(response.status, 200); assert.match(response.headers.get('cache-control'), /no-store/);
  const validate = validator.compile({ $ref: 'education#/components/schemas/' + schema });
  assert.ok(validate((await response.json()).data), schema + ': ' + validator.errorsText(validate.errors));
}
console.log('PASS: OpenAPI references and six actual curriculum/lesson HTTP response schemas; no-store retained.');
