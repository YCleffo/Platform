import { coreAdminHttp } from './adminHttp';
import { bounded } from './privacy';
import { ApiError, ApiResponseError, NetworkError } from '../errors';

export type ConfigValue =
  null | boolean | number | string | ConfigValue[] | { [key: string]: ConfigValue };
export interface CoreModule {
  id: string;
  code: string;
  name: string;
  description: string | null;
  version: string;
  settingsSchemaVersion: number;
  enabled: boolean;
  installedAt: string;
  enabledAt: string | null;
  disabledAt: string | null;
}
export interface CoreSetting {
  id: string;
  key: string;
  value: ConfigValue;
  description: string | null;
  updatedAt: string;
  createdAt: string;
  isPublic?: boolean;
  moduleCode?: string;
}
export interface CoreFlag {
  id: string;
  code: string;
  moduleCode: string | null;
  enabled: boolean;
  rolloutPercent: number;
  config: Record<string, ConfigValue>;
  description: string | null;
  updatedAt: string;
  createdAt: string;
}
export interface SettingInput {
  value: ConfigValue;
  description: string | null;
  isPublic?: boolean;
}
export interface FlagInput {
  moduleCode: string | null;
  enabled: boolean;
  rolloutPercent: number;
  config: Record<string, ConfigValue>;
  description: string | null;
}
export const isObject = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string';
const nullable = (v: unknown) => v === null || text(v);
export const machineCode = (s: string, max = 160) =>
  s.length > 0 && s.length <= max && /^[a-z0-9]+(?:[._-][a-z0-9]+)*$/.test(s);
export const safeKey = (s: string) =>
  !['password', 'passwd', 'secret', 'token', 'privatekey', 'apikey', 'credential'].some((term) =>
    s
      .toLowerCase()
      .replace(/[^a-z0-9]/g, '')
      .includes(term),
  );
export function isConfigValue(v: unknown, depth = 0): v is ConfigValue {
  if (depth > 32) return false;
  if (v === null || text(v) || typeof v === 'boolean') return true;
  if (typeof v === 'number') return Number.isFinite(v);
  if (Array.isArray(v)) return v.every((item) => isConfigValue(item, depth + 1));
  return (
    isObject(v) &&
    Object.entries(v).every(
      ([k, item]) =>
        !['__proto__', 'constructor', 'prototype'].includes(k) && isConfigValue(item, depth + 1),
    )
  );
}
function moduleRow(v: unknown): v is CoreModule {
  return (
    isObject(v) &&
    [v.id, v.code, v.name, v.version, v.installedAt].every(text) &&
    nullable(v.description) &&
    nullable(v.enabledAt) &&
    nullable(v.disabledAt) &&
    typeof v.enabled === 'boolean' &&
    Number.isInteger(v.settingsSchemaVersion)
  );
}
function settingRow(v: unknown, module?: string): v is CoreSetting {
  return (
    isObject(v) &&
    [v.id, v.key, v.updatedAt, v.createdAt].every(text) &&
    nullable(v.description) &&
    isConfigValue(v.value) &&
    (module === undefined ? typeof v.isPublic === 'boolean' : v.moduleCode === module)
  );
}
function flagRow(v: unknown): v is CoreFlag {
  return (
    isObject(v) &&
    [v.id, v.code, v.updatedAt, v.createdAt].every(text) &&
    nullable(v.description) &&
    nullable(v.moduleCode) &&
    typeof v.enabled === 'boolean' &&
    Number.isInteger(v.rolloutPercent) &&
    Number(v.rolloutPercent) >= 0 &&
    Number(v.rolloutPercent) <= 100 &&
    (isObject(v.config) || (Array.isArray(v.config) && v.config.length === 0)) &&
    isConfigValue(v.config)
  );
}
function checked<T>(v: unknown, guard: (v: unknown) => boolean, path: string): T {
  if (!guard(v)) throw new ApiResponseError(path);
  return v as T;
}
const get = (p: string) => bounded((signal) => coreAdminHttp.get(p, signal));
const settingsPath = (module?: string) =>
  module === undefined
    ? '/core/settings/system'
    : `/core/modules/${encodeURIComponent(module)}/settings`;
