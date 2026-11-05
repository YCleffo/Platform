import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import ts from 'typescript';
const source = ts.transpileModule(readFileSync('src/modules/admin/workbench/catalog.ts', 'utf8'), {
  compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 },
}).outputText;
const { coreOperations, coreResources } = await import(
  'data:text/javascript;base64,' + Buffer.from(source).toString('base64')
);
test('all migrated Core methods, paths and permissions match actual server middleware', () => {
  const source = readFileSync(resolve('../backend/config/common/routes.php'), 'utf8');
  const normalize = (path) => path.replace(/\{\w+\}/g, '{}');
  const routes = source.split(/(?=Route::)/).flatMap((block) => {
    const path = /Route::(\w+)\('([^']+)'/.exec(block);
    return path
      ? [
          {
            method: path[1].toUpperCase(),
            path: normalize(path[2]),
            permission: /\$permission\('([^']+)'/.exec(block)?.[1] ?? '',
          },
        ]
      : [];
  });
  for (const op of coreOperations) {
    const route = routes.find(
      (r) => r.method === op.method && r.path === normalize('/api' + op.path),
    );
    assert.ok(route, op.key);
    assert.equal(op.permission, route.permission, op.key);
  }
  for (const resource of coreResources) {
    const route = routes.find((r) => r.method === 'GET' && r.path === '/api' + resource.path);
    assert.equal(resource.permission, route?.permission, resource.key);
  }
});

test('dynamic workflow action, field and choice labels exist in English and Russian', async () => {
  async function load(path, transform = (source) => source) {
    const source = transform(readFileSync(path, 'utf8'));
    const output = ts.transpileModule(source, {
      compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 },
    }).outputText;
    return import('data:text/javascript;base64,' + Buffer.from(output).toString('base64'));
  }
  const { operatorOperations } = await load('src/modules/commercial/operator/operations.ts');
  const { workbenchEn, workbenchRu } = await load('src/shared/i18n/workbench.ts');
  const { operatorEn, operatorRu } = await load('src/shared/i18n/operator.ts');
  const withoutImports = (source) => source
    .replace(/^import .*;$/gm, '')
    .replace(/workbench:\s*workbench(En|Ru)/, 'workbench: {}')
    .replace(/operator:\s*operator(En|Ru)/, 'operator: {}');
  const en = (await load('src/shared/i18n/messages/en.ts', withoutImports)).default;
  const ru = (await load('src/shared/i18n/messages/ru.ts', withoutImports)).default;
  for (const messages of [
    { ...en, workbench: workbenchEn, operator: operatorEn },
    { ...ru, workbench: workbenchRu, operator: operatorRu },
  ]) {
    for (const operation of [...coreOperations, ...operatorOperations]) {
      assert.ok(operation.label.split('.').reduce((value, key) => value?.[key], messages), operation.label);
      for (const field of operation.fields) {
        assert.ok(messages.workbench.fields[field.name], field.name);
        for (const option of field.options ?? []) assert.ok(messages.workbench.options[option], option);
      }
    }
  }
});
