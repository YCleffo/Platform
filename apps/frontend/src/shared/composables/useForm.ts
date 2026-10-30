import { reactive } from 'vue';
import { runRules, type Rule } from '@/shared/validation/rules';

export interface FieldSpec {
  /** DOM id of the control, used to focus the first invalid field. */
  id: string;
  rules: Rule[];
}

export interface FormConfig<T extends Record<string, string>> {
  initial: T;
  fields: { [K in keyof T]: FieldSpec };
}

export function useForm<T extends Record<string, string>>(config: FormConfig<T>) {
  const keys = Object.keys(config.fields) as (keyof T)[];
  const values = reactive<Record<string, string>>({ ...config.initial });
  const errors = reactive<Record<string, string | null>>({});
  for (const key of keys) {
    errors[key as string] = null;
  }

  function validateField(key: keyof T): void {
    const name = key as string;
    errors[name] = runRules(values[name] ?? '', config.fields[key].rules);
  }

  function validate(): boolean {
    let firstInvalid: keyof T | null = null;
    for (const key of keys) {
      validateField(key);
      if (errors[key as string] !== null && firstInvalid === null) {
        firstInvalid = key;
      }
    }
    if (firstInvalid !== null) {
      document.getElementById(config.fields[firstInvalid].id)?.focus();
      return false;
    }
    return true;
  }

  function reset(): void {
    for (const key of keys) {
      const name = key as string;
      values[name] = config.initial[key] ?? '';
      errors[name] = null;
    }
  }

  return {
    values: values as T,
    errors: errors as Record<keyof T, string | null>,
    validate,
    validateField,
    reset,
  };
}
