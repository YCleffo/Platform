import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { missingScenarios, readEvidenceSet, revision, root, runEvidence } from './frontend07-evidence.mjs';

const coverageKeys = ['FULL', 'PARTIAL', 'MISSING', 'NOT_UI_REQUIRED', 'ALTERNATIVE_VERIFIED', 'INTERNAL', 'DEPRECATED'];

function snapshot(options) {
  const staticOutput = join(root, 'docs/audits/frontend07-coverage.json');
  const check = options.length === 1 && options[0] === '--check';
  const withEvidence = options.length >= 4 && options[0] === '--evidence' && options.at(-2) === '--output';
  if (options.length && !check && !withEvidence) throw new Error('Use --check or --evidence <manifest>... --output <snapshot>.');
  const output = withEvidence ? resolve(options.at(-1)) : staticOutput;
  if (withEvidence && output === staticOutput) throw new Error('Use a separate output for revision-bound evidence.');
  const evidence = withEvidence ? readEvidenceSet(options.slice(1, -2).map((path) => resolve(path)), revision()) : null;
  buildSnapshot(output, check, evidence);
}

const scenariosForEndpoint = new Map([
  ['teacher-enrollment-journal', [
    'POST /api/education/students/new',
    'PUT /api/education/lessons/{}/results/{}',
    'GET /api/education/groups/{}/journal',
    'GET /api/education/students/{}',
  ]],
  ['classroom-lifecycle', [
    'GET /api/device-management/devices/{}',
    'POST /api/device-management/devices/{}/session',
    'POST /api/device-management/devices/{}/session/end',
    'POST /api/device-management/devices/{}/recovery/retry',
  ]],
  ['storage-recovery', [
    'GET /api/storage/files',
    'POST /api/storage/files/restore',
    'POST /api/storage/transfers',
    'POST /api/storage/transfers/{}/complete',
  ]],
]);
const alternativeEndpoints = new Map([
  ['GET /api/education/curriculum-modules/{}', 'GET /api/education/programs/{}'],
  ['GET /api/education/curriculum-lessons/{}', 'GET /api/education/programs/{}'],
  ['GET /api/education/parent/children/{}', 'GET /api/education/parent/context'],
  ['GET /api/education/files/{}', 'GET /api/education/files/{}/transfer'],
  ['POST /api/education/files', 'POST /api/education/file-transfers'],
  ['GET /api/education/homework/files/{}', 'GET /api/education/homework/files/{}/transfer'],
  ['POST /api/education/homework/{}/files', 'POST /api/education/homework/{}/file-transfers'],
  ['POST /api/education/student/homework/{}/files', 'POST /api/education/student/homework/{}/file-transfers'],
  ['GET /api/storage/file', 'POST /api/storage/transfers'],
  ['PUT /api/storage/files', 'POST /api/storage/transfers/{}/complete'],
]);

function operationKey(endpoint) {
  return endpoint.replace(/\{[^{}]+\}/g, '{}');
}

function internalAccess(endpoint) {
  const path = endpoint.split(' ')[1];
  if (/(?:\/api)?\/health\/isolation$/.test(path)) {
    return 'Test-only nonce hash. Core requires test environment, isolated runtime and disposable PostgreSQL; Commercial requires test environment and isolated control plane.';
  }
  if (path === '/api/device-agent/enroll') return 'Provisioning channel requires a valid enrollment code before issuing a device credential.';
  if (path.startsWith('/api/device-agent/')) return 'Machine channel requires device authentication.';
  if (path.startsWith('/api/m2m/')) return 'Service authentication or signed entitlement is enforced in the handler.';
  if (path.startsWith('/api/control/webhooks/')) return 'Provider event is checked at the server-side gateway and reconciled against authoritative provider state.';
  if (path === '/metrics') return 'Operations metrics. Application handler checks configured network restrictions; audit deployment exposure separately.';
  return 'Service identification or readiness probe. Check exposure against ingress policy; no user action is expected.';
}

function priority(row, endpoint) {
  if (['INTERNAL', 'DEPRECATED', 'NOT_UI_REQUIRED', 'ALTERNATIVE_VERIFIED'].includes(row.coverage)) {
    return { level: 'P2', reason: 'Service surface, optional alternate transport or documented retirement.' };
  }
  const required = [...scenariosForEndpoint.values()].some(operations => operations.includes(endpoint));
  if (required || /\/auth\/(?:login|refresh|logout|mfa\/verify)$/.test(endpoint) ||
      /\/api\/storage\/(?:transfers|conflicts|tickets)/.test(endpoint) ||
      /\/education\/(?:parent\/activate|student-activate|student\/home|homework\/[^ ]+\/publish)$/.test(endpoint)) {
    return { level: 'P0', reason: 'Required workflow transition, identity boundary or risk of loss of confirmed or pending data.' };
  }
  if (row.coverage === 'FULL') return { level: 'P1', reason: 'Confirmed user path with no current release-blocking gap.' };
  return { level: 'P1', reason: 'Existing supporting workflow. Prefer role-level verification before expanding UI.' };
}

function coreCategory(row) {
  if (row.coverage === 'DEPRECATED') return 'obsolete';
  if (row.coverage === 'INTERNAL') return 'service';
  if (row.audience === 'Admin') return 'administrative';
  return row.permission.context.length ? 'account' : 'public';
}

