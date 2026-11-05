import { isObject, type ConfigValue } from '@/shared/api/core/configuration';
export const valueKinds = ['string', 'number', 'boolean', 'null', 'object', 'array'] as const;
export type ValueKind = (typeof valueKinds)[number];
export interface ValueDraft {
  id: number;
  kind: ValueKind;
  text: string;
  checked: boolean;
  children: { key: string; node: ValueDraft }[];
}
let serial = 0;
export function draftValue(value: ConfigValue = ''): ValueDraft {
  const kind: ValueKind =
    value === null
      ? 'null'
      : Array.isArray(value)
        ? 'array'
        : isObject(value)
          ? 'object'
          : (typeof value as 'string' | 'number' | 'boolean');
  return {
    id: ++serial,
    kind,
    text: typeof value === 'string' || typeof value === 'number' ? String(value) : '',
    checked: value === true,
    children:
      kind === 'array'
        ? (value as ConfigValue[]).map((v) => ({ key: '', node: draftValue(v) }))
        : kind === 'object'
          ? Object.entries(value as Record<string, ConfigValue>).map(([key, v]) => ({
              key,
              node: draftValue(v),
            }))
          : [],
  };
}
export function readDraft(node: ValueDraft, depth = 0): ConfigValue {
  if (depth > 32) throw new TypeError('depth');
  if (node.kind === 'string') return node.text;
  if (node.kind === 'null') return null;
  if (node.kind === 'boolean') return node.checked;
  if (node.kind === 'number') {
    if (
      !/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(node.text) ||
      !Number.isFinite(Number(node.text))
    )
      throw new TypeError('number');
    return Number(node.text);
  }
  if (node.kind === 'array') return node.children.map((c) => readDraft(c.node, depth + 1));
  const keys = node.children.map((c) => c.key);
  if (
    keys.some((k) => k === '' || ['__proto__', 'constructor', 'prototype'].includes(k)) ||
    new Set(keys).size !== keys.length
  )
    throw new TypeError('keys');
  return Object.fromEntries(node.children.map((c) => [c.key, readDraft(c.node, depth + 1)]));
}
