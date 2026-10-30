import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildTokensCss } from './generate-tokens.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outputPath = join(root, 'src', 'shared', 'styles', 'tokens.css');

const expected = buildTokensCss();
let actual = '';
try {
  actual = readFileSync(outputPath, 'utf8');
} catch {
  console.error('check:tokens FAILED — src/shared/styles/tokens.css is missing. Run: npm run generate:tokens');
  process.exit(1);
}

if (actual !== expected) {
  console.error(
    'check:tokens FAILED — tokens.css does not match design/design-tokens.json.\n' +
      'Do not edit tokens.css by hand. Run: npm run generate:tokens',
  );
  process.exit(1);
}

console.log('check:tokens OK — tokens.css matches the canonical design tokens.');