function currentOperation(row, previous, findings) {
  const endpoint = operationKey(row.endpoint);
  const available = [...scenariosForEndpoint].filter(([, endpoints]) => endpoints.includes(endpoint)).map(([id]) => id);
  const evidence = findings.filter(finding => available.includes(finding.id));
  const passed = evidence.find(finding => finding.status === 'PASS');
  const alternate = alternativeEndpoints.get(endpoint);
  const coverage = row.coverage === 'INTERNAL' || row.coverage === 'DEPRECATED' ? row.coverage
    : row.ui.length && passed ? 'FULL'
      : alternate && !row.frontendApi.length ? 'NOT_UI_REQUIRED'
        : row.frontendApi.length ? 'PARTIAL' : 'MISSING';
  const stage = finding => finding ? 'VERIFIED' : evidence.some(item => item.status === 'FAIL') ? 'FAILED_RUN' : 'NOT_VERIFIED';
  const proofs = {
    backendRouteExists: true,
    contractDocumented: row.contracts.length > 0,
    clientCallExists: row.frontendApi.length > 0,
    routedUiConsumerExists: row.ui.length > 0,
    userActionReachable: stage(passed?.userActionReachable),
    realBrowserE2ePassed: stage(passed),
    persistentBackendResultVerified: stage(passed?.persistentResultVerified),
    securityBoundaryVerified: stage(passed?.securityBoundaryVerified),
  };
  return {
    ...row,
    coverage,
    previousCoverage: previous?.coverage ?? null,
    priority: priority({ ...row, coverage }, endpoint),
    reason: coverage === 'FULL' ? 'Fresh isolated browser run with result and scope assertions.'
      : coverage === 'PARTIAL' ? 'Static client inventory. A fresh run with the required assertions has not confirmed this operation.'
        : row.reason,
    evidenceStages: proofs,
    test: row.test.map(test => test.type === 'Browser E2E'
      ? { type: 'Historical scenario reference', file: test.file, scope: test.scope }
      : test),
    expectedScenarios: available,
    evidence: evidence.map(({ id, runId, timestamp, artifact, status }) => ({ id, runId, timestamp, artifact, status })),
    ...(alternate && { alternative: { endpoint: alternate, rationale: row.reason } }),
    ...(coverage === 'INTERNAL' && { internalAccess: internalAccess(endpoint) }),
  };
}

function buildSnapshot(output, check, evidence) {
  const readJson = file => JSON.parse(readFileSync(file, 'utf8'));
  const live = JSON.parse(execFileSync(process.execPath, [
    join(root, 'apps/frontend/scripts/frontend06-coverage.mjs'), '--emit-static',
  ], { cwd: root, encoding: 'utf8', maxBuffer: 12 * 1024 * 1024 }));
  const baseline = readJson(join(root, 'docs/audits/frontend06-coverage.json'));
  const key = row => `${row.plane} ${row.endpoint}`;
  const old = new Map(baseline.operations.map(row => [key(row), row]));
  const operations = live.operations.map(row => currentOperation(row, old.get(key(row)), evidence?.scenarios ?? []));
  const provedRoutes = new Set(operations.filter(row => row.coverage === 'FULL').map(row => operationKey(row.endpoint)));
  for (const row of operations) {
    if (row.coverage === 'NOT_UI_REQUIRED' && provedRoutes.has(row.alternative.endpoint)) row.coverage = 'ALTERNATIVE_VERIFIED';
  }
  const totals = {};
  for (const row of operations) {
    totals[row.plane] ??= Object.fromEntries([...coverageKeys, 'withoutContract'].map(label => [label, 0]));
    totals[row.plane][row.coverage]++;
    if (!row.contracts.length) totals[row.plane].withoutContract++;
  }
  const undocumentedCore = operations.filter(row => row.plane === 'Core' && !row.contracts.length)
    .map(row => ({
      endpoint: row.endpoint, category: coreCategory(row), permission: row.permission,
      source: row.backend.source, action: row.backend.action,
    }));
  const result = {
    version: 1,
    baseline: { file: 'docs/audits/frontend06-coverage.json', totals: baseline.totals },
    documents: live.documents, totals, contractOnly: live.contractOnly,
    static: {
      registered: operations.length,
      hasContract: operations.filter(row => row.contracts.length).length,
      hasClient: operations.filter(row => row.frontendApi.length).length,
      hasRoutedConsumer: operations.filter(row => row.ui.length).length,
    },
    provenance: evidence ? {
      runIds: evidence.runIds, testedSha: evidence.testedSha, sourceDigest: evidence.sourceDigest,
      sourceDirty: evidence.sourceDirty, environment: evidence.environment,
    } : null,
    undocumentedCore,
    operations,
  };
  const serialized = JSON.stringify(result, null, 2) + '\n';
  if (check) {
    if (readFileSync(output, 'utf8').replaceAll('\r\n', '\n') !== serialized) throw new Error('FRONTEND_07 static inventory drifted.');
  } else {
    writeFileSync(output, serialized);
  }
  console.log(JSON.stringify({ totals, static: result.static, undocumentedCore: undocumentedCore.length }, null, 2));
}

const args = process.argv.slice(2);
if (args[0] === '--run-evidence') {
  if (args.length < 5) throw new Error('Provide the manifest, scenario, test file and role.');
  const [, manifest, scenario, testFile, ...testArguments] = args;
  process.exitCode = await runEvidence(resolve(manifest), scenario, testFile, testArguments);
} else if (args[0] === '--check-evidence') {
  const evidence = readEvidenceSet(args.slice(1).map((path) => resolve(path)), revision());
  console.log(`Verified evidence for ${evidence.testedSha}: ${evidence.scenarios.map(item => `${item.id}=${item.status}`).join(', ')}`);
  const missing = missingScenarios(evidence);
  if (missing.length) console.error(`Missing release scenarios: ${missing.join(', ')}`);
  if (missing.length || evidence.scenarios.some(item => item.status !== 'PASS')) process.exitCode = 1;
} else {
  snapshot(args);
}
