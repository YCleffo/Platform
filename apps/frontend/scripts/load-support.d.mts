export interface LoadBudget { minRequests: number; p95: number; errorRate: number }
export interface LoadSample { ok: boolean; status: number | string; ms: number; route?: string }
export interface LoadSummary {
  requests: number; errors: number; errorRate: number; rps: number;
  p50: number | null; p95: number | null; p99: number | null; max: number | null;
  statuses: Record<string, number>; budget: LoadBudget; passed: boolean;
}
export function summarizeSamples(samples: LoadSample[], seconds: number, budget: LoadBudget): LoadSummary;
export function runStage(options: {
  concurrency: number; durationMs: number; budget: LoadBudget;
  request: (sequence: number) => Promise<{ ok: boolean; status: number | string; route: string }>;
}): Promise<LoadSummary & { concurrency: number; seconds: number; routes: Record<string, LoadSummary> }>;
