import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const frontendDir = path.resolve(fileURLToPath(import.meta.url), '..', '..');

try {
  execSync('npx vitest run src/shared/i18n/i18n-parity.spec.ts', {
    cwd: frontendDir,
    stdio: 'inherit',
    shell: true,
  });
  console.log('CHECK I18N OK — 100% RU/EN dictionary parity and Vue template coverage verified.');
} catch (error) {
  process.exit(error.status ?? 1);
}
