import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, unlinkSync, rmdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { maxEvidenceAge, missingScenarios, readEvidence, readEvidenceSet } from './frontend07-evidence.mjs';

test('coverage verifies exact, fresh browser artifacts and identifies incomplete runs', () => {
  const directory = mkdtempSync(join(tmpdir(), 'platform-frontend07-test-'));
  const manifestPath = join(directory, 'manifest.json');
  const artifact = 'teacher.json';
  const artifactPath = join(directory, artifact);
  const now = Date.now();
  const revision = { testedSha: 'a'.repeat(40), sourceDigest: 'b'.repeat(64), sourceDirty: true };
  const runId = 'test-run';
  const proofSha256 = 'c'.repeat(64);
  const timestamp = new Date(now).toISOString();
  const id = 'teacher-enrollment-journal';
  const testFile = 'apps/frontend/scripts/e2e-education-roles.mjs';
  const status = 'PASS';
  const attestation = JSON.stringify({
    runId, testedSha: revision.testedSha, sourceDigest: revision.sourceDigest, timestamp,
    proofSha256, id, testFile, status, testArgument: 'teacher', exitCode: 0,
  });
  try {
    writeFileSync(artifactPath, attestation);
    writeFileSync(manifestPath, JSON.stringify({
      version: 1, runId, ...revision, environment: { kind: 'isolated-ci', proofSha256 },
      scenarios: [{
        id, testFile, status, timestamp, artifact,
        artifactSha256: createHash('sha256').update(attestation).digest('hex'),
        userActionReachable: true, persistentResultVerified: true, securityBoundaryVerified: true,
      }],
    }));

    assert.deepEqual(missingScenarios(readEvidence(manifestPath, revision, now)), ['classroom-lifecycle', 'storage-recovery']);
    assert.deepEqual(readEvidenceSet([manifestPath], revision, now).runIds, [runId]);
    assert.throws(() => readEvidenceSet([manifestPath, manifestPath], revision, now), /more than one/);
    assert.throws(() => readEvidence(manifestPath, { ...revision, sourceDigest: 'd'.repeat(64) }, now), /sourceDigest differs/);
    assert.throws(() => readEvidence(manifestPath, revision, now + maxEvidenceAge + 1), /stale timestamp/);

    writeFileSync(artifactPath, JSON.stringify({ ...JSON.parse(attestation), exitCode: 1 }));
    assert.throws(() => readEvidence(manifestPath, revision, now), /Artifact digest mismatch/);
  } finally {
    unlinkSync(artifactPath);
    unlinkSync(manifestPath);
    rmdirSync(directory);
  }
});
