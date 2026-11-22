/// <reference types="node" />
import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join, sep } from 'node:path';
import { parse } from 'vue/compiler-sfc';
import { NodeTypes, type TemplateChildNode } from '@vue/compiler-dom';
import ts from 'typescript';
import { expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { createAppRouter } from './index';

type Status = 'resolved' | 'browser-verification' | 'external' | 'fragment' | 'broken';
type Result = { source: string; kind: string; target: string; status: Status; detail?: string };
type RuntimeClass = 'ROLE_RESTRICTED' | 'CONTEXT_DEPENDENT' | 'INTERNAL' | 'NOT_VERIFIED';
type Target = Pick<Result, 'target' | 'status' | 'detail'>;

const requiredQueries: Record<string, readonly string[]> = {
  'core-activation': ['token'],
  'core-password-reset': ['token'],
  'core-mfa-recovery': ['token'],
  'password-reset-confirm': ['token'],
  'verify-email': ['token'],
  invitation: ['token'],
};
const literal = (node: ts.Expression | undefined): string | undefined =>
  node && ts.isStringLiteralLike(node) ? node.text : undefined;
const property = (object: ts.ObjectLiteralExpression, key: string) =>
  object.properties.find((item): item is ts.PropertyAssignment =>
    ts.isPropertyAssignment(item)
    && (ts.isIdentifier(item.name) || ts.isStringLiteralLike(item.name))
    && item.name.text === key,
  )?.initializer;

function classifyRuntimeLink({ source, kind, target }: Result): RuntimeClass {
  if (kind === 'navigation-item' || /^\{\s*query\s*:/.test(target)) return 'INTERNAL';
  if (/\/(?:AdminLayout|AdminDashboardView|EducationContextView|EducationLayout|StudentLayout)\.vue:/.test(source)) {
    return 'ROLE_RESTRICTED';
  }
  if (kind.startsWith('location.') || target.includes('${')
    || /\b(?:route\.query|workspace\.child|redirectTarget|requestRoute|\w*Target\(|childSection\()/.test(target)) {
    return 'CONTEXT_DEPENDENT';
  }
  return 'NOT_VERIFIED';
}

it('checks static navigation against the registered Vue Router routes', () => {
  const router = createAppRouter(createMemoryHistory(), true);
  const routes = router.getRoutes().flatMap((record) => {
    if (typeof record.name !== 'string') return [];
    return [{
      name: record.name,
      path: record.path,
      requiredParams: [...record.path.matchAll(/:([\w]+)(?:\([^)]*\))?([?*+]?)/g)]
        .filter((part) => part[2] !== '?' && part[2] !== '*').map((part) => part[1]!),
      requiredQuery: requiredQueries[record.name] ?? [],
    }];
  }).sort((left, right) => left.name.localeCompare(right.name, 'en'));
  const byName = new Map(routes.map((record) => [record.name, record]));
  const results: Result[] = [];

  function checkPath(raw: string, externalTarget = false, rel = ''): Target {
    const target = raw.trim();
    if (target.startsWith('#')) return { target, status: 'fragment' };
    if (/^(?:[a-z][\w+.-]*:|\/\/)/i.test(target) || target.startsWith('\\')) {
      try {
        const url = new URL(target, 'https://platform.invalid');
        const safeScheme = /^(https?|mailto|tel):$/.test(url.protocol);
        const safeTarget = !externalTarget || rel.split(/\s+/).some((value) => /^(noopener|noreferrer)$/.test(value));
        return {
          target,
          status: safeScheme && safeTarget && !url.username && !url.password ? 'external' : 'broken',
          ...(!safeScheme || !safeTarget || url.username || url.password ? { detail: 'Unsafe external scheme, credentials or opener' } : {}),
        };
      } catch {
        return { target, status: 'broken', detail: 'Malformed external URL' };
      }
    }
    if (!target.startsWith('/')) return { target, status: 'browser-verification', detail: 'Relative to the current route' };
    const resolved = router.resolve(target);
    if (!resolved.matched.length || resolved.name === 'not-found') {
      return { target, status: 'broken', detail: 'No registered route for path' };
    }
    const keys = requiredQueries[String(resolved.name)] ?? [];
    const missing = keys.filter((key) => typeof resolved.query[key] !== 'string' || !resolved.query[key]);
    return missing.length
      ? { target, status: 'broken', detail: `Missing action query: ${missing.join(', ')}` }
      : { target, status: 'resolved' };
  }

  function checkTarget(node: ts.Expression, file: ts.SourceFile, navigationItem = false): Target {
    const value = literal(node);
    if (value !== undefined) return checkPath(value);
    if (ts.isParenthesizedExpression(node)) return checkTarget(node.expression, file, navigationItem);
    const deferred: Target = { target: node.getText(file), status: 'browser-verification', detail: 'Runtime destination or context' };
    if (!ts.isObjectLiteralExpression(node)) return deferred;
    const path = literal(property(node, 'path'));
    if (!navigationItem && path !== undefined) return checkPath(path);
    const name = literal(property(node, navigationItem ? 'routeName' : 'name'));
    if (name === undefined) return deferred;
    const record = byName.get(name);
    if (!record) return { target: name, status: 'broken', detail: 'Unknown route name' };
    if (navigationItem && property(node, 'to')) return { target: name, status: 'resolved' };
    for (const [container, keys] of [['params', record.requiredParams], ['query', record.requiredQuery]] as const) {
      const argument = property(node, container);
      if (!keys.length) continue;
      if (!argument || !ts.isObjectLiteralExpression(argument) || argument.properties.some(ts.isSpreadAssignment)) {
        if (container === 'query' && !argument) return { target: name, status: 'broken', detail: `Missing action query: ${keys.join(', ')}` };
        return { ...deferred, target: name, detail: `Verify ${container} from the current route: ${keys.join(', ')}` };
      }
      const provided = new Set(argument.properties.flatMap((entry) =>
        entry.name && (ts.isIdentifier(entry.name) || ts.isStringLiteralLike(entry.name)) ? [entry.name.text] : [],
      ));
      const missing = keys.filter((key) => !provided.has(key));
      if (missing.length) return { target: name, status: 'broken', detail: `Missing ${container}: ${missing.join(', ')}` };
    }
    return { target: name, status: 'resolved' };
  }

  function inspectScript(content: string, path: string, lineOffset = 0) {
    if (!/\b(?:router|location|routeName)\b/.test(content)) return;
    const file = ts.createSourceFile(path, content, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
    const sourceAt = (node: ts.Node) =>
      `${path}:${file.getLineAndCharacterOfPosition(node.getStart(file)).line + 1 + lineOffset}`;
    function visit(node: ts.Node) {
      if (ts.isCallExpression(node) && ts.isPropertyAccessExpression(node.expression)) {
        const { expression, name } = node.expression;
        const target = node.arguments[0];
        if (target && ['push', 'replace'].includes(name.text) && /(?:^|\.)(?:\$?router)$/.test(expression.getText(file))) {
          results.push({ source: sourceAt(node), kind: `router.${name.text}`, ...checkTarget(target, file) });
        }
        if (target && ['assign', 'replace'].includes(name.text) && /(?:^|\.)location$/.test(expression.getText(file))) {
          results.push({ source: sourceAt(node), kind: `location.${name.text}`, ...checkTarget(target, file) });
        }
      } else if (ts.isObjectLiteralExpression(node) && property(node, 'routeName')) {
        const source = sourceAt(node);
        results.push({ source, kind: 'navigation-item', ...checkTarget(node, file, true) });
        const destination = property(node, 'to');
        if (destination) results.push({ source, kind: 'navigation-to', ...checkTarget(destination, file) });
      }
      ts.forEachChild(node, visit);
    }
    visit(file);
  }

  function inspectTemplate(node: TemplateChildNode, path: string) {
    if (node.type !== NodeTypes.ELEMENT) return;
    const source = `${path}:${node.loc.start.line}`;
    const attr = (name: string) => node.props.find((prop) => prop.type === NodeTypes.ATTRIBUTE && prop.name === name);
    const attrValue = (name: string) => {
      const prop = attr(name);
      return prop?.type === NodeTypes.ATTRIBUTE ? prop.value?.content : undefined;
    };
    const kind = /^(routerlink|router-link)$/i.test(node.tag) ? 'RouterLink' : node.tag === 'a' ? 'href' : '';
    const key = kind === 'RouterLink' ? 'to' : 'href';
    if (kind) {
      const plain = attrValue(key);
      if (plain !== undefined) results.push({ source, kind, ...checkPath(plain, attrValue('target') === '_blank', attrValue('rel')) });
    }
    for (const prop of node.props) {
      if (prop.type !== NodeTypes.DIRECTIVE || prop.exp?.type !== NodeTypes.SIMPLE_EXPRESSION) continue;
      if (prop.name === 'on') {
        inspectScript(prop.exp.content, path, prop.exp.loc.start.line - 1);
      } else if (kind && prop.name === 'bind' && prop.arg?.type === NodeTypes.SIMPLE_EXPRESSION && prop.arg.content === key) {
        const file = ts.createSourceFile(path, `(${prop.exp.content})`, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
        const statement = file.statements[0];
        if (!statement || !ts.isExpressionStatement(statement)) continue;
        const value = ts.isParenthesizedExpression(statement.expression) ? statement.expression.expression : statement.expression;
        const destination = literal(value);
        results.push({
          source, kind,
          ...(destination === undefined ? checkTarget(value, file) : checkPath(destination, attrValue('target') === '_blank', attrValue('rel'))),
        });
      }
    }
    for (const child of node.children) inspectTemplate(child, path);
  }

  const sources = readdirSync('src', { encoding: 'utf8', recursive: true }).sort()
    .map((path) => path.split(sep).join('/'))
    .filter((path) => /\.(vue|ts)$/.test(path) && !/\.(?:spec|test|d)\.ts$/.test(path));
  for (const file of sources) {
    const path = `src/${file}`;
    const content = readFileSync(path, 'utf8');
    if (!file.endsWith('.vue')) {
      inspectScript(content, path);
      continue;
    }
    const { descriptor } = parse(content, { filename: path });
    for (const script of [descriptor.script, descriptor.scriptSetup]) {
      if (script) inspectScript(script.content, path, script.loc.start.line - 1);
    }
    for (const node of descriptor.template?.ast?.children ?? []) inspectTemplate(node, path);
  }

  const output = join('screenshots', 'frontend09-route-audit.json');
  mkdirSync('screenshots', { recursive: true });
  const runtimeLinks = results.filter(({ status }) => status === 'browser-verification')
    .map((link) => ({ ...link, classification: classifyRuntimeLink(link) }));
  writeFileSync(output, JSON.stringify({ routes, links: results, runtimeLinks }, null, 2) + '\n');
  expect(routes.length).toBeGreaterThan(0);
  expect(results.filter(({ status }) => status === 'broken')).toEqual([]);
}, 20_000);
