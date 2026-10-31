import { describe, expect, it } from 'vitest';
import { runStage, summarizeSamples } from '../../../scripts/load-support.mjs';

describe('load acceptance criteria', () => {
  const budget = { minRequests: 2, p95: 100, errorRate: .01 };
  it('fails an empty run and a fast HTTP response with the wrong contract', () => {
    expect(summarizeSamples([], 1, budget).passed).toBe(false);
    const samples = [{ ok: true, ms: 1, status: 200 }, { ok: false, ms: 2, status: 200 }];
    expect(summarizeSamples(samples, 1, budget)).toMatchObject({ errors: 1, errorRate: .5, passed: false });
  });
  it('uses nearest-rank tail latency and fails a slow tail', () => {
    const samples = Array.from({ length: 20 }, (_, index) => ({ ok: true, ms: index >= 18 ? 150 : 1, status: 200 }));
    expect(summarizeSamples(samples, 2, budget)).toMatchObject({ p95: 150, p99: 150, rps: 10, passed: false });
  });
  it('caps concurrency, records transport failures and has no implicit retries', async () => {
    let active = 0, peak = 0, calls = 0;
    const result = await runStage({ concurrency: 3, durationMs: 12, budget: { minRequests: 1, p95: 1000, errorRate: 0 }, request: async () => {
      calls++; active++; peak = Math.max(active, peak);
      await new Promise(resolve => setTimeout(resolve, 5)); active--;
      throw new Error('connection reset');
    } });
    expect(peak).toBe(3);
    expect(result.requests).toBe(calls);
    expect(result.errors).toBe(calls);
    expect(result.passed).toBe(false);
  });
});
