import { describe, expect, it } from 'vitest';
import { useForm } from './useForm';
import { email, password, required } from '@/shared/validation/rules';

describe('useForm', () => {
  it('reports required, email and password validation errors', () => {
    const form = useForm({
      initial: { email: '', password: '' },
      fields: {
        email: { id: 'email', rules: [email] },
        password: { id: 'password', rules: [password] },
      },
    });

    expect(form.validate()).toBe(false);
    expect(form.errors.email).toBe('validation.required');
    expect(form.errors.password).toBe('validation.required');

    form.values.email = 'not-an-email';
    form.values.password = 'short';
    expect(form.validate()).toBe(false);
    expect(form.errors.email).toBe('validation.emailInvalid');
    expect(form.errors.password).toBe('validation.passwordMin');

    form.values.email = 'person@example.test';
    form.values.password = 'correct horse battery';
    expect(form.validate()).toBe(true);
    expect(form.errors.email).toBeNull();
    expect(form.errors.password).toBeNull();
  });

  it('focuses the first invalid field', () => {
    const input = document.createElement('input');
    input.id = 'email';
    document.body.appendChild(input);

    const form = useForm({
      initial: { email: '', password: '' },
      fields: {
        email: { id: 'email', rules: [email] },
        password: { id: 'password', rules: [required] },
      },
    });

    form.validate();
    expect(document.activeElement).toBe(input);
  });

  it('preserves entered values on error and resets on demand', () => {
    const form = useForm({
      initial: { email: '' },
      fields: { email: { id: 'email', rules: [email] } },
    });

    form.values.email = 'broken';
    form.validate();
    expect(form.values.email).toBe('broken');

    form.reset();
    expect(form.values.email).toBe('');
    expect(form.errors.email).toBeNull();
  });
});
