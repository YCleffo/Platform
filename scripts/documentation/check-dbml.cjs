const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {Parser} = require('@dbml/core');
const root = path.resolve(__dirname, '../..');
const cases = [
  ['core','core/schema.catalog.json','core/Core_v2.dbml'],
  ['platform_runtime','core/schema.catalog.json','runtime/Platform_Runtime_v1.dbml'],
  ['commercial','commercial/schema.catalog.json','commercial/Commercial_ControlPlane_v2.dbml'],
];
for (const [schema,catalogFile,dbmlFile] of cases) {
  const catalog = JSON.parse(fs.readFileSync(path.join(root,'docs/database',catalogFile),'utf8'));
  const db = Parser.parse(fs.readFileSync(path.join(root,'docs/database',dbmlFile),'utf8'),'dbml');
  const parsed = db.schemas.flatMap(s => s.tables.map(t => ({schema:s.name,table:t})));
  const expected = catalog.tables.filter(t => t.schema === schema);
  assert.equal(parsed.length, expected.length, dbmlFile+' table count');
  for (const table of expected) {
    const actual = parsed.find(t => t.schema===schema && t.table.name===table.name)?.table;
    assert.ok(actual, 'Missing '+schema+'.'+table.name);
    assert.deepEqual(actual.fields.map(f=>f.name),table.columns.map(c=>c.name),table.name+' columns');
    for (const column of table.columns) {
      assert.equal(Boolean(actual.fields.find(f=>f.name===column.name).not_null),!column.nullable,table.name+'.'+column.name+' nullability');
    }
  }
  const refs = db.schemas.flatMap(s=>s.refs);
  const foreignKeys = expected.flatMap(t=>t.constraints.filter(c=>c.kind==='f').map(c=>({table:t.name,constraint:c})));
  assert.equal(refs.length,foreignKeys.length,dbmlFile+' foreign keys');
  for (const {table,constraint:c} of foreignKeys) {
    assert.ok(refs.some(r=>r.endpoints.some(e=>e.schemaName===schema && e.tableName===table && JSON.stringify(e.fieldNames)===JSON.stringify(c.columns))
      && r.endpoints.some(e=>e.schemaName===c.target_schema && e.tableName===c.target_table && JSON.stringify(e.fieldNames)===JSON.stringify(c.target_columns))), table+' FK '+c.name);
  }
  console.log('PASS',dbmlFile,expected.length+' tables',foreignKeys.length+' physical foreign keys');
}
