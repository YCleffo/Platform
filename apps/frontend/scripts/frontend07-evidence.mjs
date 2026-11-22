import { execFileSync, spawnSync } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluateCoreMutationGate } from './core-e2e-guard.mjs';

export const root = fileURLToPath(new URL('../../../', import.meta.url));
export const maxEvidenceAge = 7 * 24 * 60 * 60 * 1000;
const hashedRoots = ['apps', 'contracts', '.github/workflows', 'scripts', 'infra', 'deployment', 'compose*.yaml'];
const digestPattern = /^[0-9a-f]{64}$/;
const testRunner = 'apps/frontend/scripts/e2e-education-roles.mjs';
const supportedScenarios = new Map([
  ['teacher-enrollment-journal', 'teacher'],
  ['classroom-lifecycle', 'classroom'],
  ['storage-recovery', 'storage'],
]);

export function missingScenarios(evidence) {
  return [...supportedScenarios.keys()].filter((id) => !evidence.scenarios.some((scenario) => scenario.id === id));
}
const outcomes = ['userActionReachable', 'persistentResultVerified', 'securityBoundaryVerified'];
const sha256 = value => createHash('sha256').update(value).digest('hex');
const git = args => execFileSync('git', args, { cwd: root, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
const readJson = file => JSON.parse(readFileSync(file, 'utf8'));

export function revision() {
  const testedSha = git(['rev-parse', 'HEAD']).trim();
  const files = [...new Set(git(['ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', ...hashedRoots])
    .split('\0').filter(Boolean))].sort();
  const hash = createHash('sha256');
  for (const file of files) {
    hash.update(file).update('\0').update(existsSync(join(root, file)) ? sha256(readFileSync(join(root, file))) : 'deleted').update('\0');
  }
  return {
    testedSha,
    sourceDigest: hash.digest('hex'),
    sourceDirty: git(['status', '--porcelain', '--untracked-files=all', '--', ...hashedRoots]).length > 0,
  };
}

export function readEvidence(path, expected, now = Date.now()) {
  const errors = [];
  const evidence = readJson(path);
  if (evidence.version !== 1 || typeof evidence.runId !== 'string' || !evidence.runId.trim()) errors.push('Invalid manifest version or run identifier.');
  for (const field of ['testedSha', 'sourceDigest', 'sourceDirty']) {
    if (evidence[field] !== expected[field]) errors.push(`Evidence ${field} differs from the current source revision.`);
  }
  if (evidence.environment?.kind !== 'isolated-ci' || !digestPattern.test(evidence.environment?.proofSha256 ?? '')) {
    errors.push('Missing isolated-runtime provenance.');
  }
  const seen = new Set();
  if (!Array.isArray(evidence.scenarios) || evidence.scenarios.length === 0) errors.push('Missing scenario records.');
  for (const scenario of Array.isArray(evidence.scenarios) ? evidence.scenarios : []) {
    const timestamp = Date.parse(scenario.timestamp);
    if (!Number.isFinite(timestamp) || timestamp > now || now - timestamp > maxEvidenceAge) errors.push(`Invalid or stale timestamp for ${scenario.id}.`);
    if (seen.has(scenario.id) || !supportedScenarios.has(scenario.id) || scenario.testFile !== testRunner) {
      errors.push(`Unexpected or duplicate scenario ${scenario.id}.`);
    }
    seen.add(scenario.id);
    if (!['PASS', 'FAIL'].includes(scenario.status) || outcomes.some(field => scenario[field] !== (scenario.status === 'PASS'))) {
      errors.push(`Unexpected outcome fields for ${scenario.id}.`);
    }
    if (typeof scenario.artifact !== 'string' || basename(scenario.artifact) !== scenario.artifact || !scenario.artifact.endsWith('.json')) {
      errors.push(`Invalid artifact path for ${scenario.id}.`);
      continue;
    }
    const artifactPath = join(dirname(path), scenario.artifact);
    if (!existsSync(artifactPath)) {
      errors.push(`Missing artifact for ${scenario.id}.`);
      continue;
    }
    const artifact = readFileSync(artifactPath, 'utf8');
    if (sha256(artifact) !== scenario.artifactSha256) errors.push(`Artifact digest mismatch for ${scenario.id}.`);
    const expectedArtifact = {
      runId: evidence.runId, testedSha: evidence.testedSha, sourceDigest: evidence.sourceDigest,
      timestamp: scenario.timestamp, proofSha256: evidence.environment.proofSha256,
      id: scenario.id, testFile: scenario.testFile, status: scenario.status, testArgument: supportedScenarios.get(scenario.id),
    };
    const recorded = JSON.parse(artifact);
    if (Object.entries(expectedArtifact).some(([field, value]) => recorded[field] !== value) ||
      (recorded.exitCode === 0) !== (scenario.status === 'PASS')) {
      errors.push(`Artifact does not match the manifest for ${scenario.id}.`);
    }
  }
  if (errors.length) throw new Error(errors.join('\n'));
  return evidence;
}

export function readEvidenceSet(paths, expected, now = Date.now()) {
  if (!paths.length) throw new Error('Provide at least one browser evidence manifest.');
  const manifests = paths.map((path) => readEvidence(path, expected, now));
  const { environment } = manifests[0];
  if (manifests.some((manifest) => manifest.environment.proofSha256 !== environment.proofSha256)) {
    throw new Error('The evidence manifests belong to different test runtimes.');
  }
  const scenarios = manifests.flatMap(({ runId, scenarios: records }) => records.map((record) => ({ ...record, runId })));
  if (new Set(scenarios.map(({ id }) => id)).size !== scenarios.length) {
    throw new Error('A browser scenario appears in more than one evidence manifest.');
  }
  return { ...expected, environment, runIds: manifests.map(({ runId }) => runId), scenarios };
}

export async function runEvidence(manifestPath, id, testFile, args) {
  const normalized = relative(root, resolve(testFile)).replaceAll('\\', '/');
  if (normalized !== testRunner || args.length !== 1 || supportedScenarios.get(id) !== args[0]) {
    throw new Error('The browser command must match a registered scenario.');
  }
  const { request } = await import('playwright');
  const probe = await request.newContext({ ignoreHTTPSErrors: true });
  try {
    const gate = await evaluateCoreMutationGate(process.env, async url => {
      const reply = await probe.get(url, { timeout: 15000 });
      return { status: reply.status(), json: () => reply.json() };
    });
    if (!gate.allowed) throw new Error(gate.reasons.join('\n'));
  } finally {
    await probe.dispose();
  }
  const before = revision();
  const proofSha256 = sha256(process.env.E2E_ISOLATION_PROOF);
  const runId = process.env.GITHUB_RUN_ID && process.env.GITHUB_RUN_ATTEMPT
    ? `github-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}` : `local-${randomUUID()}`;
  const manifest = existsSync(manifestPath) ? readEvidence(manifestPath, before) : {
    version: 1, runId, ...before, environment: { kind: 'isolated-ci', proofSha256 }, scenarios: [],
  };
  if (manifest.environment.proofSha256 !== proofSha256 || (process.env.GITHUB_RUN_ID && manifest.runId !== runId)) {
    throw new Error('The existing manifest belongs to a different test run.');
  }
  const run = spawnSync(process.execPath, [join(root, normalized), ...args], { cwd: join(root, 'apps/frontend'), stdio: 'inherit' });
  if (run.error) throw run.error;
  const after = revision();
  if (Object.keys(before).some(field => before[field] !== after[field])) throw new Error('Source changed during browser verification.');
  const timestamp = new Date().toISOString();
  const status = run.status === 0 ? 'PASS' : 'FAIL';
  const artifact = `${id}-${randomUUID()}.json`;
  const attestation = JSON.stringify({
    runId: manifest.runId, testedSha: before.testedSha, sourceDigest: before.sourceDigest,
    timestamp, proofSha256, id, testFile: normalized, status, testArgument: args[0], exitCode: run.status,
  }, null, 2) + '\n';
  mkdirSync(dirname(manifestPath), { recursive: true });
  writeFileSync(join(dirname(manifestPath), artifact), attestation, { flag: 'wx' });
  const scenario = { id, testFile: normalized, status, timestamp, artifact, artifactSha256: sha256(attestation) };
  for (const field of outcomes) scenario[field] = status === 'PASS';
  manifest.scenarios = [...manifest.scenarios.filter(item => item.id !== id), scenario];
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
  return status === 'PASS' ? 0 : 1;
}
