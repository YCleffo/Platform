import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const json = JSON.parse(readFileSync(join(root, 'стили.json'), 'utf8'));

const g = json.global;
const colors = g.Colors;
const states = colors['Component States'];
const effects = g['Effect Styles'];
const numbers = g['Number Variables'];

const css = [];
const w = (line) => css.push(line);

const color = (name, value) => w(`  --color-${name}: ${value};`);
const shadow = (name, value) => {
  const { color, x, y, blur, spread } = value;
  w(`  --shadow-${name}: ${x}px ${y}px ${blur}px ${spread}px ${color};`);
};

w('/* AUTO-GENERATED from стили.json. Do not edit by hand. Run: node scripts/generate-tokens.mjs */');

// ---------------------------------------------------------------------------
w('');
w(':root,');
w("[data-theme='light'] {");
w('  /* Primary */');
for (const [step, v] of Object.entries(colors.Primary)) color(`primary-${step}`, v.$value);
w('');
w('  /* Neutral */');
color('app-background', colors.Neutral['App Background'].$value);
color('white', colors.Neutral['White'].$value);
color('surface-secondary', colors.Neutral['Surface Secondary'].$value);
color('surface-hover', colors.Neutral['Surface Hover'].$value);
color('text-primary', colors.Neutral['Text Primary'].$value);
color('text-secondary', colors.Neutral['Text Secondary'].$value);
color('text-muted', colors.Neutral['Text Muted'].$value);
color('text-disabled', colors.Neutral['Text Disabled'].$value);
color('border-default', colors.Neutral['Border Default'].$value);
color('border-strong', colors.Neutral['Border Strong'].$value);
color('overlay-backdrop', colors.Neutral['Overlay Backdrop'].$value);
w('');
w('  /* Surface */');
color('card', colors.Surface['Card'].$value);
color('elevated', colors.Surface['Elevated'].$value);
color('overlay', colors.Surface['Overlay'].$value);
w('');
w('  /* Links */');
color('link', colors.Links['Default'].$value);
color('link-hover', colors.Links['Hover'].$value);
color('link-visited', colors.Links['Visited'].$value);
w('');
w('  /* Status */');
for (const [name, steps] of Object.entries(colors.Status)) {
  const k = name.toLowerCase();
  for (const [step, v] of Object.entries(steps)) color(`${k}-${step}`, v.$value);
}
w('');
w('  /* Domain */');
color('domain-education', colors.Domain['Education'].$value);
color('domain-analytics', colors.Domain['Analytics'].$value);
color('domain-security', colors.Domain['Security'].$value);
color('domain-system', colors.Domain['System'].$value);
w('');
w('  /* Component states (light) */');
color('focus-border', states.Light['Border Focus'].$value);
color('focus-ring', states.Light['Focus Ring'].$value);
color('hover-primary', states.Light['Hover Primary'].$value);
color('pressed-primary', states.Light['Pressed Primary'].$value);
color('disabled-text', states.Light['Disabled Text'].$value);
color('disabled-border', states.Light['Disabled Border'].$value);
color('disabled-surface', states.Light['Disabled Surface'].$value);
color('loading-surface', states.Light['Loading Surface'].$value);
color('loading-accent', states.Light['Loading Accent'].$value);
color('selected-surface', states.Light['Selected Surface'].$value);
color('selected-accent', states.Light['Selected Accent'].$value);
color('error-surface', states.Light['Error Surface'].$value);
color('error-accent', states.Light['Error Accent'].$value);
color('success-surface', states.Light['Success Surface'].$value);
color('success-accent', states.Light['Success Accent'].$value);
w('');
w('  /* Utility */');
color('black', colors.Utility['Black'].$value);
color('primary-glow', colors.Utility['Primary Glow'].$value);
w('');
w('  /* Effects (light) */');
shadow('soft', effects.Light['Shadow Soft'].$value);
shadow('card', effects.Light['Shadow Card'].$value);
shadow('popover', effects.Light['Shadow Popover'].$value);
shadow('modal', effects.Light['Shadow Modal'].$value);
shadow('focus-ring', effects.Light['Focus Ring'].$value);
w('}');

