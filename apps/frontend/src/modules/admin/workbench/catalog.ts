import type { Field, Operation } from './form';
const text = (name: string, extra: Partial<Field> = {}): Field => ({ name, ...extra });
const code = (name: string, extra: Partial<Field> = {}): Field =>
  text(name, { rule: 'code', ...extra });
const choice = (name: string, options: string[], extra: Partial<Field> = {}): Field =>
  text(name, { kind: 'select', options, initial: options[0]!, ...extra });
const entity = (name: string, source: Field['source'], extra: Partial<Field> = {}): Field =>
  text(name, { kind: 'entity', rule: 'uuid', source, ...extra });
const id = (name = 'id'): Field => text(name, { rule: 'uuid', param: true });
const optional = (name: string, extra: Partial<Field> = {}): Field =>
  text(name, { optional: true, ...extra });
const codes = (name: string, extra: Partial<Field> = {}): Field =>
  text(name, { kind: 'codes', initial: [], ...extra });
const date = (name: string, extra: Partial<Field> = {}): Field =>
  optional(name, { kind: 'datetime', ...extra });
const op = (
  key: string,
  label: string,
  method: Operation['method'],
  path: string,
  permission: string,
  fields: Field[] = [],
  extra: Partial<Operation> = {},
): Operation => ({
  key,
  label,
  method,
  path,
  permission,
  fields: fields.map((f) =>
    f.param && f.name === 'id'
      ? {
          ...f,
          kind: 'entity' as const,
          path: '/core/privacy/work-items/' + key.split('.')[0],
          permission: key.startsWith('erasure.')
            ? 'core.privacy.read'
            : key.startsWith('incident.')
              ? 'core.privacy.incidents.read'
              : key.startsWith('representation.')
                ? 'core.privacy.representations.manage'
                : undefined,
        }
      : f,
  ),
  ...extra,
});
const personFields: Field[] = [
  text('givenName', { max: 120 }),
  text('familyName', { max: 120 }),
  optional('middleName', { nullable: true, max: 120 }),
  optional('displayName', { nullable: true, max: 255 }),
  optional('birthDate', { kind: 'date', nullable: true }),
];
const roleFields = [
  code('code'),
  text('name', { max: 160 }),
  optional('description', { nullable: true, max: 1000 }),
];
const roleProtected = (r: Record<string, unknown>) => r.isSystem === true || r.isProtected === true;
export const coreOperations: Operation[] = [
  op(
    'organization.create',
    'admin.create',
    'POST',
    '/core/organizations',
    'core.organizations.manage',
    [code('code'), text('name', { max: 255 })],
  ),
  op(
    'organization.members',
    'admin.members',
    'GET',
    '/core/organizations/{id}/members',
    'core.organization_memberships.read',
  ),
  op(
    'organization.add',
    'admin.addMember',
    'POST',
    '/core/organizations/{id}/members',
    'core.organization_memberships.manage',
    [entity('personId', 'persons')],
  ),
  op(
    'organization.leave',
    'admin.removeMember',
    'POST',
    '/core/organizations/{id}/members/{personId}/leave',
    'core.organization_memberships.manage',
    [
      entity('personId', 'persons', {
        param: true,
        path: '/core/organizations/{id}/members',
        permission: 'core.organization_memberships.read',
      }),
    ],
    { danger: true },
  ),
  op('person.create', 'admin.create', 'POST', '/core/persons', 'core.persons.manage', personFields),
  op('person.detail', 'admin.details', 'GET', '/core/persons/{id}', 'core.persons.read'),
  op(
    'person.update',
    'admin.update',
    'PATCH',
    '/core/persons/{id}',
    'core.persons.manage',
    personFields,
    { prepare: '/core/persons/{id}' },
  ),
  op(
    'person.archive',
    'admin.archive',
    'POST',
    '/core/persons/{id}/archive',
    'core.persons.manage',
    [],
    { danger: true },
  ),
  op(
    'person.contacts',
    'admin.contacts',
    'GET',
    '/core/persons/{id}/contacts',
    'core.persons.read',
  ),
  op(
    'person.contact',
    'admin.addContact',
    'POST',
    '/core/persons/{id}/contacts',
    'core.persons.manage',
    [
      choice('contactType', ['email', 'phone']),
      text('value', { max: 320 }),
      optional('label', { nullable: true, max: 80 }),
      text('isPrimary', { kind: 'boolean' }),
    ],
  ),
  op('account.create', 'admin.create', 'POST', '/core/accounts', 'core.accounts.manage', [
    choice('accountType', ['human', 'service', 'system']),
    entity('personId', 'persons', { when: (d) => d.accountType === 'human' }),
    optional('label', { nullable: true, max: 160 }),
  ]),
  op('account.detail', 'admin.details', 'GET', '/core/accounts/{id}', 'core.accounts.read'),
  ...(['approve', 'disable', 'enable', 'archive'] as const).map((action) =>
    op(
      'account.' + action,
      'admin.' + action,
      'POST',
      `/core/accounts/{id}/${action}`,
      'core.accounts.manage',
      [],
      {
        danger: action !== 'enable',
        blocked: (r) =>
          action === 'approve'
            ? r.status !== 'pending_approval'
            : action === 'disable'
              ? r.status !== 'active'
              : action === 'enable'
                ? r.status !== 'disabled'
                : r.status === 'archived',
      },
    ),
  ),
  op(
    'account.invite',
    'admin.invite',
    'POST',
    '/core/accounts/{id}/invite',
    'core.accounts.manage',
    [text('email', { kind: 'email', max: 320 })],
  ),
  op(
    'credential.list',
    'admin.serviceCredentials',
    'GET',
    '/core/accounts/{id}/service-credentials',
    'core.service_credentials.read',
    [],
    { blocked: (r) => r.accountType === 'human' },
  ),
  op(
    'credential.create',
    'admin.createCredential',
    'POST',
    '/core/accounts/{id}/service-credentials',
    'core.service_credentials.manage',
    [codes('scopes'), date('expiresAt', { nullable: true })],
    { blocked: (r) => r.accountType === 'human' },
  ),
  op(
    'credential.rotate',
    'admin.rotateCredential',
    'POST',
    '/core/accounts/{id}/service-credentials/{credentialId}/rotate',
    'core.service_credentials.manage',
    [
      text('credentialId', {
        kind: 'entity',
        rule: 'uuid',
        param: true,
        path: '/core/accounts/{id}/service-credentials',
        permission: 'core.service_credentials.read',
      }),
    ],
    { danger: true, blocked: (r) => r.accountType === 'human' },
  ),
  op(
    'credential.revoke',
    'admin.revokeCredential',
    'POST',
    '/core/accounts/{id}/service-credentials/{credentialId}/revoke',
    'core.service_credentials.manage',
    [
      text('credentialId', {
        kind: 'entity',
        rule: 'uuid',
        param: true,
        path: '/core/accounts/{id}/service-credentials',
        permission: 'core.service_credentials.read',
      }),
    ],
    { danger: true, blocked: (r) => r.accountType === 'human' },
  ),
  op(
    'account.roles',
    'admin.accountRoles',
    'GET',
    '/core/accounts/{accountId}/roles',
    'core.rbac.manage',
    [entity('accountId', 'accounts', { param: true })],
  ),
  op(
    'role.assign',
    'admin.assignRole',
    'POST',
    '/core/accounts/{accountId}/roles/{roleId}',
    'core.rbac.manage',
    [entity('accountId', 'accounts', { param: true }), entity('roleId', 'roles', { param: true })],
    { danger: true },
  ),
  op(
    'role.revoke',
    'admin.revokeRole',
    'DELETE',
    '/core/accounts/{accountId}/roles/{roleId}',
    'core.rbac.manage',
    [entity('accountId', 'accounts', { param: true }), entity('roleId', 'roles', { param: true })],
    { danger: true },
  ),
  op('role.create', 'admin.create', 'POST', '/core/roles', 'core.rbac.manage', roleFields),
  op('role.update', 'admin.update', 'PATCH', '/core/roles/{id}', 'core.rbac.manage', roleFields, {
    blocked: roleProtected,
  }),
  op('role.delete', 'admin.delete', 'DELETE', '/core/roles/{id}', 'core.rbac.manage', [], {
    danger: true,
    blocked: roleProtected,
  }),
  op(
    'role.permissions',
    'admin.permissions',
    'GET',
    '/core/roles/{id}/permissions',
    'core.rbac.manage',
  ),
  op(
    'role.replace',
    'admin.replacePermissions',
    'PUT',
    '/core/roles/{id}/permissions',
    'core.rbac.manage',
    [
      text('permissions', {
        kind: 'multi',
        source: 'permissions',
        optional: true,
        initial: [],
        max: 1000,
      }),
    ],
    { prepare: '/core/roles/{id}/permissions', danger: true, blocked: roleProtected },
  ),
  op(
    'hold.create',
    'admin.create',
    'POST',
    '/core/privacy/legal-holds',
    'core.privacy.legal_holds.manage',
    [
      choice('scopeType', ['subject', 'purpose', 'resource']),
      entity('subjectPersonId', 'persons', {
        optional: true,
        when: (d) => d.scopeType !== 'resource',
      }),
      code('purposeCode', { when: (d) => d.scopeType === 'purpose' }),
      code('resourceType', { when: (d) => d.scopeType === 'resource' }),
      text('resourceId', { rule: 'uuid', when: (d) => d.scopeType === 'resource' }),
      code('reasonCode'),
      code('sourceType', { optional: true }),
      optional('sourceReference', { max: 160 }),
      date('expiresAt'),
    ],
  ),
  op(
    'hold.detail',
    'admin.details',
    'GET',
    '/core/privacy/legal-holds/{id}',
    'core.privacy.legal_holds.read',
  ),
  op(
    'hold.release',
    'admin.release',
    'POST',
    '/core/privacy/legal-holds/{id}/release',
    'core.privacy.legal_holds.manage',
    [code('reasonCode', { fromRow: false })],
    { danger: true, blocked: (r) => r.releasedAt != null },
  ),
  op(
    'representation.create',
    'admin.declareRepresentation',
    'POST',
    '/core/privacy/representations',
    'core.privacy.representations.manage',
    [
      entity('subjectPersonId', 'persons'),
      entity('representativePersonId', 'persons'),
      code('authorityType'),
      text('validFrom', { kind: 'datetime' }),
      date('validUntil'),
      code('evidenceType', { optional: true }),
      optional('evidenceReference'),
      optional('evidenceSha256', { rule: 'sha256' }),
      codes('scopes'),
    ],
  ),
  op(
    'representation.verify',
    'admin.verifyRepresentation',
    'POST',
    '/core/privacy/representations/{id}/verify',
    'core.privacy.representations.manage',
    [id()],
  ),
  op(
    'representation.revoke',
    'admin.revokeRepresentation',
    'POST',
    '/core/privacy/representations/{id}/revoke',
    'core.privacy.representations.manage',
    [id(), code('reasonCode')],
    { danger: true },
  ),
  op(
    'erasure.create',
    'admin.createErasureCase',
    'POST',
    '/core/privacy/erasure-cases',
    'core.privacy.erasure.manage',
    [
      entity('subjectPersonId', 'persons'),
      code('reasonCode'),
      optional('sourceRequestId', { rule: 'uuid' }),
      date('deadlineAt'),
      choice('deadlineCalendarType', ['calendar_days', 'working_days', 'hours'], {
        optional: true,
        initial: '',
      }),
    ],
  ),
  op(
    'erasure.detail',
    'admin.getErasureCase',
    'GET',
    '/core/privacy/erasure-cases/{id}',
    'core.privacy.read',
    [id()],
  ),
  op(
    'erasure.execute',
    'admin.executeErasureCase',
    'POST',
    '/core/privacy/erasure-cases/{id}/execute',
    'core.privacy.erasure.manage',
    [id(), text('limit', { kind: 'number', min: 1, max: 100, initial: 25 })],
    { danger: true },
  ),
  op(
    'erasure.evidence',
    'admin.erasureEvidence',
    'GET',
    '/core/privacy/erasure-cases/{id}/evidence',
    'core.privacy.read',
    [id()],
  ),
  op(
    'erasure.attest',
    'admin.attestErasure',
    'POST',
    '/core/privacy/erasure-cases/{id}/evidence/attest',
    'core.privacy.erasure.manage',
    [
      id(),
      text('evidenceId', { rule: 'uuid' }),
      text('signedDocumentReference'),
      text('signatureReference'),
    ],
    { danger: true },
  ),
  op(
    'incident.create',
    'admin.detectIncident',
    'POST',
    '/core/privacy/incidents',
    'core.privacy.incidents.manage',
    [
      code('incidentType'),
      code('reasonCode'),
      codes('categoryCodes'),
      text('estimatedSubjects', { kind: 'number', optional: true, min: 0 }),
      codes('subjectPersonIds', { rule: 'uuid', optional: true }),
    ],
  ),
  op(
    'incident.detail',
    'admin.getIncident',
    'GET',
    '/core/privacy/incidents/{id}',
    'core.privacy.incidents.read',
    [id()],
  ),
  op(
    'incident.transition',
    'admin.transitionIncident',
    'POST',
    '/core/privacy/incidents/{id}/transition',
    'core.privacy.incidents.manage',
    [id(), choice('status', ['investigating', 'contained', 'resolved'], { fromRow: false }), code('reasonCode')],
    { danger: true },
  ),
  op(
    'incident.notification',
    'admin.recordIncidentNotification',
    'POST',
    '/core/privacy/incidents/{id}/notification-record',
    'core.privacy.incidents.manage',
    [id(), choice('kind', ['initial', 'investigation'], { fromRow: false }), code('referenceCode')],
  ),
  op(
    'distribution.create',
    'admin.grantDistributionConsent',
    'POST',
    '/core/privacy/distribution-consents',
    '',
    [
      entity('subjectPersonId', 'persons'),
      code('purposeCode'),
      codes('categoryCodes'),
      codes('fieldCodes'),
      codes('conditionCodes', { optional: true }),
      codes('restrictionCodes', { optional: true }),
      optional('representationId', { rule: 'uuid' }),
    ],
    { danger: true },
  ),
  op(
    'distribution.withdraw',
    'admin.withdrawDistributionConsent',
    'POST',
    '/core/privacy/distribution-consents/{id}/withdraw',
    '',
    [id()],
    { danger: true },
  ),
];
export interface Resource {
  key: string;
  title: string;
  path: string;
  permission: string;
  columns: string[];
  paginated: boolean;
  create?: string;
  actions: string[];
  global?: string[];
}
export const coreResources: Resource[] = [
  {
    key: 'organizations',
    title: 'admin.organizations',
    path: '/core/organizations',
    permission: 'core.organizations.read',
    columns: ['name', 'code', 'createdAt'],
    paginated: false,
    create: 'organization.create',
    actions: ['organization.members', 'organization.add', 'organization.leave'],
  },
  {
    key: 'persons',
    title: 'admin.persons',
    path: '/core/persons',
    permission: 'core.persons.read',
    columns: ['displayName', 'givenName', 'familyName', 'status'],
    paginated: true,
    create: 'person.create',
    actions: [
      'person.detail',
      'person.update',
      'person.archive',
      'person.contacts',
      'person.contact',
    ],
  },
  {
    key: 'accounts',
    title: 'admin.accounts',
    path: '/core/accounts',
    permission: 'core.accounts.read',
    columns: ['label', 'accountType', 'status', 'personId'],
    paginated: true,
    create: 'account.create',
    actions: [
      'account.detail',
      'account.approve',
      'account.disable',
      'account.enable',
      'account.archive',
      'account.invite',
      'credential.list',
      'credential.create',
      'credential.rotate',
      'credential.revoke',
    ],
    global: ['account.roles', 'role.assign', 'role.revoke'],
  },
  {
    key: 'roles',
    title: 'admin.rbac',
    path: '/core/roles',
    permission: 'core.rbac.read',
    columns: ['name', 'code', 'isSystem', 'isProtected'],
    paginated: true,
    create: 'role.create',
    actions: ['role.update', 'role.delete', 'role.permissions', 'role.replace'],
    global: ['account.roles', 'role.assign', 'role.revoke'],
  },
  {
    key: 'permissions',
    title: 'admin.permissions',
    path: '/core/permissions',
    permission: 'core.rbac.read',
    columns: ['code', 'module', 'description'],
    paginated: true,
    actions: [],
  },
  {
    key: 'audit',
    title: 'admin.audit',
    path: '/core/audit-events',
    permission: '',
    columns: ['action', 'actorType', 'success', 'errorCode', 'occurredAt'],
    paginated: true,
    actions: [],
  },
  {
    key: 'legalHolds',
    title: 'admin.legalHolds',
    path: '/core/privacy/legal-holds',
    permission: 'core.privacy.legal_holds.read',
    columns: ['scopeType', 'reasonCode', 'subjectPersonId', 'releasedAt'],
    paginated: false,
    create: 'hold.create',
    actions: ['hold.detail', 'hold.release'],
  },
  {
    key: 'representations',
    title: 'workbench.groups.representation',
    path: '/core/privacy/work-items/representation',
    permission: 'core.privacy.representations.manage',
    columns: ['name', 'status', 'id'],
    paginated: true,
    create: 'representation.create',
    actions: ['representation.verify', 'representation.revoke'],
  },
  {
    key: 'erasureCases',
    title: 'workbench.groups.erasure',
    path: '/core/privacy/work-items/erasure',
    permission: 'core.privacy.read',
    columns: ['name', 'status', 'id'],
    paginated: true,
    create: 'erasure.create',
    actions: ['erasure.detail', 'erasure.execute', 'erasure.evidence', 'erasure.attest'],
  },
  {
    key: 'privacyIncidents',
    title: 'workbench.groups.incident',
    path: '/core/privacy/work-items/incident',
    permission: 'core.privacy.incidents.read',
    columns: ['name', 'status', 'id'],
    paginated: true,
    create: 'incident.create',
    actions: ['incident.detail', 'incident.transition', 'incident.notification'],
  },
  {
    key: 'distributionConsents',
    title: 'workbench.groups.distribution',
    path: '/core/privacy/work-items/distribution',
    permission: '',
    columns: ['name', 'status', 'id'],
    paginated: true,
    actions: ['distribution.withdraw'],
  },
];
