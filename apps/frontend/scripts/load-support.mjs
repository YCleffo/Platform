/** Closed workload statistics. Empty stages and invalid responses must never pass. */
export function summarizeSamples(samples, seconds, budget) {
  const durations = samples.map(item => item.ms).sort((a, b) => a - b);
  const percentile = p => durations.length ? durations[Math.ceil(p * durations.length) - 1] : null;
  const errors = samples.filter(item => !item.ok).length;
  const errorRate = samples.length ? errors / samples.length : 1;
  const p95 = percentile(.95);
  const statuses = {};
  for (const sample of samples) statuses[sample.status] = (statuses[sample.status] ?? 0) + 1;
  return { requests: samples.length, errors, errorRate, rps: seconds > 0 ? samples.length / seconds : 0,
    p50: percentile(.5), p95, p99: percentile(.99), max: durations.at(-1) ?? null, statuses,
    budget, passed: samples.length >= budget.minRequests && p95 !== null && p95 <= budget.p95 && errorRate <= budget.errorRate };
}

export async function runStage({ concurrency, durationMs, request, budget }) {
  const start = performance.now();
  const deadline = start + durationMs;
  const samples = [];
  let sequence = 0;
  await Promise.all(Array.from({ length: concurrency }, async () => {
    while (performance.now() < deadline) {
      const index = sequence++;
      const started = performance.now();
      let result;
      try { result = await request(index); }
      catch { result = { ok: false, status: 'network-error', route: 'transport' }; }
      samples.push({ ...result, ms: performance.now() - started });
    }
  }));
  const seconds = (performance.now() - start) / 1000;
  const routes = {};
  for (const route of new Set(samples.map(item => item.route))) routes[route] = summarizeSamples(samples.filter(item => item.route === route), seconds, { ...budget, minRequests: 1 });
  const summary = summarizeSamples(samples, seconds, budget);
  return { concurrency, seconds, ...summary, routes, passed: summary.passed && Object.values(routes).every(route => route.passed) };
}
