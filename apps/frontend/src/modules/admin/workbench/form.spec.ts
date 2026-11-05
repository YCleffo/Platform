import { describe, expect, it } from 'vitest';
import { initialData, serialize, operationPath, rowsFrom, FormValidationError } from './form';
import { coreOperations, coreResources } from './catalog';
const id = '01900000-0000-7000-8000-000000000001';
const operation = (key: string) => coreOperations.find((op) => op.key === key)!;
describe('Core workflow request contracts', () => {
  it('covers the 53 previously generic operations with structured forms and lists', () => {
    expect(coreOperations.length + coreResources.length).toBe(53);
    expect(new Set(coreOperations.map((o) => o.method + ' ' + o.path)).size).toBe(
      coreOperations.length,
    );
  });
  it('creates an erasure case with sourceRequestId, not rejected legacy keys', () => {
    const op = operation('erasure.create');
    const body = serialize(op.fields, {
      subjectPersonId: id,
      reasonCode: 'request',
      sourceRequestId: id,
      deadlineAt: '2026-12-01T12:30:00Z',
      deadlineCalendarType: 'calendar_days',
      requestId: 'untrusted',
      scopeType: 'subject',
    });
    expect(body).toEqual({
      subjectPersonId: id,
      reasonCode: 'request',
      sourceRequestId: id,
      deadlineAt: '2026-12-01T12:30:00Z',
      deadlineCalendarType: 'calendar_days',
    });
  });
  it('uses status instead of toStatus for the actual incident API', () => {
    const op = operation('incident.transition');
    const data = { id, status: 'contained', reasonCode: 'investigated' };
    expect(operationPath(op, {}, data)).toBe('/core/privacy/incidents/' + id + '/transition');
    expect(serialize(op.fields, data)).toEqual({ status: 'contained', reasonCode: 'investigated' });
  });
  it('keeps protected roles immutable and does not invent lifecycle permissions', () => {
    expect(operation('role.update').blocked!({ isSystem: true })).toBe(true);
    expect(operation('role.replace').blocked!({ isProtected: true })).toBe(true);
    expect(operation('account.disable').permission).toBe('core.accounts.manage');
    expect(operation('account.approve').blocked!({ status: 'active' })).toBe(true);
    expect(operation('account.enable').blocked!({ status: 'disabled' })).toBe(false);
    expect(operation('person.update').permission).toBe('core.persons.manage');
  });
  it('retains unchanged nullable person values and false booleans', () => {
    const op = operation('person.update');
    expect(
      serialize(
        op.fields,
        initialData(op.fields, {
          givenName: 'Alex',
          familyName: 'Smith',
          middleName: null,
          displayName: null,
          birthDate: null,
        }),
      ),
    ).toEqual({
      givenName: 'Alex',
      familyName: 'Smith',
      middleName: null,
      displayName: null,
      birthDate: null,
    });
    expect(
      serialize(operation('person.contact').fields, {
        contactType: 'email',
        value: 'a@example.test',
        label: '',
        isPrimary: false,
      }),
    ).toEqual({ contactType: 'email', value: 'a@example.test', label: null, isPrimary: false });
  });
  it('does not mix path-only identifiers into a request body', () => {
    const op = operation('role.assign');
    expect(serialize(op.fields, { accountId: id, roleId: id })).toEqual({});
    expect(operationPath(op, {}, { accountId: id, roleId: id })).toBe(
      '/core/accounts/' + id + '/roles/' + id,
    );
  });
  it('permits an explicit empty permission replacement', () => {
    const op = operation('role.replace');
    expect(serialize(op.fields, { permissions: [] })).toEqual({ permissions: [] });
  });
  it.each(['../secrets', 'not-a-uuid', id + '/extra'])('rejects path injection %s', (value) => {
    expect(() => operationPath(operation('person.detail'), { id: value }, {})).toThrow(
      FormValidationError,
    );
  });
  it.each([
    ['limit', 0],
    ['limit', 101],
    ['limit', 1.5],
    ['limit', 'oops'],
  ])('rejects invalid batch %s=%s', (key, value) => {
    expect(() => serialize(operation('erasure.execute').fields, { id, [key]: value })).toThrow(
      FormValidationError,
    );
  });
  it('drops hidden hold scope fields and validates machine codes', () => {
    const op = operation('hold.create');
    expect(
      serialize(op.fields, {
        scopeType: 'resource',
        resourceType: 'file',
        resourceId: id,
        subjectPersonId: id,
        purposeCode: 'billing',
        reasonCode: 'litigation',
      }),
    ).toEqual({
      scopeType: 'resource',
      resourceType: 'file',
      resourceId: id,
      reasonCode: 'litigation',
    });
    expect(() =>
      serialize(op.fields, { scopeType: 'subject', subjectPersonId: id, reasonCode: 'bad code' }),
    ).toThrow();
  });
  it('rejects invalid date normalization, duplicate lists and unsupported enums', () => {
    expect(() =>
      serialize(operation('person.create').fields, {
        givenName: 'A',
        familyName: 'B',
        birthDate: '2026-02-31',
      }),
    ).toThrow();
    expect(() =>
      serialize(operation('incident.create').fields, {
        incidentType: 'access',
        reasonCode: 'detected',
        categoryCodes: ['contact', 'contact'],
      }),
    ).toThrow();
    expect(() =>
      serialize(operation('incident.transition').fields, {
        id,
        status: 'anything',
        reasonCode: 'x',
      }),
    ).toThrow();
  });
  it('rejects a malformed list instead of showing a false empty result', () => {
    expect(() => rowsFrom({ message: 'wrong server' })).toThrow();
    expect(rowsFrom({ items: [{ id }], pagination: { total: 100 } }).total).toBe(100);
  });
});
