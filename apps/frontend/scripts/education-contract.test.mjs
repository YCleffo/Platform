import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '..', '..', '..');

const contractPath = path.join(root, 'contracts', 'openapi', 'education.yaml');
const contract = JSON.parse(readFileSync(contractPath, 'utf8'));

const cache = new Map();
cache.set('./education.yaml', contract);

function loadDoc(relPath) {
  if (!cache.has(relPath)) {
    const full = path.resolve(root, 'contracts', 'openapi', relPath);
    if (existsSync(full)) {
      cache.set(relPath, JSON.parse(readFileSync(full, 'utf8')));
    }
  }
  return cache.get(relPath);
}

function resolveRef(refStr, currentDoc = contract) {
  let doc = currentDoc;
  let pointer = refStr;
  if (refStr.startsWith('./') || refStr.includes('.yaml#')) {
    const [filePart, pointerPart] = refStr.split('#');
    doc = loadDoc(filePart);
    pointer = pointerPart || '';
  }
  if (!pointer || pointer === '/') return doc;
  if (pointer.startsWith('#/')) pointer = pointer.slice(2);
  else if (pointer.startsWith('#')) pointer = pointer.slice(1);
  else if (pointer.startsWith('/')) pointer = pointer.slice(1);

  let target = doc;
  for (const rawKey of pointer.split('/')) {
    const key = rawKey.replaceAll('~1', '/').replaceAll('~0', '~');
    target = target?.[key];
  }
  return target;
}

test('Education OpenAPI covers precisely the registered optional HTTP operations', () => {
  const routesSrc = readFileSync(path.join(root, 'apps', 'backend', 'config', 'common', 'education-routes.php'), 'utf8');
  const transferSrc = readFileSync(path.join(root, 'apps', 'backend', 'config', 'common', 'education-transfer-routes.php'), 'utf8');

  const actualEdu = [...routesSrc.matchAll(/\['(GET|POST|PUT|DELETE)',\s*'([^']+)',\s*'[^']+'\]/g)].map(([, m, p]) => `${m} ${p}`);
  const actualTrans = [...transferSrc.matchAll(/\['(GET|POST|PUT|DELETE)',\s*'([^']+)',\s*'[^']+'\]/g)].map(([, m, p]) => `${m} ${p}`);
  const actual = [...actualEdu, ...actualTrans].sort();

  const documented = [];
  for (const [routePath, item] of Object.entries(contract.paths)) {
    let targetItem = item;
    if (item.$ref) {
      targetItem = resolveRef(item.$ref, contract);
      assert.ok(targetItem, `Failed to resolve path $ref: ${item.$ref}`);
    }
    for (const method of Object.keys(targetItem)) {
      if (['get', 'post', 'put', 'delete'].includes(method)) {
        const cleanPath = routePath.replace('/api/education', '');
        const op = `${method.toUpperCase()} ${cleanPath}`;
        if (actual.includes(op)) {
          documented.push(op);
        }
      }
    }
  }

  // Also include operations defined in externalDocs (activities and assessments)
  const activitiesContract = loadDoc('./education-activities.yaml');
  if (activitiesContract?.paths) {
    for (const [routePath, item] of Object.entries(activitiesContract.paths)) {
      for (const method of Object.keys(item)) {
        if (['get', 'post', 'put', 'delete'].includes(method)) {
          const cleanPath = routePath.replace('/api/education', '');
          const op = `${method.toUpperCase()} ${cleanPath}`;
          if (actual.includes(op) && !documented.includes(op)) {
            documented.push(op);
          }
        }
      }
    }
  }

  const assessmentsContract = loadDoc('./education-assessments.yaml');
  if (assessmentsContract?.paths) {
    for (const [routePath, item] of Object.entries(assessmentsContract.paths)) {
      for (const method of Object.keys(item)) {
        if (['get', 'post', 'put', 'delete'].includes(method)) {
          const cleanPath = routePath.replace('/api/education', '');
          const op = `${method.toUpperCase()} ${cleanPath}`;
          if (actual.includes(op) && !documented.includes(op)) {
            documented.push(op);
          }
        }
      }
    }
  }
  documented.sort();

  assert.equal(actual.length, 127);
  assert.equal(documented.length, 127);
  assert.deepEqual(documented, actual);
});

test('Education OpenAPI references resolve and every operation requires Core authentication', () => {
  function visit(value, currentDoc = contract) {
    if (!value || typeof value !== 'object') return;
    if (value.$ref) {
      const target = resolveRef(value.$ref, currentDoc);
      assert.ok(target, `Unresolved schema: ${value.$ref}`);
    }
    Object.values(value).forEach(v => visit(v, currentDoc));
  }
  visit(contract);

  for (const [pathKey, pathItem] of Object.entries(contract.paths)) {
    let resolvedItem = pathItem;
    if (pathItem.$ref) {
      resolvedItem = resolveRef(pathItem.$ref, contract);
    }
    for (const [method, operation] of Object.entries(resolvedItem)) {
      if (!['get', 'post', 'put', 'delete'].includes(method)) continue;
      const sec = operation.security ?? contract.security;
      assert.ok(sec?.some(value => 'CoreBearer' in value), `Missing CoreBearer on ${method.toUpperCase()} ${pathKey}`);
    }
  }
});
