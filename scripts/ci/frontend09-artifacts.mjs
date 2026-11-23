import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { groups } from './browser-verification-groups.mjs';

const capturesRoot = 'frontend09-final/authenticated';
const jobs = {
  frontend: { environment: 'production preview with isolated HTTP fixtures' },
  core: { environment: 'isolated Core and Education API', captures: { 'core-admin': 9, 'education-teacher': 15, 'education-parent': 1 }, results: ['core-auth-results.json', 'core-config-real-results.json', 'education-real/results.json'] },
  commercial: { environment: 'isolated Commercial API and synthetic payment transport', captures: { 'commercial-account': 4, 'commercial-operator': 2 }, results: ['frontend09-account-workspace-closeout.json', 'billing-real-results.json', 'billing-paid-results.json', 'operators-real-results.json'] },
};

export function runIdentity(env) {
  const { GITHUB_SHA: sourceSha, GITHUB_RUN_ID: runId, GITHUB_RUN_ATTEMPT: attempt } = env;
  assert.match(sourceSha ?? '', /^[0-9a-f]{40}$/);
  assert.match(runId ?? '', /^\d+$/);
  assert.match(attempt ?? '', /^\d+$/);
  return { sourceSha, runId, attempt };
}

export function sealEvidence(root, profile, provenance) {
  const job = jobs[profile];
  assert.ok(job, 'Unknown evidence profile');
  const artifacts = [];
  const versions = new Set();
  function collect(file) {
    const location = join(root, file);
    const stat = statSync(location);
    assert.ok(stat.isFile() && stat.size > 0, `Empty artifact: ${file}`);
    assert.ok(stat.mtimeMs >= provenance.startedAt, `Stale artifact: ${file}`);
    const bytes = readFileSync(location);
    const sha256 = createHash('sha256').update(bytes).digest('hex');
    artifacts.push({ file, sha256, size: bytes.length });
    return { bytes, sha256 };
  }
  const json = (file) => JSON.parse(collect(file).bytes.toString('utf8'));
  function screenshot(folder, file, expected) {
    assert.match(file, /^[a-z0-9-]+\.png$/);
    assert.equal(collect(`${folder}/${file}`).sha256, expected, `Screenshot hash mismatch: ${file}`);
  }
  if (profile === 'frontend') {
    const timings = json('verify-timings.json');
    assert.deepEqual(timings.results.map(({ script }) => script).sort(), groups.flatMap(({ scripts }) => scripts).sort(), 'Browser suite is incomplete');
    for (const result of timings.results) {
      assert.equal(result.exitCode, 0, result.script);
      assert.match(result.script, /^verify-[a-z0-9-]+\.mjs$/);
      collect(`verify-logs/${result.script.replace(/\.mjs$/, '.log')}`);
    }
    const routes = json('frontend09-route-audit.json');
    assert.ok(routes.routes.length >= 111 && routes.runtimeLinks.length >= 131);
    assert.ok(!routes.links.some(({ status }) => status === 'broken'));
    const navigation = json('frontend09-closeout-navigation/public-routes.json');
    assert.ok(navigation.rows.length > 0 && navigation.browserVersion);
    assert.equal(navigation.testedSha, provenance.sourceSha);
    assert.equal(navigation.sourceDirty, false);
    const visual = json('frontend09/visual-report.json');
    assert.ok(visual.captures.length >= 12 && visual.pageErrors.length === 0);
    versions.add(visual.browserVersion);
    for (const entry of visual.captures) {
      assert.deepEqual(entry.violations, []);
      screenshot('frontend09', entry.file, entry.sha256);
    }
  } else {
    for (const file of job.results) assert.equal(json(file).passed, true, file);
    for (const [suite, minimum] of Object.entries(job.captures)) {
      const folder = `${capturesRoot}/${suite}`;
      const records = readdirSync(join(root, folder)).filter((file) => file.endsWith('.json') && file !== 'navigation-metrics.json');
      assert.ok(records.length >= minimum, `Missing required captures: ${suite}`);
      for (const file of records.sort()) {
        const record = json(`${folder}/${file}`);
        assert.equal(record.suite, suite);
        assert.equal(record.status, 'PASS');
        assert.equal(record.overflow, false);
        assert.deepEqual(record.violations, []);
        assert.ok(Array.isArray(record.incomplete));
        assert.ok(record.browserVersion && record.route);
        versions.add(record.browserVersion);
        screenshot(folder, record.file, record.sha256);
      }
    }
  }
  return { ...provenance, status: 'PASS', exitCode: 0, profile, environment: job.environment, browserVersions: [...versions], artifacts };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const [, , action, profile, root = 'screenshots'] = process.argv;
  assert.ok(Object.hasOwn(jobs, profile));
  const identity = runIdentity(process.env);
  const stamp = join(root, `frontend09-${profile}-${identity.runId}-${identity.attempt}.start.json`);
  if (action === 'start') {
    mkdirSync(root, { recursive: true });
    writeFileSync(stamp, JSON.stringify({ ...identity, startedAt: Date.now() }), { flag: 'wx' });
  } else {
    assert.equal(action, 'seal');
    const provenance = JSON.parse(readFileSync(stamp, 'utf8'));
    for (const [key, value] of Object.entries(identity)) assert.equal(provenance[key], value);
    assert.ok(Number.isSafeInteger(provenance.startedAt) && provenance.startedAt > 0 && provenance.startedAt <= Date.now());
    const manifest = sealEvidence(root, profile, provenance);
    writeFileSync(join(root, `frontend09-${profile}-manifest.json`), JSON.stringify(manifest, null, 2) + '\n');
    console.log(`Verified ${manifest.artifacts.length} fresh artifacts for ${profile} (${identity.sourceSha}, run ${identity.runId}/${identity.attempt}).`);
  }
}
