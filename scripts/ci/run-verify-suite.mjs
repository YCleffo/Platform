import { createWriteStream, mkdirSync, writeFileSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { join } from 'node:path';

const baseUrl = process.env.BASE_URL ?? 'http://127.0.0.1:4174';
const concurrency = Math.max(1, Number.parseInt(process.env.VERIFY_CONCURRENCY ?? '4', 10) || 4);
const timeoutMs = Math.max(30_000, Number.parseInt(process.env.VERIFY_TIMEOUT_MS ?? '600000', 10) || 600_000);
const logDirectory = join('screenshots', 'verify-logs');

const groups = [
  {
    name: 'appearance-theme-responsive',
    scripts: ['verify-responsive.mjs', 'verify-appearance.mjs', 'verify-ux.mjs'],
  },
  {
    name: 'accessibility-controls-typography',
    scripts: ['verify-accessibility.mjs', 'verify-controls.mjs', 'verify-typography.mjs', 'verify-mobile-components.mjs'],
  },
  {
    name: 'core-authentication-security-configuration',
    scripts: ['verify-core-auth.mjs', 'verify-core-security.mjs', 'verify-core-configuration.mjs'],
  },
  {
    name: 'core-profile-credentials-privacy',
    scripts: ['verify-core-profile.mjs', 'verify-core-credentials.mjs', 'verify-core-privacy.mjs', 'verify-core-privacy-operator.mjs'],
  },
  {
    name: 'commercial-billing-admin',
    scripts: ['verify-admin.mjs', 'verify-billing.mjs'],
  },
  {
    name: 'navigation-workflows-mobile',
    scripts: ['verify-menus.mjs', 'verify-workflows.mjs'],
  },
];

const tasks = groups.flatMap((group) => group.scripts.map((script) => ({ group: group.name, script })));

mkdirSync(logDirectory, { recursive: true });

function terminate(child) {
  if (child.exitCode !== null || child.signalCode !== null) return;
  try {
    if (process.platform === 'win32') {
      spawn('taskkill', ['/pid', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' });
    } else {
      process.kill(-child.pid, 'SIGTERM');
    }
  } catch {
    // The process may already have exited.
  }
  const escalation = setTimeout(() => {
    try {
      if (process.platform !== 'win32') process.kill(-child.pid, 'SIGKILL');
    } catch {
      // The process group is already gone.
    }
  }, 5_000);
  escalation.unref();
}

function runTask(task) {
  const logName = task.script.replace(/\.mjs$/, '.log');
  const logPath = join(logDirectory, logName);
  const started = Date.now();
  const logStream = createWriteStream(logPath);
  let tail = '';

  return new Promise((resolve) => {
    const child = spawn(process.execPath, [join('scripts', task.script)], {
      cwd: process.cwd(),
      env: { ...process.env, BASE_URL: baseUrl },
      stdio: ['ignore', 'pipe', 'pipe'],
      detached: process.platform !== 'win32',
      windowsHide: true,
    });

    const append = (chunk) => {
      const text = chunk.toString();
      logStream.write(text);
      tail = (tail + text).slice(-16_000);
    };
    child.stdout.on('data', append);
    child.stderr.on('data', append);

    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      terminate(child);
    }, timeoutMs);
    timer.unref();

    child.on('close', (code, signal) => {
      clearTimeout(timer);
      const durationMs = Date.now() - started;
      const status = timedOut ? 'timeout' : code === 0 ? 'passed' : 'failed';
      logStream.end(() => {
        if (status !== 'passed') {
          console.error(`::group::${status.toUpperCase()} ${task.script}`);
          if (tail.trim() !== '') console.error(tail.trimEnd());
          console.error(`::endgroup::`);
        }
        console.log(`${status.toUpperCase()} ${task.group} ${task.script} ${durationMs}ms (${logPath})`);
        resolve({
          script: task.script,
          group: task.group,
          status,
          exitCode: code,
          signal,
          durationMs,
          log: logPath,
        });
      });
    });
  });
}

const queue = [...tasks];
const results = [];
const suiteStarted = Date.now();

async function worker() {
  for (;;) {
    const task = queue.shift();
    if (task === undefined) return;
    results.push(await runTask(task));
  }
}

console.log(
  `Running ${tasks.length} browser verification scripts with concurrency ${concurrency} against ${baseUrl}.`,
);
await Promise.all(Array.from({ length: Math.min(concurrency, tasks.length) }, worker));

const wallMs = Date.now() - suiteStarted;
results.sort((left, right) => right.durationMs - left.durationMs);
const serialMs = results.reduce((total, result) => total + result.durationMs, 0);
writeFileSync(
  join('screenshots', 'verify-timings.json'),
  JSON.stringify({ baseUrl, concurrency, serialMs, wallMs, results }, null, 2),
);

console.log('\nVerification timing summary:');
for (const result of results) {
  console.log(
    `${result.status.padEnd(7)} ${String(result.durationMs).padStart(7)}ms ${result.group} ${result.script}`,
  );
}

const failures = results.filter((result) => result.status !== 'passed');
if (failures.length > 0) {
  console.error(`\n${failures.length} browser verification script(s) failed: ${failures.map((result) => result.script).join(', ')}`);
  process.exitCode = 1;
} else {
  console.log(
    `\nAll ${results.length} browser verification scripts passed in ${wallMs}ms wall time (${serialMs}ms of work).`,
  );
}
