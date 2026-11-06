import { appendFileSync } from 'node:fs';

const target = process.env.CAPACITY_TARGET_URL;
const environment = process.env.CAPACITY_ENVIRONMENT ?? 'local';
const durationSeconds = Number.parseInt(process.env.CAPACITY_DURATION_SECONDS ?? '30', 10);
const concurrency = Number.parseInt(process.env.CAPACITY_CONCURRENCY ?? '10', 10);
const prometheus = process.env.CAPACITY_PROMETHEUS_URL;
const output = process.env.CAPACITY_OUTPUT;

if (!target) {
  console.error('CAPACITY_TARGET_URL is required.');
  process.exit(2);
}
if (!['local', 'staging', 'production'].includes(environment)) {
  console.error('CAPACITY_ENVIRONMENT must be local, staging, or production.');
  process.exit(2);
}
if (environment === 'production' && process.env.CAPACITY_ALLOW_PRODUCTION !== 'true') {
  console.error('Refusing a production baseline without CAPACITY_ALLOW_PRODUCTION=true.');
  process.exit(2);
}
if (!Number.isFinite(durationSeconds) || durationSeconds < 5 || durationSeconds > 3600) {
  console.error('CAPACITY_DURATION_SECONDS must be between 5 and 3600.');
  process.exit(2);
}
if (!Number.isFinite(concurrency) || concurrency < 1 || concurrency > 200) {
  console.error('CAPACITY_CONCURRENCY must be between 1 and 200.');
  process.exit(2);
}

const latencies = [];
let ok = 0;
let failed = 0;
const deadline = Date.now() + durationSeconds * 1000;

async function worker() {
  while (Date.now() < deadline) {
    const started = performance.now();
    try {
      const response = await fetch(target, {
        headers: { 'User-Agent': 'platform-capacity-baseline/1' },
        signal: AbortSignal.timeout(10_000),
      });
      await response.arrayBuffer();
      if (response.ok) ok += 1;
      else failed += 1;
    } catch {
      failed += 1;
    } finally {
      latencies.push(performance.now() - started);
    }
  }
}

const startedAt = new Date();
await Promise.all(Array.from({ length: concurrency }, worker));
const elapsedSeconds = (Date.now() - startedAt.getTime()) / 1000;
latencies.sort((left, right) => left - right);
const percentile = (p) => {
  if (latencies.length === 0) return 0;
  const index = Math.min(latencies.length - 1, Math.ceil((p / 100) * latencies.length) - 1);
  return latencies[index];
};
const total = ok + failed;
const snapshot = prometheus ? await prometheusSnapshot(prometheus) : {};

const lines = [
  '# Platform capacity baseline',
  '',
  `- Environment: \`${environment}\``,
  `- Target: \`${target}\``,
  `- Started: ${startedAt.toISOString()}`,
  `- Duration: ${elapsedSeconds.toFixed(1)} s`,
  `- Concurrency: ${concurrency}`,
  `- Requests: ${total}`,
  `- Throughput: ${(total / elapsedSeconds).toFixed(1)} req/s`,
  `- Success rate: ${total === 0 ? '0.0' : ((ok / total) * 100).toFixed(2)}%`,
  `- Client latency p50: ${percentile(50).toFixed(1)} ms`,
  `- Client latency p95: ${percentile(95).toFixed(1)} ms`,
  `- Client latency p99: ${percentile(99).toFixed(1)} ms`,
  '',
  '## Prometheus snapshot',
  '',
];
if (Object.keys(snapshot).length === 0) {
  lines.push('Not requested. Set `CAPACITY_PROMETHEUS_URL` to include server-side signals.');
} else {
  lines.push('| Metric | Value |', '| --- | --- |');
  for (const [metric, value] of Object.entries(snapshot)) {
    lines.push(`| \`${metric}\` | ${value} |`);
  }
}
lines.push(
  '',
  '> This is a measured baseline for the named environment. It is not a production',
  '> capacity proof unless it was run against representative production volumes and',
  '> approved by the operator. Client latency is measured by this Node process.',
  '',
);
const report = lines.join('\n');
process.stdout.write(report);
if (output) {
  appendFileSync(output, report);
  console.log(`Report appended to ${output}`);
}

async function prometheusSnapshot(base) {
  const queries = {
    ready: 'platform_ready',
    outbox_pending: 'platform_outbox_pending{queue="all"}',
    outbox_oldest_age_seconds: 'platform_outbox_oldest_age_seconds{queue="all"}',
    worker_heartbeat_age_seconds:
      'max(platform_background_job_heartbeat_age_seconds{worker=~"__scheduler__|backend_email_worker|control_outbox_worker"})',
    caddy_p95_seconds:
      'histogram_quantile(0.95, sum by (le) (rate(caddy_http_request_duration_seconds_bucket[5m])))',
    postgres_up: 'pg_up',
    redis_up: 'redis_up',
  };
  const result = {};
  for (const [name, query] of Object.entries(queries)) {
    try {
      const response = await fetch(
        `${base.replace(/\/$/, '')}/api/v1/query?query=${encodeURIComponent(query)}`,
        { signal: AbortSignal.timeout(10_000) },
      );
      if (!response.ok) continue;
      const body = await response.json();
      const values = Array.isArray(body?.data?.result) ? body.data.result : [];
      result[name] = values.map((item) => item.value?.[1]).filter(Boolean).join(', ') || 'no data';
    } catch {
      result[name] = 'unavailable';
    }
  }
  return result;
}