const flagsPath = '/core/feature-flags';
function checkInput(key: string, input: SettingInput | FlagInput): void {
  if (!machineCode(key) || !safeKey(key)) throw new TypeError('Invalid configuration key');
  const value = 'value' in input ? input.value : input.config;
  function safe(v: ConfigValue): boolean {
    return Array.isArray(v)
      ? v.every(safe)
      : isObject(v)
        ? Object.entries(v).every(([k, item]) => safeKey(k) && safe(item as ConfigValue))
        : true;
  }
  if (
    !isConfigValue(value) ||
    !safe(value) ||
    new TextEncoder().encode(JSON.stringify(value)).length > 16384
  )
    throw new TypeError('Invalid configuration value');
  if (
    input.description !== null &&
    (input.description.length > 1000 || /[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(input.description))
  )
    throw new TypeError('Invalid description');
}
export const coreConfiguration = {
  async modules(): Promise<CoreModule[]> {
    const p = '/core/modules';
    return checked(await get(p), (v) => Array.isArray(v) && v.every(moduleRow), p);
  },
  async module(code: string): Promise<CoreModule> {
    const p = '/core/modules/' + encodeURIComponent(code);
    return checked(await get(p), (v) => moduleRow(v) && v.code === code, p);
  },
  async setModule(code: string, enabled: boolean): Promise<CoreModule> {
    const p = '/core/modules/' + encodeURIComponent(code) + (enabled ? '/enable' : '/disable');
    return checked(
      await bounded((signal) => coreAdminHttp.post(p, {}, signal)),
      (v) => moduleRow(v) && v.code === code && v.enabled === enabled,
      p,
    );
  },
  async settings(module?: string): Promise<CoreSetting[]> {
    const p = settingsPath(module);
    return checked(
      await get(p),
      (v) => Array.isArray(v) && v.every((row) => settingRow(row, module)),
      p,
    );
  },
  async setting(key: string, module?: string): Promise<CoreSetting> {
    const p = settingsPath(module) + '/' + encodeURIComponent(key);
    return checked(await get(p), (v) => settingRow(v, module) && v.key === key, p);
  },
  async saveSetting(key: string, input: SettingInput, module?: string): Promise<CoreSetting> {
    checkInput(key, input);
    const p = settingsPath(module) + '/' + encodeURIComponent(key);
    const body =
      module === undefined
        ? { ...input, isPublic: input.isPublic === true }
        : { value: input.value, description: input.description };
    return checked(
      await bounded((signal) => coreAdminHttp.put(p, body, signal)),
      (v) => settingRow(v, module) && v.key === key,
      p,
    );
  },
  async removeSetting(key: string, module?: string): Promise<void> {
    const p = settingsPath(module) + '/' + encodeURIComponent(key);
    checked(
      await bounded((signal) => coreAdminHttp.delete(p, undefined, signal)),
      (v) => isObject(v) && v.deleted === true && v.key === key,
      p,
    );
  },
  async flags(): Promise<CoreFlag[]> {
    return checked<CoreFlag[]>(
      await get(flagsPath),
      (v) => Array.isArray(v) && v.every(flagRow),
      flagsPath,
    ).map((row) => ({ ...row, config: Array.isArray(row.config) ? {} : row.config }));
  },
  async flag(code: string): Promise<CoreFlag> {
    const p = flagsPath + '/' + encodeURIComponent(code);
    const row = checked<CoreFlag>(await get(p), (v) => flagRow(v) && v.code === code, p);
    return { ...row, config: Array.isArray(row.config) ? {} : row.config };
  },
  async saveFlag(code: string, input: FlagInput): Promise<CoreFlag> {
    checkInput(code, input);
    if (
      (input.moduleCode !== null && !machineCode(input.moduleCode, 100)) ||
      !Number.isInteger(input.rolloutPercent) ||
      input.rolloutPercent < 0 ||
      input.rolloutPercent > 100 ||
      !isObject(input.config)
    )
      throw new TypeError('Invalid flag');
    const p = flagsPath + '/' + encodeURIComponent(code);
    const row = checked<CoreFlag>(
      await bounded((signal) => coreAdminHttp.put(p, input, signal)),
      (v) => flagRow(v) && v.code === code,
      p,
    );
    return { ...row, config: Array.isArray(row.config) ? {} : row.config };
  },
  async removeFlag(code: string): Promise<void> {
    const p = flagsPath + '/' + encodeURIComponent(code);
    checked(
      await bounded((signal) => coreAdminHttp.delete(p, undefined, signal)),
      (v) => isObject(v) && v.deleted === true && v.code === code,
      p,
    );
  },
  async publicSettings(): Promise<Record<string, ConfigValue>> {
    const path = '/api/public/config';
    return bounded(async (signal) => {
      let response: Response;
      try {
        response = await fetch(path, {
          signal,
          credentials: 'omit',
          cache: 'no-store',
          headers: { Accept: 'application/json' },
        });
      } catch (error) {
        if (signal.aborted) throw error;
        throw new NetworkError();
      }
      if (!response.ok)
        throw new ApiError(
          response.status,
          'public_config.unavailable',
          'Public configuration unavailable',
        );
      const v: unknown = await response.json().catch(() => {
        throw new ApiResponseError(path);
      });
      return checked<{ status: 'success'; data: { settings: Record<string, ConfigValue> } }>(
        v,
        (x) =>
          isObject(x) &&
          x.status === 'success' &&
          isObject(x.data) &&
          isObject(x.data.settings) &&
          isConfigValue(x.data.settings),
        path,
      ).data.settings;
    });
  },
};
