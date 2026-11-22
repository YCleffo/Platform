import type { EducationContext } from './api';

export function isEducationActivationTarget(path: string): boolean {
  return /^\/(parent\/activate|education\/student-activate)(?:[?#]|$)/.test(path);
}

export function educationContexts(
  context: EducationContext,
): Array<{ kind: 'teacher' | 'student' | 'parent'; path: string }> {
  const choices: Array<{ kind: 'teacher' | 'student' | 'parent'; path: string }> = [];
  if (context.organizations.some((org) => org.teacher))
    choices.push({ kind: 'teacher', path: '/today' });
  if (context.organizations.some((org) => org.student))
    choices.push({ kind: 'student', path: '/student/home' });
  if (context.organizations.some((org) => org.parent))
    choices.push({ kind: 'parent', path: '/parent/home' });
  return choices;
}

export function educationLanding(context: EducationContext): string {
  const choices = educationContexts(context);
  return choices.length === 1
    ? choices[0]!.path
    : choices.length > 1
      ? '/education/contexts'
      : '/parent/activate';
}
