import type { Plan } from '@/shared/types';
import { planFeatureEntries } from './features';
import type { PlanFeatureEntry } from './features';

export interface PlanComparisonRow {
  code: string;
  label: string;
  values: Array<PlanFeatureEntry | null>;
}

/** Builds the feature matrix in first-seen order. False/absent values become null. */
export function comparisonRows(plans: Plan[]): PlanComparisonRow[] {
  const rows = new Map<string, PlanComparisonRow>();
  plans.forEach((plan, planIndex) => {
    for (const entry of planFeatureEntries(plan.features)) {
      let row = rows.get(entry.code);
      if (row === undefined) {
        row = { code: entry.code, label: entry.label, values: plans.map(() => null) };
        rows.set(entry.code, row);
      }
      row.values[planIndex] = entry;
    }
  });
  return [...rows.values()];
}

/** Published catalog can contain multiple versions; compare the latest one per code. */
export function latestPlans(plans: Plan[]): Plan[] {
  const latest = new Map<string, Plan>();
  for (const plan of plans) {
    const current = latest.get(plan.code);
    if (current === undefined || plan.version > current.version) {
      latest.set(plan.code, plan);
    }
  }
  return [...latest.values()];
}