// ---------------------------------------------------------------------------
w('');
w("[data-theme='dark'] {");
const dark = colors.Dark;
color('app-background', dark['Background'].$value);
color('surface', dark['Surface'].$value);
color('surface-secondary', dark['Surface Secondary'].$value);
color('card', dark['Card'].$value);
color('surface-hover', dark['Surface Hover'].$value);
color('selected-surface', dark['Selected Surface'].$value);
color('text-primary', dark['Text Primary'].$value);
color('text-secondary', dark['Text Secondary'].$value);
color('text-muted', dark['Text Muted'].$value);
color('text-disabled', dark['Text Disabled'].$value);
color('border-default', dark['Border Default'].$value);
color('border-strong', dark['Border Strong'].$value);
color('focus-border', dark['Focus Border'].$value);
color('overlay-backdrop', dark['Overlay Backdrop'].$value);
color('link', dark['Link Default'].$value);
color('link-hover', dark['Link Hover'].$value);
color('link-visited', dark['Link Visited'].$value);
w('');
color('hover-primary', states.Dark['Hover Primary'].$value);
color('pressed-primary', states.Dark['Pressed Primary'].$value);
color('disabled-text', states.Dark['Disabled Text'].$value);
color('disabled-border', states.Dark['Disabled Border'].$value);
color('disabled-surface', states.Dark['Disabled Surface'].$value);
color('loading-surface', states.Dark['Loading Surface'].$value);
color('loading-accent', states.Dark['Loading Accent'].$value);
color('selected-accent', states.Dark['Selected Accent'].$value);
color('error-surface', states.Dark['Error Surface'].$value);
color('error-accent', states.Dark['Error Accent'].$value);
color('success-surface', states.Dark['Success Surface'].$value);
color('success-accent', states.Dark['Success Accent'].$value);
color('focus-ring', states.Dark['Focus Ring'].$value);
w('');
const ds = colors['Dark Status'];
for (const [name, v] of Object.entries(ds)) {
  const k = name.toLowerCase();
  color(`${k}-surface`, v['Surface'].$value);
  color(`${k}-text`, v['Text'].$value);
  color(`${k}-border`, v['Border'].$value);
}
w('');
shadow('soft', effects.Dark['Shadow Soft'].$value);
shadow('card', effects.Dark['Shadow Card'].$value);
shadow('popover', effects.Dark['Shadow Popover'].$value);
shadow('modal', effects.Dark['Shadow Modal'].$value);
shadow('focus-ring', effects.Dark['Focus Ring'].$value);
w('}');

// ---------------------------------------------------------------------------
w('');
w(':root {');
w('  /* Spacing */');
for (const [step, v] of Object.entries(numbers.Spacing)) w(`  --space-${step}: ${v.$value}px;`);
w('');
w('  /* Radius */');
const kebab = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
for (const [name, v] of Object.entries(numbers.Radius)) {
  const n = kebab(name);
  if (typeof v.$value === 'number') {
    w(`  --radius-${n}: ${v.$value}px;`);
    continue;
  }
  for (const [step, vv] of Object.entries(v)) {
    w(`  --radius-${n}-${kebab(step)}: ${vv.$value}px;`);
  }
}
w('');
w('  /* Control height + icon size */');
w('  --control-h-s: 32px;');
w('  --control-h-m: 40px;');
w('  --control-h-l: 48px;');
w('  --icon-xs: 14px;');
w('  --icon-s: 16px;');
w('  --icon-m: 20px;');
w('  --icon-l: 24px;');
w('  --icon-xl: 32px;');
w('  --bottom-nav-h: 72px;');
w('  --page-pad-x: 18px;');
w('}');

writeFileSync(join(root, 'src', 'shared', 'styles', 'tokens.css'), css.join('\n') + '\n');
console.log('tokens.css generated');
