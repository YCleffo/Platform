import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const contract = JSON.parse(readFileSync('../../contracts/openapi/education.yaml', 'utf8'));
test('Education OpenAPI covers precisely the registered optional HTTP operations', () => {
  const routes = readFileSync('../backend/config/common/education-routes.php', 'utf8');
  const actual = [...routes.matchAll(/\['(GET|POST|PUT)', '([^']+)', '[^']+'\]/g)].map(([, method, path]) => `${method} ${path}`).sort();
  const documented = Object.entries(contract.paths).flatMap(([path, item]) => Object.keys(item).filter(method => ['get', 'post', 'put'].includes(method)).map(method => `${method.toUpperCase()} ${path.replace('/api/education', '')}`)).sort();
  assert.equal(actual.length, 33); assert.deepEqual(documented, actual);
});
test('Education OpenAPI references resolve and every operation requires Core authentication', () => {
  function visit(value) {
    if (!value || typeof value !== 'object') return;
    if (value.$ref) {
      assert.ok(value.$ref.startsWith('#/'));
      let target = contract;
      for (const key of value.$ref.slice(2).split('/')) target = target?.[key.replaceAll('~1', '/').replaceAll('~0', '~')];
      assert.ok(target, `Unresolved schema: ${value.$ref}`);
    }
    Object.values(value).forEach(visit);
  }
  visit(contract);
  for (const path of Object.values(contract.paths)) for (const [method, operation] of Object.entries(path)) {
    if (!['get', 'post', 'put'].includes(method)) continue;
    assert.ok((operation.security ?? contract.security)?.some(value => 'CoreBearer' in value));
  }
});
