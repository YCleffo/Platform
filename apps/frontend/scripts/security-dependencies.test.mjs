import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const require = createRequire(import.meta.url);
const baseline = process.env.DEPENDENCY_TEST_BASELINE
  ? createRequire(join(process.env.DEPENDENCY_TEST_BASELINE, 'package.json')) : null;
const parent = process.env.DEPENDENCY_TEST_TMPDIR ?? tmpdir();
function child(source, args = []) {
  const result = spawnSync(process.execPath, ['--max-old-space-size=128', '-e', source, ...args], {
    timeout: 5000, encoding: 'utf8', windowsHide: true,
  });
  assert.equal(result.error, undefined, 'Dependency must complete within the bounded child process');
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('all affected consumers bound amplified glob patterns; legacy ESLint matching remains compatible', () => {
  for (const owner of ['glob', '@vue/language-core', '@typescript-eslint/typescript-estree', 'editorconfig']) {
    const ownerPath = require.resolve(owner);
    const matcher = baseline?.resolve('minimatch') ?? require.resolve('minimatch', { paths: [dirname(ownerPath)] });
    const value = child(`
      const { Minimatch, minimatch } = require(process.argv[1]);
      let bounded = false;
      try { const pattern = Array(24).fill('**/../a/b').join('/'); const result = new Minimatch(pattern, { optimizationLevel: 2, braceExpandMax: 64 }); bounded = result.set.length <= 64; }
      catch (error) { if (!(error instanceof TypeError || error instanceof RangeError)) throw error; bounded = true; }
      const alternateBounded = new Minimatch('{a,b}'.repeat(20), { braceExpandMax: 64 }).set.length <= 64;
      console.log(JSON.stringify({ bounded, alternateBounded, valid: minimatch('src/views/Page.vue', 'src/**/*.vue'), invalid: minimatch('src/views/Page.ts', 'src/**/*.vue') }));
    `, [matcher]);
    assert.deepEqual(value, { bounded: true, alternateBounded: true, valid: true, invalid: false }, owner);
  }
  const legacy = require('minimatch');
  assert.equal(typeof legacy, 'function');
  assert.equal(legacy('src/app.ts', '**/*.ts'), true);
});

test('glob abort stops subsequent directory reads and normal traversal still returns files', () => {
  const directory = mkdtempSync(join(parent, 'platform-glob-'));
  try {
    let paths = [directory];
    for (let depth = 0; depth < 4; depth++) {
      const next = [];
      for (const path of paths) for (const name of ['a', 'b']) {
        const target = join(path, name); mkdirSync(target); writeFileSync(join(target, 'entry.txt'), 'fixture'); next.push(target);
      }
      paths = next;
    }
    const value = child(`
      const fs = require('node:fs'); const controller = new AbortController(); let late = 0; let calls = 0;
      const abort = () => { if (!controller.signal.aborted) controller.abort(new Error('Fixture stop')); };
      const custom = { ...fs, readdir(path, options, callback) { calls++; if (controller.signal.aborted) late++; fs.readdir(path, options, (error, result) => { abort(); callback(error, result); }); },
        promises: { ...fs.promises, async readdir(...args) { calls++; if (controller.signal.aborted) late++; const result = await fs.promises.readdir(...args); abort(); return result; } } };
      const { glob } = require(process.argv[1]);
      (async () => { let rejected = false; try { await glob('**/*', { cwd: process.argv[2], fs: custom, signal: controller.signal }); } catch { rejected = true; }
        await new Promise(resolve => setTimeout(resolve, 100));
        const files = await glob('**/*.txt', { cwd: process.argv[2] });
        console.log(JSON.stringify({ rejected, calls, late, files: files.length }));
      })().catch(error => { console.error(error); process.exit(1); });
    `, [baseline?.resolve('glob') ?? require.resolve('glob'), directory]);
    assert.equal(value.rejected, true); assert.ok(value.calls > 0); assert.equal(value.late, 0);
    assert.equal(value.files, 30);
  } finally { rmSync(directory, { recursive: true, force: true }); }
});
