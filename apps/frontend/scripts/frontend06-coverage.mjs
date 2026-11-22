import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { basename, dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';

const directory = dirname(fileURLToPath(import.meta.url));
const root = resolve(directory, '../../..');
const frontend = join(root, 'apps/frontend/src');
const output = join(root, 'docs/audits/frontend06-coverage.json');
const { contracts, core, commercial } = JSON.parse(execFileSync(
  'php', [join(directory, 'frontend06-backend-inventory.php')], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 },
));
const methods = new Set(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']);
const sourcePath = file => relative(root, file).replaceAll('\\', '/');
const routeKey = (method, path) => `${method} ${path.replace(/\{[^{}]+\}/g, '{}')}`;

function contractPath(document, path) {
  if (path.startsWith('/api/')) return path;
  if (document === 'device-management.yaml') {
    return path.startsWith('/agent/') ? path.replace('/agent/', '/api/device-agent/') : '/api/device-management' + path;
  }
  if (document === 'storage.yaml') {
    return path.startsWith('/agent/') ? path.replace('/agent/', '/api/device-agent/') : '/api/storage' + path;
  }
  const base = contracts[document].servers?.[0]?.url;
  return base?.startsWith('/api/') ? base + path : path;
}

function resolveItem(document, item) {
  if (!item.$ref) return { document, item };
  const [file, pointer] = item.$ref.split('#');
  const target = file ? basename(file) : document;
  const referenced = pointer.slice(1).split('/').reduce(
    (node, part) => node?.[part.replaceAll('~1', '/').replaceAll('~0', '~')], contracts[target],
  );
  if (!referenced) throw new Error(`Unresolved path reference in ${document}: ${item.$ref}`);
  return resolveItem(target, referenced);
}

const documented = new Map();
for (const [document, contract] of Object.entries(contracts)) {
  for (const [path, pathItem] of Object.entries(contract.paths)) {
    const resolved = resolveItem(document, pathItem);
    for (const [method, operation] of Object.entries(resolved.item)) {
      if (!methods.has(method.toUpperCase())) continue;
      const fullPath = contractPath(document, path);
      const key = routeKey(method.toUpperCase(), fullPath);
      const entries = documented.get(key) ?? [];
      entries.push({
        file: document,
        path,
        operationId: operation.operationId ?? null,
        security: operation.security ?? contracts[resolved.document].security ?? [],
        deprecated: operation.deprecated === true,
      });
      documented.set(key, entries);
    }
  }
}

function filesUnder(directoryName) {
  return readdirSync(directoryName, { withFileTypes: true }).sort((a, b) => a.name < b.name ? -1 : 1).flatMap(entry => {
    const file = join(directoryName, entry.name);
    if (entry.isDirectory()) return filesUnder(file);
    return /\.(?:ts|vue)$/.test(file) && !/\.(?:spec|test|d)\.ts$/.test(file) ? [file] : [];
  });
}

function parseSource(file) {
  const contents = readFileSync(file, 'utf8');
  const script = file.endsWith('.vue') ? /<script\b[^>]*>([\s\S]*?)<\/script>/.exec(contents) : null;
  const code = script ? contents.slice(0, script.index).replace(/[^\n]/g, ' ') + script[1] : contents;
  return { file, contents, ast: ts.createSourceFile(file, code, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS) };
}

const sources = filesUnder(frontend).map(parseSource);
const byFile = new Map(sources.map(source => [source.file, source]));
const routeSources = sources.filter(({ file }) => [
  'apps/frontend/src/app/router/index.ts',
  'apps/frontend/src/modules/education/routes.ts',
].includes(sourcePath(file)));

function walk(node, visit) {
  visit(node);
  ts.forEachChild(node, child => walk(child, visit));
}

function property(object, name) {
  if (!object || !ts.isObjectLiteralExpression(object)) return undefined;
  return object.properties.find(field => ts.isPropertyAssignment(field) && field.name.getText() === name)?.initializer;
}

function declaration(node, name) {
  for (let scope = node.parent; scope; scope = scope.parent) {
    if (!ts.isBlock(scope) && !ts.isSourceFile(scope)) continue;
    for (const statement of scope.statements) {
      if (!ts.isVariableStatement(statement)) continue;
      for (const variable of statement.declarationList.declarations) {
        if (variable.name.getText() === name) return variable.initializer;
      }
    }
  }
}

function functionReturns(node, name) {
  const variable = declaration(node, name);
  if (variable && ts.isArrowFunction(variable) && !ts.isBlock(variable.body)) return [variable.body];
  const statement = node.getSourceFile().statements.find(candidate =>
    ts.isFunctionDeclaration(candidate) && candidate.name?.text === name);
  const expressions = [];
  if (statement?.body) walk(statement.body, child => {
    if (ts.isReturnStatement(child) && child.expression) expressions.push(child.expression);
  });
  return expressions;
}

function parameterChoices(node, name) {
  for (let scope = node.parent; scope; scope = scope.parent) {
    if (!ts.isFunctionLike(scope)) continue;
    const type = scope.parameters.find(parameter => parameter.name.getText() === name)?.type;
    if (type && ts.isUnionTypeNode(type) && type.types.every(member =>
      ts.isLiteralTypeNode(member) && ts.isStringLiteralLike(member.literal))) {
      return type.types.map(member => member.literal.text);
    }
    if (scope.parameters[0]?.name.getText() !== name) continue;
    const mapping = scope.parent;
    if (!ts.isCallExpression(mapping) || !ts.isPropertyAccessExpression(mapping.expression) ||
      mapping.expression.name.text !== 'map') continue;
    let values = mapping.expression.expression;
    while (ts.isParenthesizedExpression(values) || ts.isAsExpression(values)) values = values.expression;
    if (ts.isArrayLiteralExpression(values) && values.elements.every(ts.isStringLiteralLike)) {
      return values.elements.map(value => value.text);
    }
  }
  return ['{}'];
}

function expand(expression, depth = 0) {
  if (!expression || depth > 10) return ['{}'];
  const next = node => expand(node, depth + 1);
  if (ts.isStringLiteralLike(expression)) return [expression.text];
  if (ts.isIdentifier(expression)) {
    const initializer = declaration(expression, expression.text);
    return initializer ? next(initializer) : parameterChoices(expression, expression.text);
  }
  if (ts.isParenthesizedExpression(expression)) return next(expression.expression);
  if (ts.isConditionalExpression(expression)) return [...next(expression.whenTrue), ...next(expression.whenFalse)];
  if (ts.isTemplateExpression(expression)) {
    return expression.templateSpans.reduce((prefixes, span) =>
      prefixes.flatMap(prefix => next(span.expression).map(value => prefix + value + span.literal.text)),
    [expression.head.text]);
  }
  if (ts.isBinaryExpression(expression) && expression.operatorToken.kind === ts.SyntaxKind.PlusToken) {
    return next(expression.left).flatMap(prefix => next(expression.right).map(suffix => prefix + suffix));
  }
  if (ts.isCallExpression(expression)) {
    const called = expression.expression.getText();
    if (called === 'query') return next(expression.arguments[0]);
    if (called === 'childPath') return next(expression.arguments[1]).map(section =>
      '/education/parent/children/{}' + (section === '{}' ? '' : '/' + section));
    if (called === 'familyPath') return ['/education/students/{}/guardians' + (expression.arguments.length > 1 ? '/{}' : '')];
    if (ts.isIdentifier(expression.expression)) {
      const returns = functionReturns(expression, called);
      if (returns.length) return returns.flatMap(next);
    }
  }
  return ['{}'];
}

function httpCall(node, file) {
  const called = node.expression.getText();
  const args = node.arguments;
  const from = sourcePath(file);
  if (from.endsWith('/workbench/catalog.ts') && called === 'op') {
    return { path: args[3], verb: expand(args[2])[0], action: expand(args[0])[0] };
  }
  if (/\/(?:homework\/api|activityApi)\.ts$/.test(from) && called === 'request') {
    return { path: args[1], verb: expand(args[3])[0], prefix: '/api/education' };
  }
  if (from.endsWith('/parent/api.ts') && called === 'request') {
    return { path: args[0], verb: args[4] ? expand(args[4])[0] : 'GET' };
  }
  if (from.endsWith('/education/api.ts') && called === 'write') {
    return { path: args[0], verb: args[4] ? expand(args[4])[0] : 'POST', prefix: '/api/education' };
  }
  if (from.endsWith('/student/api.ts') && called === 'write') return { path: args[0], verb: 'PUT' };
  if (from.endsWith('/student/storage.ts') && called === 'transferPost') return { path: args[1], verb: 'POST' };
  if (called === 'coreAdminHttp.document') return { path: args[0], verb: 'GET' };
  if (['educationUpload', 'educationDownload'].includes(called)) {
    return { path: args[1], verb: called === 'educationUpload' ? 'POST' : 'GET' };
  }
  const simple = /^(?:[\w$]+\.)?(get|post|put|patch|delete)$/.exec(called);
  if (simple) {
    return { path: args[0], verb: simple[1].toUpperCase(),
      prefix: from.endsWith('/education/api.ts') ? '/api/education' : undefined };
  }
  if (called === 'auth' && from.endsWith('/operator/session.ts')) return { path: args[0], verb: 'POST' };
  if (!/(?:^|\.)(?:request|fetch)$/.test(called)) return;
  const init = args[1];
  const jsonInit = init && ts.isCallExpression(init) && init.expression.getText() === 'jsonInit' && init.arguments[0];
  const spread = init && ts.isObjectLiteralExpression(init) && init.properties.find(field =>
    ts.isSpreadAssignment(field) && ts.isCallExpression(field.expression) && field.expression.expression.getText() === 'jsonInit');
  const method = property(init, 'method') || jsonInit || (spread && spread.expression.arguments[0]);
  return { path: args[0], verb: method ? expand(method)[0] : 'GET' };
}

function routePath(path, file, prefix) {
  if (path.startsWith('/api/')) return path;
  const normalized = sourcePath(file);
  const commercialFile = /\/(?:commercial|core\/auth)\//.test(normalized);
  return (prefix ?? (commercialFile ? '/api/control' : '/api')) + path;
}

function caller(node) {
  for (let current = node.parent; current; current = current.parent) {
    if (ts.isPropertyAssignment(current) || ts.isMethodDeclaration(current)) {
      const object = current.parent;
      if (ts.isObjectLiteralExpression(object) && ts.isVariableDeclaration(object.parent)) {
        return { action: current.name.getText(), owner: object.parent.name.getText() };
      }
      if (ts.isObjectLiteralExpression(object) && ts.isReturnStatement(object.parent)) {
        return { action: current.name.getText() };
      }
    }
    if (ts.isFunctionDeclaration(current) && current.name) return { action: current.name.text };
    if (ts.isVariableDeclaration(current) && current.initializer &&
      (ts.isArrowFunction(current.initializer) || ts.isFunctionExpression(current.initializer))) {
      return { action: current.name.getText() };
    }
  }
  return {};
}

const clients = new Map();
function addClient(key, source, node, action) {
  const matches = clients.get(key) ?? [];
  matches.push({
    file: sourcePath(source.file),
    line: source.ast.getLineAndCharacterOfPosition(node.getStart()).line + 1,
    ...caller(node),
    ...(action ? { action } : {}),
  });
  clients.set(key, matches);
}

for (const source of sources) {
  walk(source.ast, node => {
    if (ts.isObjectLiteralExpression(node) &&
      /\/(?:commercial\/operator\/operations|admin\/workbench\/catalog)\.ts$/.test(sourcePath(source.file))) {
      const path = property(node, 'path');
      const action = property(node, 'key');
      const verb = expand(property(node, 'method'))[0];
      if (path && action && ts.isStringLiteralLike(path) && ts.isStringLiteralLike(action)) {
        addClient(routeKey(methods.has(verb) ? verb : 'GET', routePath(path.text, source.file)), source, node, action.text);
      }
    }
    if (!ts.isCallExpression(node)) return;
    const call = httpCall(node, source.file);
    if (!call || !call.path || !methods.has(call.verb)) return;
    for (const value of expand(call.path)) {
      const path = value.split('?')[0];
      if (!path.startsWith('/')) continue;
      const key = routeKey(call.verb, routePath(path, source.file, call.prefix));
      addClient(key, source, node, call.action);
    }
  });
}

function importedFile(from, specifier) {
  const target = specifier.startsWith('@/') ? resolve(frontend, specifier.slice(2))
    : specifier.startsWith('.') ? resolve(dirname(from), specifier) : '';
  return [target, target + '.ts', target && join(target, 'index.ts')].find(file => byFile.has(file));
}

const vueRoutes = new Map();
for (const { file, ast } of routeSources) {
  walk(ast, node => {
    if (!ts.isObjectLiteralExpression(node)) return;
    const component = property(node, 'component');
    if (!component) return;
    const value = ts.isIdentifier(component) ? declaration(component, component.text) : component;
    const specifier = /import\(['"]([^'"]+)['"]\)/.exec(value?.getText() ?? '')?.[1];
    const route = property(node, 'name')?.getText() ?? property(node, 'path')?.getText();
    const imported = specifier && importedFile(file, specifier);
    if (!imported || !route) return;
    const routes = vueRoutes.get(imported) ?? new Set();
    routes.add(route.replace(/^['"`]|['"`]$/g, ''));
    vueRoutes.set(imported, routes);
  });
}

const parents = new Map();
for (const { file, ast } of sources) {
  walk(ast, node => {
    if (!ts.isImportDeclaration(node) || !ts.isStringLiteral(node.moduleSpecifier)) return;
    const imported = importedFile(file, node.moduleSpecifier.text);
    if (!imported) return;
    const consumers = parents.get(imported) ?? [];
    consumers.push(file);
    parents.set(imported, consumers);
  });
}

function routesFor(file, seen = new Set()) {
  if (seen.has(file)) return [];
  seen.add(file);
  if (vueRoutes.has(file)) return [...vueRoutes.get(file)];
  return (parents.get(file) ?? []).flatMap(parent => routesFor(parent, seen));
}

const usageCache = new Map();
function consumers(client) {
  const key = JSON.stringify(client);
  if (usageCache.has(key)) return usageCache.get(key);
  const result = new Map();
  const origin = join(root, client.file);
  if (vueRoutes.has(origin) || origin.endsWith('.vue')) result.set(origin, client.line);
  if (client.action && /^[A-Za-z_$][\w$]*$/.test(client.action)) {
    const owner = client.owner && /^\w+$/.test(client.owner) ? client.owner + '\\s*\\.\\s*' : '[\\w$]+\\s*\\.\\s*';
    const indirect = /\/(?:core|commercial)\//.test(client.file) || !client.owner;
    const expression = new RegExp(`\\b${owner}${client.action}\\s*\\(`);
    for (const { file, contents } of sources) {
      if (file === origin) continue;
      if (!indirect && !contents.includes(client.owner)) continue;
      if (indirect && !/(?:\.vue$|Store\.ts$|use[A-Z])/.test(file)) continue;
      if (indirect && /\/shared\/api\/commercial\//.test(client.file) &&
        !/(?:modules[\\/]commercial|core[\\/](?:account|auth)|PrivacySettings)/.test(file)) continue;
      const match = expression.exec(contents);
      if (match) result.set(file, contents.slice(0, match.index).split('\n').length);
    }
  }
  if (client.file.endsWith('/commercial/operator/operations.ts') || client.file.endsWith('/admin/workbench/catalog.ts')) {
    result.set(origin, client.line);
  }
  const mapped = [...result].flatMap(([file, line]) => {
    const routes = [...new Set(routesFor(file))].sort();
    return routes.length ? [{ file: sourcePath(file), line, routes }] : [];
  });
  usageCache.set(key, mapped);
  return mapped;
}

function sourceEvidence(file, anchor, action) {
  const text = readFileSync(join(root, file), 'utf8');
  const offset = text.indexOf(anchor);
  if (offset === -1) throw new Error(`Inventory source evidence changed: ${file} (${anchor})`);
  return { file, line: text.slice(0, offset).split('\n').length, action };
}

const interpretedCalls = [
  ['GET /api/branding/favicon/{}', 'modules/admin/configuration/BrandingSettingsPanel.vue', ':src="platformBranding.favicon.url"', 'Display browser-loaded favicon'],
  ['GET /api/control/operator/privacy/incidents', 'modules/commercial/operator/OperatorConsole.vue', "'/operator/privacy/' + tab.value", 'Load incidents tab'],
  ['GET /api/control/operator/privacy/requests', 'modules/commercial/operator/OperatorConsole.vue', "'/operator/privacy/' + tab.value", 'Load requests tab'],
  ['GET /api/core/privacy/requests/{}/data', 'shared/api/core/privacyOperator.ts', 'requestPath(row.id)', 'response'],
  ['GET /api/core/privacy/requests/{}/processing-information', 'shared/api/core/privacyOperator.ts', 'requestPath(row.id)', 'response'],
  ['GET /api/storage/files', 'modules/education/student/StudentDocumentsView.vue', 'storageApi.allFiles(ctx.org', 'List documents through the paginated Storage API'],
  ['POST /api/storage/tickets', 'modules/education/student/StudentDocumentsView.vue', 'await storageApi.uploadFile(ctx.org', 'Acquire the scoped upload ticket for a student document'],
  ['POST /api/storage/transfers/{}/complete', 'modules/education/student/StudentDocumentsView.vue', 'await storageApi.uploadFile(ctx.org', 'Confirm the signed transfer before refreshing the list'],
];
for (const [key, file, anchor, action] of interpretedCalls) {
  const hit = sourceEvidence('apps/frontend/src/' + file, anchor, action);
  clients.set(key, [...clients.get(key) ?? [], hit]);
}

const backend = new Map();
for (const [plane, routes] of [['Core', core], ['Commercial', commercial]]) {
  for (const route of routes) {
    // Both deployments intentionally own distinct health/metrics routes with the same URL.
    const key = plane + ' ' + routeKey(route.method, route.path);
    if (backend.has(key)) throw new Error(`Duplicate backend operation: ${key}`);
    backend.set(key, { plane, ...route });
  }
}

function planeFor(route) {
  if (route.plane === 'Commercial') return 'Commercial';
  if (route.path.startsWith('/api/education/')) return 'Education';
  if (/^\/api\/(?:storage|device-agent\/storage)(?:\/|$)/.test(route.path)) return 'Storage';
  if (/^\/api\/(?:device-management|device-agent)(?:\/|$)/.test(route.path)) return 'DeviceManagement';
  return 'Core';
}

function internalReason(route) {
  if (/^\/api\/device-agent\//.test(route.path)) return 'Windows Agent device channel. Enrollment uses an enrollment token and subsequent requests use device authentication.';
  if (/^\/api\/m2m\//.test(route.path)) return 'Service-to-service deployment, metering or entitlement exchange.';
  if (/^\/api\/control\/webhooks\//.test(route.path)) return 'Payment-provider callback governed by provider verification.';
  if (/^\/(?:api\/)?(?:health|metrics)(?:\/|$)/.test(route.path) || route.path === '/') {
    return 'Deployment health, metrics or service identification.';
  }
  return null;
}

function priorityFor(plane, path, coverage) {
  if (coverage === 'INTERNAL' || coverage === 'DEPRECATED') return 'P2';
  if (/^\/api\/(?:control\/)?auth\//.test(path)) return 'P0';
  if (plane === 'DeviceManagement' || plane === 'Commercial') return 'P1';
  if (plane === 'Education' || plane === 'Storage') return 'P0';
  return 'P1';
}

function audienceFor(route, plane, internal) {
  if (internal) return 'Internal';
  if (plane === 'Storage') return 'Student';
  if (plane === 'DeviceManagement') return 'Operator / Teacher';
  if (plane === 'Education') {
    if (route.path.startsWith('/api/education/parent/')) return 'Parent';
    if (/(?:file-transfers\/\{id\}|\/files\/\{id\}\/transfer|\/homework\/files\/\{id\})(?:\/|$)/.test(route.path)) {
      return 'Teacher / Student';
    }
    if (/^\/api\/education\/student(?:[-/]|$)/.test(route.path)) return 'Student';
    if (route.name === 'education/context') return 'Teacher / Student / Parent';
    return 'Teacher';
  }
  if (plane === 'Commercial') {
    if (/^\/api\/control\/(?:finance|operator)(?:\/|$)/.test(route.path)) return 'Operator';
    if (route.firewallRole === 'PUBLIC_ACCESS') return 'Public / Commercial account user';
    return 'Commercial account user';
  }
  return route.path.startsWith('/api/core/') ? 'Admin' : 'Core account user / Admin';
}

const actionSources = new Map();
function permissionFor(route, plane) {
  if (plane === 'Commercial') {
    return {
      context: route.firewallRole ?? 'See firewall',
      permissions: [...new Set(route.authorization.flatMap(evidence => evidence.permissions ?? []))],
      source: route.source,
      authorizationLines: route.authorizationLines,
      authorization: route.authorization,
      note: 'Symfony firewall plus controller and delegated service policy. Conditional permissions depend on the operation and resource.',
    };
  }
  const permissions = route.middleware.flatMap(value => value?.['__construct()']?.permission ?? []);
  const context = route.middleware.filter(value => typeof value === 'string' && value.includes('Require'))
    .map(value => value.split('\\').at(-1));
  const operation = route.defaults.operation;
  if (plane === 'DeviceManagement' && route.path.startsWith('/api/device-management/')) {
    permissions.push('education.devices.manage');
    context.push('Education organization scope');
  }
  if (plane === 'Storage' && route.path.startsWith('/api/storage/')) {
    permissions.push('education.student.use');
    context.push('Education student and organization scope');
  }
  if (plane === 'Education') {
    if (!actionSources.has(route.source)) actionSources.set(route.source, readFileSync(join(root, route.source), 'utf8'));
    const source = actionSources.get(route.source);
    if (route.action.endsWith('\\EducationAction') && operation && !['context', 'activate_invite', 'header'].includes(operation)) {
      const permissionArms = source.slice(source.indexOf('$permission = match'), source.indexOf('$studentOperations'));
      const acl = /((?:'[\w]+'\s*,?\s*)+)\s*=>\s*'(education\.[\w.]+)'/g;
      const activityStudent = source.slice(source.indexOf('? $access->studentScope('), source.indexOf(' : ($operation'));
      const exact = [...permissionArms.matchAll(acl)].find(([, choices]) => choices.includes(`'${operation}'`));
      permissions.push(operation.startsWith('student_')
        ? activityStudent.includes(`'${operation}'`) ? 'education.activities.use' : 'education.student.use'
        : exact?.[2] ?? 'education.access');
    } else if (route.action.endsWith('\\CurriculumAction')) {
      permissions.push(operation === 'own_curriculum_progress' ? 'education.student.use'
        : operation === 'create_student' ? 'education.students.create' : 'education.people.manage');
    } else if (route.action.endsWith('\\HomeworkAction')) {
      permissions.push(operation.startsWith('student_') ? 'education.student.use'
        : operation === 'review' ? 'education.homework.review' : 'education.homework.manage');
    } else if (route.action.endsWith('\\ParentAction') && operation.startsWith('staff_')) {
      permissions.push('education.people.manage');
    } else if (route.action.endsWith('\\ParentAction') && !['context', 'register', 'activate'].includes(operation)) {
      permissions.push('education.parent.use');
      context.push('Confirmed child relationship in the selected organization');
    } else if (route.action.endsWith('\\HomeworkIntegrationAction')) {
      if (['material', 'download'].includes(operation)) permissions.push('education.people.manage');
      if (['submission', 'download', 'unlink'].includes(operation)) permissions.push('education.student.use');
      if (operation.endsWith('_activity')) permissions.push('education.activities.use');
    } else if (route.action.endsWith('\\EducationTransferAction')) {
      if (['start', 'download', 'read', 'complete', 'abort'].includes(operation)) permissions.push('education.files.manage');
      if (['homework_start', 'homework_download', 'read', 'complete', 'abort'].includes(operation)) permissions.push('education.people.manage');
      if (['student_homework_start', 'download', 'homework_download', 'read', 'complete', 'abort'].includes(operation)) permissions.push('education.student.use');
    }
    context.push('Operation-specific Education policy and organization/relationship scope');
  }
  return { context, permissions, source: route.source };
}

const coverageNotes = new Map([
  ['GET /api/branding/favicon/{}', 'The public branding response supplies the icon URL and the document requests it as a favicon resource.'],
  ['POST /api/branding/favicon/{}/refresh', 'The branding upload renews a rejected or expired signed request once in the same pending upload. The client has targeted unit coverage.'],
  ['GET /api/education/curriculum-lessons/{}', 'ProgramView uses the nested curriculum from GET /programs/{id}. The individual read API has no matching call.'],
  ['GET /api/education/curriculum-modules/{}', 'ProgramView uses the nested curriculum from GET /programs/{id}. The individual read API has no matching call.'],
  ['GET /api/education/parent/children/{}', 'The parent workspace uses context and scoped child section responses. It has no direct child summary request.'],
  ['PUT /api/education/attempts/{}/revoke', 'The teacher activity results view confirms revocation and re-reads server results and analytics.'],
  ['GET /api/education/files/{}', 'Browser downloads use signed /files/{id}/transfer. The direct bytes endpoint remains available to other clients.'],
  ['POST /api/education/files', 'Browser uploads use signed /file-transfers. The direct multipart endpoint remains available to other clients.'],
  ['GET /api/education/homework/files/{}', 'Browser downloads use signed /homework/files/{id}/transfer. The direct bytes endpoint remains available to other clients.'],
  ['POST /api/education/homework/{}/files', 'Browser uploads use signed /homework/{id}/file-transfers. The direct multipart endpoint remains available to other clients.'],
  ['POST /api/education/student/homework/{}/files', 'Browser uploads use signed /student/homework/{id}/file-transfers. The direct multipart endpoint remains available to other clients.'],
  ['GET /api/storage/file', 'Student documents use signed Storage transfers; the ticket-based bytes endpoint is outside the signed workflow.'],
  ['PUT /api/storage/files', 'Student documents use signed Storage transfers; the ticket-based bytes endpoint is outside the signed workflow.'],
]);

const browserScenarios = [
  {
    file: 'apps/frontend/scripts/e2e-core-profile.mjs',
    scope: 'Core profile saves and resets sparse settings, updates locale and timezone, and records and withdraws consent through the browser',
    result: 'PASS',
    operations: [
      'GET /api/auth/me', 'PATCH /api/auth/me/preferences',
      'GET /api/auth/me/settings', 'PATCH /api/auth/me/settings',
      'GET /api/auth/me/consents', 'POST /api/auth/me/consents/{}', 'DELETE /api/auth/me/consents/{}',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-core-configuration.mjs',
    scope: 'MFA operator edits and deletes Core settings and feature flags, switches module state, and verifies public publication and permission denial',
    result: 'PASS',
    operations: [
      'POST /api/auth/login', 'POST /api/auth/mfa/verify', 'GET /api/public/config',
      'GET /api/core/modules', 'GET /api/core/modules/{}',
      'POST /api/core/modules/{}/enable', 'POST /api/core/modules/{}/disable',
      'GET /api/core/modules/{}/settings', 'GET /api/core/modules/{}/settings/{}',
      'PUT /api/core/modules/{}/settings/{}', 'DELETE /api/core/modules/{}/settings/{}',
      'GET /api/core/settings/system', 'GET /api/core/settings/system/{}',
      'PUT /api/core/settings/system/{}', 'DELETE /api/core/settings/system/{}',
      'GET /api/core/feature-flags', 'GET /api/core/feature-flags/{}',
      'PUT /api/core/feature-flags/{}', 'DELETE /api/core/feature-flags/{}',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-account-workspace.mjs',
    scope: 'Commercial workspace login, account creation and switching, members, analytics, audit and personal-data export on disposable PostgreSQL',
    result: 'PASS',
    operations: [
      'POST /api/control/auth/login', 'GET /api/control/auth/sessions',
      'GET /api/control/accounts', 'POST /api/control/accounts', 'GET /api/control/accounts/{}',
      'GET /api/control/accounts/{}/memberships', 'GET /api/control/accounts/{}/analytics',
      'GET /api/control/accounts/{}/audit-events', 'GET /api/control/privacy/me/export',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-paid-billing.mjs',
    scope: 'Paid Commercial checkout uses real billing and PostgreSQL with synthetic external provider transport',
    result: 'PASS',
    operations: [
      'PUT /api/control/accounts/{}/billing-profile', 'POST /api/control/accounts/{}/onboarding/catalog-reviewed',
      'POST /api/control/accounts/{}/subscriptions', 'POST /api/control/accounts/{}/payments/{}/checkout',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-commercial-operators.mjs',
    scope: 'Two MFA operators review finance evidence and complete privacy request and incident lifecycles on disposable Commercial PostgreSQL',
    result: 'PASS',
    operations: [
      'POST /api/control/auth/mfa/login/verify', 'GET /api/control/operator/me',
      'POST /api/control/finance/plan-change-compensations/attestations/{}/approve',
      'POST /api/control/finance/plan-change-compensations/attestations/{}/reject',
      'GET /api/control/operator/privacy/requests', 'GET /api/control/operator/privacy/incidents',
      'POST /api/control/privacy/operator/requests/intake',
      'POST /api/control/privacy/operator/requests/{}/resolve',
      'POST /api/control/privacy/operator/incidents',
      'POST /api/control/privacy/operator/incidents/{}/events',
      'POST /api/control/privacy/operator/incidents/{}/close',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-education.mjs',
    scope: 'Teacher uses forms and reads persistent data over the real Core API',
    result: 'PASS',
    operations: [
      'POST /api/education/groups', 'PUT /api/education/groups/{}',
      'POST /api/education/groups/{}/archive', 'GET /api/education/groups/{}/students',
      'POST /api/education/lessons', 'PUT /api/education/lessons/{}',
      'GET /api/education/lessons/{}', 'POST /api/education/lessons/{}/complete',
      'PUT /api/education/lessons/{}/participants/{}', 'GET /api/education/lessons/{}/materials',
      'POST /api/education/file-transfers', 'POST /api/education/file-transfers/{}/complete',
      'GET /api/education/files/{}/transfer',
      'POST /api/education/events', 'PUT /api/education/profile',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-education.mjs',
    scope: 'Teacher delegates and revokes group access through the existing panel and a colleague can read only while assigned',
    result: 'PASS',
    operations: [
      'GET /api/education/groups/{}', 'GET /api/education/groups/{}/teachers',
      'PUT /api/education/groups/{}/teachers',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-education.mjs',
    scope: 'Teacher opens the group journal and sees the completed lesson',
    result: 'PASS',
    operations: ['GET /api/education/groups/{}/journal'],
  },
  {
    file: 'apps/frontend/scripts/e2e-education.mjs',
    scope: 'Teacher creates program, module and two topics, assigns the group, relinks the scheduled lesson and verifies completion',
    result: 'PASS',
    operations: [
      'GET /api/education/programs', 'GET /api/education/programs/{}',
      'POST /api/education/programs', 'POST /api/education/programs/{}/modules',
      'POST /api/education/curriculum-modules/{}/lessons',
      'GET /api/education/groups/{}/programs', 'POST /api/education/groups/{}/programs',
      'POST /api/education/lessons', 'PUT /api/education/lessons/{}',
      'GET /api/education/lessons/{}', 'POST /api/education/lessons/{}/complete',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-education-roles.mjs',
    scope: 'Student invitation, self-activation, lesson and private document round trip',
    result: 'PASS',
    operations: [
      'POST /api/education/students/{}/invite', 'POST /api/education/student-activate',
      'GET /api/education/student/home', 'GET /api/education/student/lessons/{}',
      'GET /api/storage/space', 'GET /api/storage/files', 'POST /api/storage/tickets',
      'POST /api/storage/transfers', 'POST /api/storage/transfers/{}/complete',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-education-roles.mjs',
    scope: 'Parent activation, multiple children, isolated sections and relationship revocation',
    result: 'PASS',
    operations: [
      'POST /api/education/students/{}/guardians',
      'POST /api/education/students/{}/guardians/{}/confirm',
      'POST /api/education/students/{}/guardians/{}/invite',
      'POST /api/education/students/{}/guardians/{}/revoke',
      'POST /api/education/parent/activate',
      'GET /api/education/parent/context',
      ...['home', 'schedule', 'diary', 'homework', 'progress', 'notifications'].map(
        section => `GET /api/education/parent/children/{}/${section}`,
      ),
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-education-roles.mjs',
    scope: 'Device operator registers, reads readiness, assigns a student, cancels and reads session, event and command history with a synthetic Agent adapter',
    result: 'PASS',
    operations: [
      'GET /api/device-management/devices', 'POST /api/device-management/devices',
      'GET /api/device-management/devices/{}', 'POST /api/device-management/devices/{}/enroll',
      'POST /api/device-management/devices/{}/session', 'POST /api/device-management/devices/{}/session/cancel',
      'GET /api/device-management/devices/{}/sessions', 'GET /api/device-management/devices/{}/events',
      'GET /api/device-management/devices/{}/commands',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-education-learning.mjs',
    scope: 'Teacher publishes homework; student saves a draft, uploads and submits; teacher reviews and student sees the persisted feedback',
    result: 'PASS',
    operations: [
      'POST /api/education/homework', 'POST /api/education/homework/{}/publish',
      'GET /api/education/homework/{}', 'GET /api/education/homework/{}/submissions',
      'GET /api/education/homework/submissions/{}', 'POST /api/education/homework/submissions/{}/review',
      'GET /api/education/student/homework', 'GET /api/education/student/homework/{}',
      'PUT /api/education/student/homework/{}/draft', 'POST /api/education/student/homework/{}/submit',
      'POST /api/education/student/homework/{}/file-transfers', 'POST /api/education/file-transfers/{}/complete',
    ],
  },
  {
    file: 'apps/frontend/scripts/e2e-education-learning.mjs',
    scope: 'Teacher assigns Typing and Logic; student starts, checkpoints, resumes and finishes; actual persisted results, progress and achievements are asserted',
    result: 'PASS',
    operations: [
      'GET /api/education/activities/assignments', 'POST /api/education/activities/{}/assignments',
      'GET /api/education/student/activities/{}',
      'POST /api/education/student/activities/{}/attempts', 'PUT /api/education/student/attempts/{}/state',
      'PUT /api/education/student/attempts/{}/finish', 'GET /api/education/student/attempts/{}',
      'GET /api/education/student/attempts',
      'GET /api/education/student/activity-progress', 'GET /api/education/student/achievements',
    ],
  },
];

function testEvidence(route, plane, frontendApi) {
  const tests = [];
  const key = routeKey(route.method, route.path);
  if (plane === 'Commercial') {
    tests.push({ type: 'Contract', file: 'apps/control-plane/tests/Unit/OpenApiRouteParityTest.php', scope: 'Route parity' });
  }
  if (plane === 'DeviceManagement' || plane === 'Storage') {
    tests.push({ type: 'Contract', file: 'apps/frontend/scripts/device-storage-contract.test.mjs', scope: 'Route parity' });
  }
  if (['EducationAction', 'CurriculumAction', 'EducationTransferAction'].includes(route.action.split('\\').at(-1))) {
    tests.push({ type: 'Contract', file: 'apps/frontend/scripts/education-contract.test.mjs', scope: 'Route parity for Education and transfer route definitions' });
  }
  if (frontendApi.some(client => client.file.endsWith('/workbench/catalog.ts'))) {
    tests.push({ type: 'Contract', file: 'apps/frontend/scripts/workbench-contracts.test.mjs', scope: 'Workbench parity suite; individual assertions vary by operation' });
  }
  if (route.action.endsWith('\\EducationTransferAction')) {
    tests.push({ type: 'Integration', file: 'apps/backend/tests/Storage02/EducationTransferIntegrationTest.php', scope: 'Signed transfer service publication, response and actor binding' });
  }
  if (plane === 'Storage' && route.defaults.operation.includes('transfer_')) {
    tests.push({ type: 'Integration', file: 'apps/backend/tests/Storage02/StorageTransferIntegrationTest.php', scope: 'Transfer service lifecycle, identity binding and object integrity' });
  }
  if (plane === 'DeviceManagement') {
    tests.push({ type: 'Integration', file: 'apps/backend/tests/Foundation/DeviceManagementTest.php', scope: 'Device service scope and lifecycle; individual assertions vary by operation' });
  }
  if (key === 'GET /api/control/accounts/{}/refunds/{}') {
    tests.push({ type: 'Browser simulation', file: 'apps/frontend/scripts/verify-billing.mjs', scope: 'Fixture-backed refund details, receipt and responsive accessibility' });
  }
  for (const scenario of browserScenarios) {
    if (scenario.operations.includes(key) && existsSync(join(root, scenario.file))) {
      tests.push({ type: 'Browser E2E', file: scenario.file, scope: scenario.scope, result: scenario.result });
    }
  }
  for (const api of frontendApi) {
    const candidate = api.file.replace(/\.(ts|vue)$/, '.spec.ts');
    if (!api.action || !existsSync(join(root, candidate))) continue;
    const testsSource = readFileSync(join(root, candidate), 'utf8');
    if (testsSource.includes('.' + api.action + '(')) tests.push({ type: 'Unit', file: candidate, scope: api.action });
  }
  return [...new Map(tests.map(test => [JSON.stringify(test), test])).values()];
}

const operations = [];
for (const route of backend.values()) {
  const key = routeKey(route.method, route.path);
  const plane = planeFor(route);
  const internal = internalReason(route);
  const references = (documented.get(key) ?? []).filter(item => (plane === 'Commercial') === (item.file === 'control-plane.yaml'));
  const frontendApi = internal ? [] : clients.get(key) ?? [];
  const ui = [...new Map(frontendApi.flatMap(consumers).map(site => [site.file + ':' + site.line, site])).values()];
  const test = testEvidence(route, plane, frontendApi);
  const verifiedBrowser = ui.length > 0 && test.some(evidence => evidence.type === 'Browser E2E' && evidence.result === 'PASS');
  const coverage = internal ? 'INTERNAL' : references.some(item => item.deprecated) ? 'DEPRECATED'
    : verifiedBrowser ? 'FULL' : frontendApi.length ? 'PARTIAL' : 'MISSING';
  const reason = internal ?? coverageNotes.get(key) ?? (coverage === 'DEPRECATED' ? 'OpenAPI deprecation recorded.'
    : verifiedBrowser ? 'Routed user action observed in an isolated browser acceptance run.'
    : ui.length ? 'Source trace leads to a routed component; role-based behavior requires acceptance evidence.'
    : frontendApi.length ? 'Identified a client call; routed user action has not been established.'
    : 'No matching client call in the static scan; dynamic paths and intentional workflow alternatives require review.');
  operations.push({
    plane,
    endpoint: route.method + ' ' + route.path,
    backend: { name: route.name, action: route.action, operation: route.defaults?.operation, source: route.source, line: route.line },
    permission: permissionFor(route, plane),
    audience: audienceFor(route, plane, internal),
    contracts: references,
    frontendApi,
    ui,
    coverage,
    reason,
    test,
    priority: priorityFor(plane, route.path, coverage),
  });
}
operations.sort((a, b) => a.plane.localeCompare(b.plane, 'en') || a.endpoint.localeCompare(b.endpoint, 'en'));

const contractOnly = [...documented].flatMap(([key, entries]) => entries.filter(item =>
  !backend.has((item.file === 'control-plane.yaml' ? 'Commercial' : 'Core') + ' ' + key),
).map(item => ({ endpoint: key.split(' ')[0] + ' ' + contractPath(item.file, item.path), ...item })));
const totals = {};
for (const operation of operations) {
  const summary = totals[operation.plane] ??= { FULL: 0, PARTIAL: 0, MISSING: 0, INTERNAL: 0, DEPRECATED: 0, withoutContract: 0 };
  summary[operation.coverage]++;
  if (!operation.contracts.length) summary.withoutContract++;
}
const snapshot = JSON.stringify({ version: 1, documents: Object.keys(contracts), totals, contractOnly, operations }, null, 2) + '\n';
if (process.argv.includes('--emit-static')) {
  process.stdout.write(snapshot);
} else {
  if (process.argv.includes('--check')) {
    if (readFileSync(output, 'utf8').replaceAll('\r\n', '\n') !== snapshot) {
      throw new Error('The frontend coverage inventory drifted. Regenerate it and review the operation evidence.');
    }
  } else {
    writeFileSync(output, snapshot);
  }
  console.log(JSON.stringify({ totals, contractOnly: contractOnly.length, backendOperations: operations.length }, null, 2));
}
