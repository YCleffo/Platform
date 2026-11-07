import { readFileSync } from 'node:fs';
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

function loadOpenApi(name) {
  return JSON.parse(readFileSync(path.join(root, 'contracts', 'openapi', name), 'utf8'));
}
function routesFrom(file) {
  const source = readFileSync(path.join(root, 'apps', 'backend', 'config', 'common', file), 'utf8');
  return [...source.matchAll(/\['(GET|POST|PUT|DELETE)', '([^']+)', '([^']+)'\]/g)]
    .map(match => ({ method: match[1], path: match[2].replace('/api/education', '').replace('/api/device-management', '').replace('/api/device-agent', '/agent').replace('/api/storage', ''), operation: match[3] }));
}
function operationId(file, route) {
  const map = { device: 'device_', agent: 'device_agent_', storage: 'storage_', agentStorage: 'storage_agent_' };
  const prefix = route.path.startsWith('/agent/storage')
    ? map.agentStorage
    : file === 'device-routes.php' ? (route.path.startsWith('/agent') ? map.agent : map.device) : map.storage;
  const ids = {
    classrooms: 'device_classrooms', create_classroom: 'device_create_classroom',
    devices: 'device_devices', create_device: 'device_create_device', device: 'device_device',
    device_enroll: 'device_enroll', revoke_device: 'device_revoke', device_events: 'device_events',
    device_session: 'device_start_session', device_session_end: 'device_end_session',
    agent_enroll: 'device_agent_enroll', agent_heartbeat: 'device_agent_heartbeat', agent_commands: 'device_agent_commands',
    agent_command_ack: 'device_agent_command_ack', agent_session: 'device_agent_session', agent_session_state: 'device_agent_session_state',
    agent_event: 'device_agent_event',
    space: 'storage_space', files: 'storage_files', ticket: 'storage_ticket', upload: 'storage_upload', download: 'storage_download',
    agent_storage_manifest: 'storage_agent_manifest', agent_storage_ticket: 'storage_agent_ticket', agent_storage_sync: 'storage_agent_sync',
    agent_storage_space: 'storage_agent_space', agent_storage_upload: 'storage_agent_upload', agent_storage_download: 'storage_agent_download',
  };
  void prefix;
  return ids[route.operation];
}
function checkRefs(openapi) {
  const schemas = openapi.components?.schemas ?? {};
  const responses = openapi.components?.responses ?? {};
  const unresolved = [];
  const walk = (node, where) => {
    if (node === null || typeof node !== 'object') return;
    if (Array.isArray(node)) return node.forEach(item => walk(item, where));
    for (const [key, value] of Object.entries(node)) {
      if (key === '$ref') {
        const schemasRef = value.startsWith('#/components/schemas/') && schemas[value.slice('#/components/schemas/'.length)];
        const responsesRef = value.startsWith('#/components/responses/') && responses[value.slice('#/components/responses/'.length)];
        if (!schemasRef && !responsesRef) {
          unresolved.push(`${where}: ${value}`);
        }
      } else {
        walk(value, `${where}.${key}`);
      }
    }
  };
  walk(openapi, 'root');
  assert.deepEqual(unresolved, []);
}

const byPath = (a, b) => (a.path === b.path ? a.method.localeCompare(b.method) : a.path.localeCompare(b.path));

test('device-management OpenAPI matches registered routes', () => {
  const openapi = loadOpenApi('device-management.yaml');
  const routes = routesFrom('device-routes.php');
  const documented = Object.values(openapi.paths).flatMap((item, index) =>
    Object.entries(item).map(([method, op]) => ({ method: method.toUpperCase(), path: Object.keys(openapi.paths)[index], operationId: op.operationId })));
  const actual = routes.map(route => ({ method: route.method, path: route.path, operationId: operationId('device-routes.php', route) }));
  assert.deepEqual(documented.sort(byPath), actual.sort(byPath));
  checkRefs(openapi);
});

test('storage OpenAPI matches registered routes', () => {
  const openapi = loadOpenApi('storage.yaml');
  const routes = routesFrom('storage-routes.php');
  const documented = Object.values(openapi.paths).flatMap((item, index) =>
    Object.entries(item).map(([method, op]) => ({ method: method.toUpperCase(), path: Object.keys(openapi.paths)[index], operationId: op.operationId })));
  const actual = routes.map(route => ({ method: route.method, path: route.path, operationId: operationId('storage-routes.php', route) }));
  assert.deepEqual(documented.sort(byPath), actual.sort(byPath));
  checkRefs(openapi);
});
