import type { Rule } from '@/shared/validation/rules';

/** Core uses mb_strlen and a maximum of 1024 characters for required password changes. */
export const corePassword: Rule = (value) => {
  const length = [...value].length;
  if (length === 0) return 'validation.required';
  if (length < 12 || length > 1024) return 'coreAuth.passwordPolicy';
  return null;
};
