import type { Field, Operation } from '@/modules/admin/workbench/form';
const field = (name: string, extra: Partial<Field> = {}): Field => ({ name, ...extra });
const choice = (name: string, options: string[], extra: Partial<Field> = {}): Field =>
  field(name, { kind: 'select', options, initial: options[0]!, ...extra });
const optional = (name: string, extra: Partial<Field> = {}): Field =>
  field(name, { optional: true, ...extra });
const references: Field[] = [
  field('externalTransactionReference', { max: 200 }),
  field('documentReference', { rule: 'urn', max: 500 }),
  field('documentSha256', { rule: 'sha256' }),
];
export const operatorOperations: Operation[] = [
  {
    key: 'commercial.intake',
    label: 'operator.intake',
    permission: 'privacy',
    method: 'POST',
    path: '/privacy/operator/requests/intake',
    fields: [
      choice('subjectKind', ['billing_contact', 'financial_snapshot', 'other']),
      field('subjectReference', { max: 500 }),
      field('identityEvidenceReference', { rule: 'urn', max: 500 }),
      optional('billingProfileId', { rule: 'uuid', when: (d) => d.subjectKind !== 'other' }),
      optional('invoiceId', { rule: 'uuid', when: (d) => d.subjectKind === 'financial_snapshot' }),
      optional('receiptId', { rule: 'uuid', when: (d) => d.subjectKind === 'financial_snapshot' }),
      choice('requestType', ['access', 'processing_information', 'correction', 'erasure']),
      choice('sourceChannel', ['paper', 'email', 'signed_electronic_document']),
      field('receivedAt', { kind: 'datetime' }),
    ],
  },
  {
    key: 'commercial.resolve',
    label: 'operator.resolve',
    permission: 'privacy',
    method: 'POST',
    path: '/privacy/operator/requests/{id}/resolve',
    danger: true,
    fields: [
      optional('contactName', { max: 500 }),
      optional('contactEmail', { kind: 'email', max: 320 }),
      optional('responseEvidenceReference', { rule: 'urn', max: 500 }),
    ],
  },
  {
    key: 'commercial.detect',
    label: 'operator.detect',
    permission: 'privacy',
    method: 'POST',
    path: '/privacy/operator/incidents',
    fields: [
      field('detectedAt', { kind: 'datetime' }),
      field('categories', { kind: 'codes', initial: [] }),
      optional('estimatedSubjects', { kind: 'number', min: 0 }),
    ],
  },
  {
    key: 'commercial.event',
    label: 'operator.event',
    permission: 'privacy',
    method: 'POST',
    path: '/privacy/operator/incidents/{id}/events',
    fields: [
      choice('eventCode', [
        'containment',
        'investigation',
        'initial_notification',
        'investigation_notification',
      ]),
      field('evidenceReference', { rule: 'urn', max: 500 }),
    ],
  },
  {
    key: 'commercial.close',
    label: 'operator.close',
    permission: 'privacy',
    method: 'POST',
    path: '/privacy/operator/incidents/{id}/close',
    fields: [],
    danger: true,
  },
  {
    key: 'commercial.current',
    label: 'operator.current',
    permission: 'finance',
    method: 'GET',
    path: '/finance/plan-change-compensations/{id}',
    fields: [],
  },
  {
    key: 'commercial.propose',
    label: 'operator.propose',
    permission: 'finance',
    method: 'POST',
    path: '/finance/plan-change-compensations/{id}',
    fields: references,
    danger: true,
  },
  {
    key: 'commercial.approve',
    label: 'operator.approve',
    permission: 'finance',
    method: 'POST',
    path: '/finance/plan-change-compensations/attestations/{attestationId}/approve',
    fields: references.map((f) => ({ ...f, readOnly: true })),
    danger: true,
  },
  {
    key: 'commercial.reject',
    label: 'operator.reject',
    permission: 'finance',
    method: 'POST',
    path: '/finance/plan-change-compensations/attestations/{attestationId}/reject',
    fields: [
      ...references.map((f) => ({ ...f, readOnly: true })),
      field('rejectionReason', { max: 500 }),
    ],
    danger: true,
  },
];
