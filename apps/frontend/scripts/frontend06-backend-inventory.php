<?php

declare(strict_types=1);

use Symfony\Component\Routing\Attribute\Route as SymfonyRoute;
use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 3);
require $root . '/apps/backend/vendor/autoload.php';
require $root . '/apps/control-plane/vendor/autoload.php';

function relativeSource(string $root, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

/** Locate controller checks and statically typed service calls without bootstrapping Symfony. */
function authorizationEvidence(string $root, ReflectionMethod $method, array &$visited, int $depth = 0): array
{
    $class = $method->getDeclaringClass();
    $id = $class->getName() . '::' . $method->getName();
    $file = $method->getFileName();
    if ($file === false || $depth > 2 || isset($visited[$id])
        || !str_starts_with(relativeSource($root, $file), 'apps/control-plane/src/')) {
        return [];
    }
    $visited[$id] = true;
    static $sources = [];
    $source = $sources[$file] ??= file($file, FILE_IGNORE_NEW_LINES);
    $evidence = [];
    for ($i = $method->getStartLine() - 1; $i < $method->getEndLine(); $i++) {
        $line = $source[$i];
        $checks = [];
        if (str_contains($line, '->requirePermission(')) {
            preg_match_all("/'([a-z]+(?:\.[a-z_]+)+)'/", $line, $permissions);
            $checks += ['permissions' => $permissions[1]];
        }
        if (str_contains($line, '->requireSensitiveMfa(')) {
            $checks['requirement'] = 'Recent MFA';
        }
        if (str_contains($line, '->assertAuthorized(')) {
            $checks['requirement'] = 'Operator authorization';
        }
        if ($checks !== []) {
            $evidence[] = ['file' => relativeSource($root, $file), 'line' => $i + 1] + $checks;
        }
        preg_match_all('/\$this->(?:(\w+)->)?(\w+)\(/', $line, $calls, PREG_SET_ORDER);
        foreach ($calls as [, $property, $callee]) {
            $target = $class;
            if ($property !== '') {
                $type = $class->hasProperty($property) ? $class->getProperty($property)->getType() : null;
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !class_exists($type->getName())) {
                    continue;
                }
                $target = new ReflectionClass($type->getName());
            }
            if ($target->hasMethod($callee)) {
                array_push($evidence, ...authorizationEvidence($root, $target->getMethod($callee), $visited, $depth + 1));
            }
        }
    }
    return $evidence;
}

$contracts = [];
foreach (glob($root . '/contracts/openapi/*.yaml') as $file) {
    $contracts[basename($file)] = Yaml::parseFile($file);
}

// Route configuration has no application bootstrap, network or database side effects.
putenv('EDUCATION_ENABLED=1');
$coreConfig = $root . '/apps/backend/config/common/routes.php';
$coreRoutes = require $coreConfig;
$core = [];
foreach ($coreRoutes as $route) {
    $middleware = $route->getData('enabledMiddlewares');
    $action = array_pop($middleware);
    $actionClass = is_array($action) ? ($action['class'] ?? null) : $action;
    $classFile = is_string($actionClass) && class_exists($actionClass)
        ? (new ReflectionClass($actionClass))->getFileName()
        : false;
    foreach ($route->getData('methods') as $method) {
        $core[] = [
            'method' => $method,
            'path' => $route->getData('pattern'),
            'name' => $route->getData('name'),
            'defaults' => $route->getData('defaults'),
            'middleware' => $middleware,
            'action' => $actionClass,
            'source' => relativeSource($root, $classFile ?: $coreConfig),
        ];
    }
}

$securityFile = $root . '/apps/control-plane/config/packages/security.yaml';
$accessControl = Yaml::parseFile($securityFile)['security']['access_control'];
$commercial = [];
foreach (glob($root . '/apps/control-plane/src/Infrastructure/Http/*Controller.php') as $file) {
    $class = new ReflectionClass('ControlPlane\\Infrastructure\\Http\\' . basename($file, '.php'));
    $prefix = '';
    foreach ($class->getAttributes(SymfonyRoute::class) as $attribute) {
        $prefix .= $attribute->newInstance()->getPath();
    }
    $sourceLines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getAttributes(SymfonyRoute::class) as $attribute) {
            $route = $attribute->newInstance();
            $path = $prefix . $route->getPath();
            $role = null;
            foreach ($accessControl as $rule) {
                if (preg_match('~' . $rule['path'] . '~', $path)) {
                    $role = $rule['roles'];
                    break;
                }
            }
            $checks = [];
            for ($i = $method->getStartLine() - 1; $i < $method->getEndLine(); $i++) {
                $line = $sourceLines[$i];
                if (preg_match('/(?:[Aa]uthori[zs]|[Aa]ccess|[Pp]ermission|require[A-Z]|[Oo]perator|[Mm]fa|[Ss]ignature|[Tt]oken).*\(/', $line)) {
                    $checks[] = $i + 1;
                }
            }
            $visited = [];
            $authorization = authorizationEvidence($root, $method, $visited);
            foreach ($route->getMethods() as $httpMethod) {
                $commercial[] = [
                    'method' => $httpMethod,
                    'path' => $path,
                    'name' => $route->getName(),
                    'action' => $class->getShortName() . '::' . $method->getName(),
                    'source' => relativeSource($root, $file),
                    'line' => $method->getStartLine(),
                    'firewallRole' => $role,
                    'authorizationLines' => $checks,
                    'authorization' => $authorization,
                ];
            }
        }
    }
}

echo json_encode(['contracts' => $contracts, 'core' => $core, 'commercial' => $commercial], JSON_THROW_ON_ERROR);
