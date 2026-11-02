import { computed, type ComputedRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { cachedAdminGet } from './adminCache';
import type { AdminResourceAction, AdminResourceConfig } from './types';

const enc = (value: unknown): string => encodeURIComponent(String(value ?? ''));

export function useAdminResourceConfigs(): ComputedRef<Record<string, AdminResourceConfig>> {
  const { t } = useI18n();

  return computed(() => {
    const assignRole: AdminResourceAction = {
      label: t('admin.assignRole'),
      method: 'POST',
      path: (_row, input) => `/core/accounts/${enc(input.accountId)}/roles/${enc(input.roleId)}`,
      inputs: [
        { name: 'accountId', label: t('admin.accountId'), placeholder: 'uuid' },
        { name: 'roleId', label: t('admin.roleId'), placeholder: 'uuid' },
      ],
    };
    const revokeRole: AdminResourceAction = {
      ...assignRole,
      label: t('admin.revokeRole'),
      method: 'DELETE',
      danger: true,
    };

    return {
      organizations: {
        title: t('admin.organizations'),
        description: t('admin.organizationsHint'),
        listPath: '/core/organizations',
        paginated: false,
        columns: ['code', 'name', 'id', 'createdAt'],
        create: { path: '/core/organizations', template: { code: '', name: '' } },
        actions: row => [
          { label: t('admin.members'), method: 'GET', path: () => `/core/organizations/${enc(row.id)}/members`, resultPanel: true },
          { label: t('admin.addMember'), method: 'POST', path: () => `/core/organizations/${enc(row.id)}/members`, inputs: [{ name: 'personId', label: t('admin.personId'), placeholder: 'uuid' }], body: { personId: '' } },
          { label: t('admin.removeMember'), method: 'POST', path: (_row, input) => `/core/organizations/${enc(row.id)}/members/${enc(input.personId)}/leave`, inputs: [{ name: 'personId', label: t('admin.personId'), placeholder: 'uuid' }], danger: true },
        ],
        globalActions: [assignRole, revokeRole],
      },
      persons: {
        title: t('admin.persons'),
        description: t('admin.personsHint'),
        listPath: '/core/persons',
        columns: ['displayName', 'givenName', 'familyName', 'status', 'id', 'createdAt'],
        create: { path: '/core/persons', template: { givenName: '', familyName: '', middleName: null, displayName: null, birthDate: null } },
        actions: row => [
          { label: t('admin.details'), method: 'GET', path: () => `/core/persons/${enc(row.id)}`, resultPanel: true },
          { label: t('admin.update'), method: 'PATCH', path: () => `/core/persons/${enc(row.id)}`, body: { givenName: '', familyName: '', displayName: null } },
          { label: t('admin.archive'), method: 'POST', path: () => `/core/persons/${enc(row.id)}/archive`, danger: true },
          { label: t('admin.contacts'), method: 'GET', path: () => `/core/persons/${enc(row.id)}/contacts`, resultPanel: true },
          { label: t('admin.addContact'), method: 'POST', path: () => `/core/persons/${enc(row.id)}/contacts`, body: { contactType: 'email', value: '', label: null, isPrimary: false } },
        ],
      },
      accounts: {
        title: t('admin.accounts'),
        description: t('admin.accountsHint'),
        listPath: '/core/accounts',
        columns: ['label', 'personName', 'accountType', 'status', 'id', 'createdAt'],
        decorate: async rows => {
          const data = await cachedAdminGet<unknown>('/core/persons?limit=100&offset=0');
          const people = Array.isArray(data)
            ? data
            : typeof data === 'object' && data !== null && Array.isArray((data as Record<string, unknown>).items)
              ? (data as { items: unknown[] }).items
              : [];
          const names = new Map<string, string>();
          for (const person of people) {
            if (typeof person !== 'object' || person === null) continue;
            const record = person as Record<string, unknown>;
            const name = typeof record.displayName === 'string' && record.displayName.trim() !== ''
              ? record.displayName
              : [record.givenName, record.familyName].filter(value => typeof value === 'string' && value.trim() !== '').join(' ');
            if (typeof record.id === 'string' && name !== '') names.set(record.id, name);
          }
          return rows.map(row => ({ ...row, personName: names.get(String(row.personId ?? '')) ?? '—' }));
        },
        create: { path: '/core/accounts', template: { accountType: 'human', personId: '', label: '' } },
        actions: row => [
          { label: t('admin.details'), method: 'GET', path: () => `/core/accounts/${enc(row.id)}`, resultPanel: true },
          { label: t('admin.approve'), method: 'POST', path: () => `/core/accounts/${enc(row.id)}/approve` },
          { label: t('admin.disable'), method: 'POST', path: () => `/core/accounts/${enc(row.id)}/disable`, danger: true },
          { label: t('admin.enable'), method: 'POST', path: () => `/core/accounts/${enc(row.id)}/enable` },
          { label: t('admin.archive'), method: 'POST', path: () => `/core/accounts/${enc(row.id)}/archive`, danger: true },
          { label: t('admin.invite'), method: 'POST', path: () => `/core/accounts/${enc(row.id)}/invite`, body: { email: '' } },
          { label: t('admin.serviceCredentials'), method: 'GET', path: () => `/core/accounts/${enc(row.id)}/service-credentials`, resultPanel: true },
          { label: t('admin.createCredential'), method: 'POST', path: () => `/core/accounts/${enc(row.id)}/service-credentials`, body: { scopes: ['read'], expiresAt: null } },
          { label: t('admin.rotateCredential'), method: 'POST', path: (_row, input) => `/core/accounts/${enc(row.id)}/service-credentials/${enc(input.credentialId)}/rotate`, inputs: [{ name: 'credentialId', label: t('admin.credentialId'), placeholder: 'uuid' }] },
          { label: t('admin.revokeCredential'), method: 'POST', path: (_row, input) => `/core/accounts/${enc(row.id)}/service-credentials/${enc(input.credentialId)}/revoke`, inputs: [{ name: 'credentialId', label: t('admin.credentialId'), placeholder: 'uuid' }], danger: true },
        ],
        globalActions: [
          { ...assignRole, label: t('admin.accountRoles') , method: 'GET', path: (_row, input) => `/core/accounts/${enc(input.accountId)}/roles`, inputs: [{ name: 'accountId', label: t('admin.accountId'), placeholder: 'uuid' }], resultPanel: true },
          assignRole,
          revokeRole,
        ],
      },
      roles: {
        title: t('admin.rbac'),
        description: t('admin.rbacHint'),
        listPath: '/core/roles',
        columns: ['code', 'name', 'isSystem', 'isProtected', 'id'],
        create: { path: '/core/roles', template: { code: '', name: '', description: null } },
        actions: row => [
          { label: t('admin.update'), method: 'PATCH', path: () => `/core/roles/${enc(row.id)}`, body: { code: '', name: '', description: null } },
          { label: t('admin.delete'), method: 'DELETE', path: () => `/core/roles/${enc(row.id)}`, danger: true },
          { label: t('admin.permissions'), method: 'GET', path: () => `/core/roles/${enc(row.id)}/permissions`, resultPanel: true },
          { label: t('admin.replacePermissions'), method: 'PUT', path: () => `/core/roles/${enc(row.id)}/permissions`, body: { permissions: [] } },
        ],
        globalActions: [
          { ...assignRole, label: t('admin.accountRoles'), method: 'GET', path: (_row, input) => `/core/accounts/${enc(input.accountId)}/roles`, inputs: [{ name: 'accountId', label: t('admin.accountId'), placeholder: 'uuid' }], resultPanel: true },
          assignRole,
          revokeRole,
        ],
      },
      audit: {
        title: t('admin.audit'),
        description: t('admin.auditHint'),
        listPath: '/core/audit-events',
        columns: ['action', 'actorType', 'success', 'errorCode', 'occurredAt', 'id'],
      },
      permissions: {
        title: t('admin.permissions'),
        description: t('admin.permissionsHint'),
        listPath: '/core/permissions',
        columns: ['code', 'module', 'description'],
      },
      modules: {
        title: t('admin.modules'),
        description: t('admin.modulesHint'),
        listPath: '/core/modules',
        paginated: false,
        columns: ['code', 'name', 'version', 'enabled', 'settingsSchemaVersion', 'id'],
        actions: row => [
          { label: t('admin.enable'), method: 'POST', path: () => `/core/modules/${enc(row.code)}/enable` },
          { label: t('admin.disable'), method: 'POST', path: () => `/core/modules/${enc(row.code)}/disable`, danger: true },
          { label: t('admin.moduleSettings'), method: 'GET', path: () => `/core/modules/${enc(row.code)}/settings`, resultPanel: true },
          { label: t('admin.putModuleSetting'), method: 'PUT', path: (_row, input) => `/core/modules/${enc(row.code)}/settings/${enc(input.key)}`, inputs: [{ name: 'key', label: t('admin.settingKey') }], body: { value: null, isPublic: false, description: null } },
          { label: t('admin.deleteModuleSetting'), method: 'DELETE', path: (_row, input) => `/core/modules/${enc(row.code)}/settings/${enc(input.key)}`, inputs: [{ name: 'key', label: t('admin.settingKey') }], danger: true },
        ],
      },
      settings: {
        title: t('admin.systemSettings'),
        description: t('admin.systemSettingsHint'),
        listPath: '/core/settings/system',
        columns: ['key', 'value', 'isPublic', 'description', 'updatedAt'],
        actions: row => [
          { label: t('admin.get'), method: 'GET', path: () => `/core/settings/system/${enc(row.key)}`, resultPanel: true },
          { label: t('admin.put'), method: 'PUT', path: () => `/core/settings/system/${enc(row.key)}`, body: { value: null, isPublic: false, description: null } },
          { label: t('admin.delete'), method: 'DELETE', path: () => `/core/settings/system/${enc(row.key)}`, danger: true },
        ],
        globalActions: [
          { label: t('admin.putSetting'), method: 'PUT', path: (_row, input) => `/core/settings/system/${enc(input.key)}`, inputs: [{ name: 'key', label: t('admin.settingKey') }], body: { value: null, isPublic: false, description: null } },
        ],
      },
      flags: {
        title: t('admin.featureFlags'),
        description: t('admin.featureFlagsHint'),
        listPath: '/core/feature-flags',
        paginated: false,
        columns: ['code', 'moduleCode', 'enabled', 'rolloutPercent', 'description'],
        actions: row => [
          { label: t('admin.get'), method: 'GET', path: () => `/core/feature-flags/${enc(row.code)}`, resultPanel: true },
          { label: t('admin.put'), method: 'PUT', path: () => `/core/feature-flags/${enc(row.code)}`, body: { moduleCode: '', enabled: false, rolloutPercent: 0, config: {}, description: null } },
          { label: t('admin.delete'), method: 'DELETE', path: () => `/core/feature-flags/${enc(row.code)}`, danger: true },
        ],
        globalActions: [
          { label: t('admin.putFlag'), method: 'PUT', path: (_row, input) => `/core/feature-flags/${enc(input.code)}`, inputs: [{ name: 'code', label: t('admin.flagCode') }], body: { moduleCode: '', enabled: false, rolloutPercent: 0, config: {}, description: null } },
        ],
      },
      privacyRequests: {
        title: t('admin.privacyRequests'),
        description: t('admin.privacyRequestsHint'),
        listPath: '/core/privacy/requests',
        columns: ['requestType', 'status', 'receivedAt', 'dueAt', 'id'],
        create: {
          path: '/core/privacy/requests/intake',
          template: {
            subjectPersonId: '',
            requestType: 'access',
            receivedAt: new Date().toISOString(),
            identityVerificationCode: '',
            identityEvidenceReference: '',
            sourceChannel: 'admin',
          },
        },
        actions: row => [
          { label: t('admin.details'), method: 'GET', path: () => `/core/privacy/requests/${enc(row.id)}`, resultPanel: true },
          { label: t('admin.transition'), method: 'POST', path: () => `/core/privacy/requests/${enc(row.id)}/transition`, body: { toStatus: 'in_review', reasonCode: 'admin_review' } },
          { label: t('admin.privacyExport'), method: 'GET', path: () => `/core/privacy/requests/${enc(row.id)}/data`, resultPanel: true },
          { label: t('admin.processingInfo'), method: 'GET', path: () => `/core/privacy/requests/${enc(row.id)}/processing-information`, resultPanel: true },
        ],
      },
      legalHolds: {
        title: t('admin.legalHolds'),
        description: t('admin.legalHoldsHint'),
        listPath: '/core/privacy/legal-holds',
        paginated: false,
        columns: ['scopeType', 'reasonCode', 'subjectPersonId', 'resourceType', 'createdAt', 'id'],
        create: {
          path: '/core/privacy/legal-holds',
          template: {
            scopeType: 'subject',
            reasonCode: 'legal_hold',
            sourceType: 'admin',
          },
        },
        actions: row => [
          { label: t('admin.details'), method: 'GET', path: () => `/core/privacy/legal-holds/${enc(row.id)}`, resultPanel: true },
          { label: t('admin.release'), method: 'POST', path: () => `/core/privacy/legal-holds/${enc(row.id)}/release`, body: { reasonCode: 'released' }, danger: true },
        ],
      },
    };
  });
}
