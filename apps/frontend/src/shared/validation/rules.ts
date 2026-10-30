export type Rule = (value: string) => string | null;

const CONTROL_CHARS = /[\u0000-\u001F\u007F]/;
const EMAIL_SHAPE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MACHINE_CODE = /^[a-z0-9]+(?:[._-][a-z0-9]+)*$/;

export const required: Rule = (value) => (value.trim() === '' ? 'validation.required' : null);

export const email: Rule = (value) => {
  const trimmed = value.trim();
  if (trimmed === '') {
    return 'validation.required';
  }
  if (trimmed.length > 320 || CONTROL_CHARS.test(trimmed) || !EMAIL_SHAPE.test(trimmed)) {
    return 'validation.emailInvalid';
  }
  return null;
};

export const password: Rule = (value) => {
  if (value === '') {
    return 'validation.required';
  }
  if (CONTROL_CHARS.test(value)) {
    return 'validation.passwordChars';
  }
  if (value.length < 12) {
    return 'validation.passwordMin';
  }
  if (value.length > 4096) {
    return 'validation.passwordMax';
  }
  return null;
};

export function runRules(value: string, rules: Rule[]): string | null {
  for (const rule of rules) {
    const error = rule(value);
    if (error !== null) {
      return error;
    }
  }
  return null;
}

/** Canonical machine code, matching the Control Plane identifier policy. */
export function machineCode(maxLength = 120): Rule {
  return (value) => {
    if (value === '') {
      return 'validation.required';
    }
    if (value.length > maxLength || !MACHINE_CODE.test(value)) {
      return 'validation.codeInvalid';
    }
    return null;
  };
}

/** Human-readable label, matching the Control Plane text policy. */
export function label(maxLength: number, options: { optional?: boolean } = {}): Rule {
  return (value) => {
    const trimmed = value.trim();
    if (trimmed === '') {
      return options.optional === true ? null : 'validation.required';
    }
    if (CONTROL_CHARS.test(value)) {
      return 'validation.textInvalid';
    }
    if ([...trimmed].length > maxLength) {
      return 'validation.tooLong';
    }
    return null;
  };
}
