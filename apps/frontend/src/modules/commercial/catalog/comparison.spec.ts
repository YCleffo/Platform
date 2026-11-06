import { describe, expect, it } from 'vitest';
import type { Plan } from '@/shared/types';
import { comparisonRows, latestPlans } from './comparison';

function plan(code: string, version: number, features: Record<string, unknown>): Plan {
  return {
    id: `${code}-${version}`,
    code,
    version,
    name: code,
    prices: [],
    features,
  };
}

describe('plan comparison', () => {
  it('keeps the latest version per plan code', () => {
    const plans = [plan('start', 1, {}), plan('start', 2, {}), plan('business', 1, {})];
    expect(latestPlans(plans).map((item) => `${item.code}@${item.version}`)).toEqual(['start@2', 'business@1']);
  });

  it('builds a first-seen feature matrix and drops false values', () => {
    const plans = [
      plan('start', 1, { seats: 3, sso: false }),
      plan('business', 1, { seats: 25, analytics: true, sso: true }),
    ];
    const rows = comparisonRows(plans);
    expect(rows.map((row) => row.code)).toEqual(['seats', 'analytics', 'sso']);
    expect(rows[0]?.values.map((value) => value?.value)).toEqual(['3', '25']);
    expect(rows[1]?.values.map((value) => value?.kind ?? null)).toEqual([null, 'included']);
    expect(rows[2]?.values.map((value) => value?.kind ?? null)).toEqual([null, 'included']);
  });
});
