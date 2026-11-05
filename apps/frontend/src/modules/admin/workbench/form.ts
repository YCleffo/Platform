export type FormValue = string | number | boolean | string[];
export type FormData = Record<string, FormValue>;
export interface Field {
  name: string;
  kind?:
    | 'text'
    | 'email'
    | 'date'
    | 'datetime'
    | 'number'
    | 'select'
    | 'multi'
    | 'boolean'
    | 'entity'
    | 'codes';
  optional?: boolean;
  nullable?: boolean;
  param?: boolean;
  rule?: 'uuid' | 'code' | 'sha256' | 'urn';
  options?: string[];
  source?: 'persons' | 'accounts' | 'roles' | 'permissions';
  path?: string;
  permission?: string;
  readOnly?: boolean;
  min?: number;
  max?: number;
  initial?: FormValue;
  when?: (data: FormData) => boolean;
}
export interface Operation {
  key: string;
  label: string;
  method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  path: string;
  permission: string;
  fields: Field[];
  danger?: boolean;
  prepare?: string;
  blocked?: (row: Record<string, unknown>) => boolean;
}
export const uuidPattern = /^[a-f\d]{8}-[a-f\d]{4}-[a-f\d]{4}-[a-f\d]{4}-[a-f\d]{12}$/i;
const codePattern = /^[a-z\d]+(?:[._-][a-z\d]+)*$/;
export class FormValidationError extends Error {
  constructor(
    public readonly field: string,
    public readonly reason: string,
  ) {
    super(reason);
  }
}
export function initialData(fields: Field[], row: Record<string, unknown> = {}): FormData {
  return Object.fromEntries(
    fields.map((field) => {
      const raw =
        row[field.name] ??
        field.initial ??
        (field.kind === 'boolean'
          ? false
          : ['multi', 'codes'].includes(field.kind ?? '')
            ? []
            : '');
      const value = Array.isArray(raw)
        ? raw.filter((v): v is string => typeof v === 'string')
        : ['string', 'number', 'boolean'].includes(typeof raw)
          ? (raw as FormValue)
          : '';
      return [
        field.name,
        field.kind === 'datetime' &&
        typeof value === 'string' &&
        value &&
        Number.isFinite(Date.parse(value))
          ? new Date(new Date(value).getTime() - new Date(value).getTimezoneOffset() * 60000)
              .toISOString()
              .slice(0, 16)
          : value,
      ];
    }),
  );
}
export function serialize(
  fields: Field[],
  data: FormData,
  includeParams = false,
): Record<string, unknown> {
  const result: Record<string, unknown> = {};
  for (const field of fields) {
    if (field.when && !field.when(data)) continue;
    const fail = (reason = 'invalid'): never => {
      throw new FormValidationError(field.name, reason);
    };
    let value: unknown = data[field.name];
    if (typeof value === 'string') value = value.trim();
    if (value === '' || value === undefined || value === null) {
      if (!field.optional) fail('required');
      if (field.nullable && (!field.param || includeParams)) result[field.name] = null;
      continue;
    }
    if (field.kind === 'boolean') {
      if (typeof value !== 'boolean') fail();
    } else if (field.kind === 'number') {
      value = Number(value);
      if (
        !Number.isSafeInteger(value) ||
        Number(value) < (field.min ?? 0) ||
        Number(value) > (field.max ?? Number.MAX_SAFE_INTEGER)
      )
        fail();
    } else if (field.kind === 'codes' || field.kind === 'multi') {
      if (
        !Array.isArray(value) ||
        value.some((v) => typeof v !== 'string') ||
        value.length > (field.max ?? 32) ||
        new Set(value).size !== value.length
      )
        fail();
      const list = value as string[];
      if (!field.optional && list.length === 0) fail('required');
      if (field.rule === 'uuid' && list.some((v) => !uuidPattern.test(v))) fail();
      if (
        field.kind === 'codes' &&
        field.rule !== 'uuid' &&
        list.some((v) => v.length > 120 || !codePattern.test(v))
      )
        fail();
      if (field.options && list.some((v) => !field.options!.includes(v))) fail();
    } else {
      if (typeof value !== 'string') fail();
      const text = value as string;
      if (text.length > (field.max ?? 512) || /[\u0000-\u001f]/.test(text)) fail();
      if (field.rule === 'uuid' && !uuidPattern.test(text)) fail();
      if (field.rule === 'code' && (text.length > 120 || !codePattern.test(text))) fail();
      if (field.rule === 'sha256' && !/^[a-f\d]{64}$/.test(text)) fail();
      if (field.rule === 'urn' && !/^urn:[^\s]{1,496}$/.test(text)) fail();
      if (field.kind === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text)) fail();
      if (field.options && !field.options.includes(text)) fail();
      if (
        field.kind === 'date' &&
        (!/^\d{4}-\d{2}-\d{2}$/.test(text) ||
          !Number.isFinite(Date.parse(text)) ||
          new Date(text).toISOString().slice(0, 10) !== text)
      )
        fail();
      if (field.kind === 'datetime') {
        if (!Number.isFinite(Date.parse(text))) fail();
        value = new Date(text).toISOString().replace(/\.\d{3}Z$/, 'Z');
      }
    }
    if (includeParams || !field.param) result[field.name] = value;
  }
  return result;
}
export function operationPath(op: Operation, row: Record<string, unknown>, data: FormData): string {
  const params = {
    ...row,
    ...serialize(
      op.fields.filter((f) => f.param),
      data,
      true,
    ),
  };
  return op.path.replace(/\{(\w+)\}/g, (_, name: string) => {
    const value = params[name];
    if (typeof value !== 'string' || !uuidPattern.test(value))
      throw new FormValidationError(name, 'required');
    return encodeURIComponent(value);
  });
}
export function rowsFrom(value: unknown): { items: Record<string, unknown>[]; total: number } {
  const record =
    value !== null && typeof value === 'object' ? (value as Record<string, unknown>) : {};
  const items = Array.isArray(value) ? value : record.items;
  if (
    !Array.isArray(items) ||
    items.some((v) => v === null || typeof v !== 'object' || Array.isArray(v))
  )
    throw new Error('api-response');
  const pagination = record.pagination as { total?: number } | undefined;
  return {
    items: items as Record<string, unknown>[],
    total: typeof record.total === 'number' ? record.total : (pagination?.total ?? items.length),
  };
}
