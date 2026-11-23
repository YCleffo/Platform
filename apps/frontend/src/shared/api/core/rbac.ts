import { ApiResponseError } from '../errors';
import { coreAdminHttp } from './adminHttp';

export interface CorePermission {
  id: string;
  code: string;
  name: string;
  description: string;
  module: 'core' | 'commercial' | 'education' | 'platform';
}

export interface CoreRole {
  id: string;
  name: string;
  code: string;
  description: string;
  isSystem: boolean;
  permissions: string[];
  createdAt: string;
  updatedAt: string;
}

export interface AccountRoleAssignment {
  accountId: string;
  roleId: string;
  roleCode: string;
  roleName: string;
  assignedAt: string;
  assignedBy: string;
}

const object = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string' && v !== '';
const booleanVal = (v: unknown): v is boolean => typeof v === 'boolean';

export function isPermission(v: unknown): v is CorePermission {
  return (
    object(v) &&
    text(v.id) &&
    text(v.code) &&
    text(v.name) &&
    typeof v.description === 'string' &&
    (v.module === 'core' ||
      v.module === 'commercial' ||
      v.module === 'education' ||
      v.module === 'platform')
  );
}

export function isRole(v: unknown): v is CoreRole {
  return (
    object(v) &&
    text(v.id) &&
    text(v.name) &&
    text(v.code) &&
    typeof v.description === 'string' &&
    booleanVal(v.isSystem) &&
    Array.isArray(v.permissions) &&
    v.permissions.every(text) &&
    text(v.createdAt) &&
    text(v.updatedAt)
  );
}

export function isAccountRoleAssignment(v: unknown): v is AccountRoleAssignment {
  return (
    object(v) &&
    text(v.accountId) &&
    text(v.roleId) &&
    text(v.roleCode) &&
    text(v.roleName) &&
    text(v.assignedAt) &&
    text(v.assignedBy)
  );
}

function checked<T>(value: unknown, valid: (v: unknown) => boolean, path: string): T {
  if (!valid(value)) throw new ApiResponseError(path);
  return value as T;
}

export const coreRbacApi = {
  async listPermissions(): Promise<CorePermission[]> {
    const path = '/core/permissions';
    const res = await coreAdminHttp.get(path);
    return checked<{ items: CorePermission[] }>(
      res,
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isPermission),
      path,
    ).items;
  },

  async listRoles(): Promise<CoreRole[]> {
    const path = '/core/roles';
    const res = await coreAdminHttp.get(path);
    return checked<{ items: CoreRole[] }>(
      res,
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isRole),
      path,
    ).items;
  },

  async getRole(roleId: string): Promise<CoreRole> {
    const path = `/core/roles/${encodeURIComponent(roleId)}`;
    const res = await coreAdminHttp.get(path);
    return checked<CoreRole>(res, isRole, path);
  },

  async createRole(payload: {
    name: string;
    code: string;
    description?: string;
    permissions: string[];
  }): Promise<CoreRole> {
    const path = '/core/roles';
    const res = await coreAdminHttp.post(path, payload);
    return checked<CoreRole>(res, isRole, path);
  },

  async patchRole(
    roleId: string,
    payload: {
      name?: string;
      description?: string;
      permissions?: string[];
    },
  ): Promise<CoreRole> {
    const path = `/core/roles/${encodeURIComponent(roleId)}`;
    const res = await coreAdminHttp.patch(path, payload);
    return checked<CoreRole>(res, isRole, path);
  },

  async deleteRole(roleId: string): Promise<void> {
    const path = `/core/roles/${encodeURIComponent(roleId)}`;
    const res = await coreAdminHttp.delete(path);
    checked(res, (v) => object(v) && v.status === 'deleted', path);
  },

  async listAccountRoles(accountId: string): Promise<AccountRoleAssignment[]> {
    const path = `/core/accounts/${encodeURIComponent(accountId)}/roles`;
    const res = await coreAdminHttp.get(path);
    return checked<{ items: AccountRoleAssignment[] }>(
      res,
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isAccountRoleAssignment),
      path,
    ).items;
  },

  async assignAccountRole(accountId: string, roleId: string): Promise<AccountRoleAssignment> {
    const path = `/core/accounts/${encodeURIComponent(accountId)}/roles`;
    const res = await coreAdminHttp.post(path, { roleId });
    return checked<AccountRoleAssignment>(res, isAccountRoleAssignment, path);
  },

  async revokeAccountRole(accountId: string, roleId: string): Promise<void> {
    const path = `/core/accounts/${encodeURIComponent(accountId)}/roles/${encodeURIComponent(roleId)}`;
    const res = await coreAdminHttp.delete(path);
    checked(res, (v) => object(v) && v.status === 'revoked', path);
  },
};
