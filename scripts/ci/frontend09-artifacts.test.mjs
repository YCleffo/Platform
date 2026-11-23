import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, realpathSync, rmSync, utimesSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { groups } from './browser-verification-groups.mjs';
import { runIdentity, sealEvidence } from './frontend09-artifacts.mjs';

test('requires matching source and fresh, verified browser evidence', () => {
  const temp = realpathSync(tmpdir());
  const directory = mkdtempSync(join(temp, 'platform-ci-evidence-'));
  const sourceSha = 'a'.repeat(40);
  const provenance = { ...runIdentity({ GITHUB_SHA: sourceSha, GITHUB_RUN_ID: '123', GITHUB_RUN_ATTEMPT: '2' }), startedAt: Date.now() - 1000 };
  const write = (name, content) => {
    const path = join(directory, name);
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, typeof content === 'string' ? content : JSON.stringify(content));
  };
  try {
    assert.throws(() => sealEvidence(directory, 'frontend', provenance), { code: 'ENOENT' });
    const results = groups.flatMap(({ scripts }) => scripts.map((script) => ({ script, exitCode: 0 })));
    for (const { script } of results) write(`verify-logs/${script.replace(/\.mjs$/, '.log')}`, 'fixture');
    write('verify-timings.json', { results });
    write('frontend09-route-audit.json', { routes: new Array(111).fill({}), links: [], runtimeLinks: new Array(133).fill({}) });
    const navigation = { testedSha: sourceSha, sourceDirty: false, browserVersion: 'fixture-browser', rows: [{}] };
    write('frontend09-closeout-navigation/public-routes.json', { ...navigation, testedSha: 'b'.repeat(40) });
    assert.throws(() => sealEvidence(directory, 'frontend', provenance), /Expected values to be strictly equal/);
    write('frontend09-closeout-navigation/public-routes.json', navigation);
    const bytes = 'fixture image bytes';
    const sha256 = createHash('sha256').update(bytes).digest('hex');
    const captures = Array.from({ length: 12 }, (_, index) => ({ file: `fixture-${index}.png`, sha256, violations: [] }));
    write('frontend09/visual-report.json', { browserVersion: 'fixture-browser', captures, pageErrors: [] });
    for (const capture of captures) write(`frontend09/${capture.file}`, bytes);
    const log = join(directory, 'verify-logs', results[0].script.replace(/\.mjs$/, '.log'));
    utimesSync(log, new Date(1000), new Date(1000));
    assert.throws(() => sealEvidence(directory, 'frontend', provenance), /Stale artifact/);
    write(`verify-logs/${results[0].script.replace(/\.mjs$/, '.log')}`, 'fresh fixture');
    write('frontend09/fixture-0.png', 'changed');
    assert.throws(() => sealEvidence(directory, 'frontend', provenance), /Screenshot hash mismatch/);
    write('frontend09/fixture-0.png', bytes);
    const manifest = sealEvidence(directory, 'frontend', provenance);
    assert.equal(manifest.status, 'PASS');
    assert.equal(manifest.sourceSha, sourceSha);
    assert.equal(manifest.runId, '123');
    assert.equal(manifest.attempt, '2');
    assert.equal(manifest.artifacts.length, results.length + 16);
  } finally {
    assert.equal(dirname(realpathSync(directory)), temp);
    rmSync(directory, { recursive: true });
  }
});
