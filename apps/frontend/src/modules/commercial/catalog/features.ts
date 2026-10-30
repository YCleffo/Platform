export interface PlanFeatureEntry {
  code: string;
  label: string;
  kind: 'included' | 'value';
  value: string;
}

function humanizeFeature(code: string): string {
  const words = code.replace(/[._-]+/g, ' ').trim();
  return words === '' ? code : words.charAt(0).toUpperCase() + words.slice(1);
}

/** Normalizes the plan `features` object from the API into ordered display entries. */
export function planFeatureEntries(features: Record<string, unknown>): PlanFeatureEntry[] {
  const entries: PlanFeatureEntry[] = [];
  for (const [code, value] of Object.entries(features)) {
    if (value === false || value === null || value === undefined) {
      continue;
    }
    if (value === true) {
      entries.push({ code, label: humanizeFeature(code), kind: 'included', value: '' });
      continue;
    }
    const text = typeof value === 'string' ? value : typeof value === 'number' ? String(value) : '';
    entries.push({ code, label: humanizeFeature(code), kind: 'value', value: text });
  }
  return entries;
}
