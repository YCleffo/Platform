import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const directory = process.argv[2];
assert.ok(directory, 'Pass the isolated EDUCATION_06 runtime directory.');
const runtime = pathToFileURL(resolve(directory) + '/');
const require = createRequire(new URL('../../../apps/frontend/package.json', import.meta.url));
const Ajv = require('ajv');
const documents = Object.fromEntries(['education-activities', 'education-parent', 'homework'].map(name => [name,
  JSON.parse(readFileSync(new URL(`../../../contracts/openapi/${name}.yaml`, import.meta.url), 'utf8'))]));
const fixture = JSON.parse(readFileSync(new URL('data/access.json', runtime), 'utf8'));
const demo = JSON.parse(readFileSync(new URL('data/education04-demo.json', runtime), 'utf8'));
const parent = JSON.parse(readFileSync(new URL('data/education06-parent-access.json', runtime), 'utf8'));
const settings = Object.fromEntries(readFileSync(new URL('local.env', runtime), 'utf8').trim().split(/\r?\n/).map(line => line.split(/=(.*)/s).slice(0, 2)));
const origin = 'http://127.0.0.1:8088';
const isolation = await (await fetch(origin + '/api/health/isolation')).json();
assert.equal(isolation.data?.nonce, createHash('sha256').update(settings.EDUCATION_LOCAL_PROOF).digest('hex'));
function convert(value) {
  if (Array.isArray(value)) return value.map(convert);
  if (!value || typeof value !== 'object') return value;
  const out = Object.fromEntries(Object.entries(value).map(([k, v]) => [k, convert(v)]));
  if (out.nullable && typeof out.type === 'string') out.type = [out.type, 'null'];
  delete out.nullable;
  return out;
}
const ajv = new Ajv({ allErrors: true, unknownFormats: 'ignore', format: false, logger: false });
for (const [name, doc] of Object.entries(documents)) ajv.addSchema(convert(doc), name);
const checked = [];
async function login(account) {
  const response = await fetch(origin + '/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ identifierType: 'email', identifier: account.email, password: account.password }) });
  assert.equal(response.status, 200);
  return (await response.json()).data.accessToken;
}
const student = await login(demo.studentAccounts[0]);
const teacher = await login(fixture.accounts.teacher);
const guardian = await login(parent);
async function check(path, template, token, method = 'get', input, name = 'education-activities') {
  const response = await fetch(origin + '/api/education' + path, { method: method.toUpperCase(),
    headers: { Authorization: `Bearer ${token}`, 'X-Education-Organization': fixture.organizationId, 'Content-Type': 'application/json' },
    ...(input === undefined ? {} : { body: JSON.stringify(input) }) });
  assert.ok(response.ok, `${method} ${template} ${response.status}`);
  assert.match(response.headers.get('cache-control') ?? '', /no-store/);
  const body = await response.json();
  const doc = documents[name];
  const schema = doc.paths[template][method].responses[String(response.status)].content['application/json'].schema;
  const validate = ajv.compile({ ...convert(schema), components: convert(doc.components) });
  assert.ok(validate(body), `${method} ${template}: ${JSON.stringify(validate.errors?.slice(0, 8))}`);
  checked.push(`${name} ${method} ${template}`);
  return body.data;
}
await check('/activities', '/activities', teacher);
await check('/student/activities', '/student/activities', student);
const typing = await check('/student/activities/platform-typing/attempts', '/student/activities/{code}/attempts', student, 'post', { language: 'ru', durationSeconds: 15, mode: 'accuracy', difficulty: 2 });
await check(`/student/attempts/${typing.attempt.id}/finish`, '/student/attempts/{id}/finish', student, 'put', { typed: typing.exercise.text.slice(0, 25), durationSeconds: 15 });
const logic = await check('/student/activities/platform-logic/attempts', '/student/activities/{code}/attempts', student, 'post', { language: 'en', difficulty: 3, roundCount: 5 });
const answers = logic.exercise.rounds.map(r => ({ roundId: r.id, optionId: r.options[0].id }));
await check(`/student/attempts/${logic.attempt.id}/state`, '/student/attempts/{id}/state', student, 'put', { version: 0, state: { answers: answers.slice(0, 1), currentRound: 1 } });
await check(`/student/attempts/${logic.attempt.id}`, '/student/attempts/{id}', student);
await check(`/student/attempts/${logic.attempt.id}/finish`, '/student/attempts/{id}/finish', student, 'put', { answers });
await check(`/student/attempts/${logic.attempt.id}`, '/student/attempts/{id}', student);
await check('/student/achievements', '/student/achievements', student);
await check('/student/activity-progress', '/student/activity-progress', student);
await check(`/groups/${fixture.groups[0].id}/activity-progress`, '/groups/{id}/activity-progress', teacher);
await check(`/parent/children/${demo.students[0].id}/progress`, '/parent/children/{studentId}/progress', guardian, 'get', undefined, 'education-parent');
const homework = await check('/student/homework', '/student/homework', student, 'get', undefined, 'homework');
const activity = homework.items.find(row => row.activityCode === 'platform-logic');
assert.ok(activity);
await check(`/student/homework/${activity.id}`, '/student/homework/{id}', student, 'get', undefined, 'homework');
writeFileSync(new URL('activity-contract-evidence.json', runtime), JSON.stringify({ checked }, null, 2));
console.log(`CONTRACT PASS: ${checked.length} real HTTP response schemas across Activities, Homework and ParentScope.`);
