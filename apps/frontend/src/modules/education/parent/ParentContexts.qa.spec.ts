import { describe, expect, it } from 'vitest';
import type { EducationContext } from '../api';
import { educationContexts, educationLanding } from '../contexts';

const context = (organizations: EducationContext['organizations']): EducationContext => ({ organizations, personId: 'parent-person', displayName: 'Parent', locale: 'en-US', timezone: 'UTC' });

describe('Education role context selection', () => {
  it('opens the parent home when parent is the only authorized Education context', () => {
    expect(educationLanding(context([{ id: 'school', name: 'School', teacher: false, student: false, parent: true }]))).toBe('/parent/home');
  });

  it('preserves a choice between teaching and parenting across organizations', () => {
    const roles = context([{ id: 'teaching-school', name: 'Teaching', teacher: true }, { id: 'child-school', name: 'Parenting', parent: true }]);
    expect(educationLanding(roles)).toBe('/education/contexts');
    expect(educationContexts(roles)).toEqual([{ kind: 'teacher', path: '/today' }, { kind: 'parent', path: '/parent/home' }]);
  });

  it('never invents a teacher or student context for an unlinked parent', () => {
    const roles = context([]);
    expect(educationContexts(roles)).toEqual([]);
    expect(educationLanding(roles)).toBe('/parent/activate');
  });

  it('drops the teaching context when a refreshed server context removes that role', () => {
    const prior = context([{ id: 'school', name: 'School', teacher: true, parent: true }]);
    expect(educationLanding(prior)).toBe('/education/contexts');
    const current = context([{ id: 'school', name: 'School', teacher: false, parent: true }]);
    expect(educationLanding(current)).toBe('/parent/home');
    expect(educationContexts(current).map(choice => choice.kind)).toEqual(['parent']);
  });
});
