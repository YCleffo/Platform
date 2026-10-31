import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { parse as parseVue } from '@vue/compiler-sfc';
import postcss from 'postcss';

const root = resolve('src');
const definitions = new Set();
const references = [];
const problems = [];
for (const relative of readdirSync(root, { recursive: true })) {
  if (!/\.(css|vue)$/.test(relative)) continue;
  const path = resolve(root, relative);
  const source = readFileSync(path, 'utf8');
  const styles = relative.endsWith('.vue') ? parseVue(source).descriptor.styles.map(style => style.content) : [source];
  for (const css of styles) {
    postcss.parse(css, { from: path }).walkDecls(decl => {
      if (decl.prop.startsWith('--')) definitions.add(decl.prop);
      for (const match of decl.value.matchAll(/var\(\s*(--[\w-]+)\s*([,)])/g)) {
        if (match[2] === ')') references.push({ name: match[1], file: relative });
      }
      if (/^(transition|animation)(?:-|$)/.test(decl.prop) && !relative.replaceAll('\\', '/').endsWith('styles/appearance.css')) {
        if (/\b\d*\.?\d+m?s\b/.test(decl.value)) problems.push(`${relative}: hardcoded motion timing: ${decl.toString()}`);
        if (decl.prop === 'transition' && decl.value !== 'none' && !decl.value.includes('var(--theme-transition)')) {
          problems.push(`${relative}: color transitions must include --theme-transition`);
        }
      }
    });
  }
}
for (const { name, file } of references) if (!definitions.has(name)) problems.push(`${file}: undefined ${name}`);
if (problems.length) { console.error([...new Set(problems)].join('\n')); process.exit(1); }
console.log('STYLE CHECKS PASSED: defined variables, shared color transitions and centralized motion timings.');
