<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

final class OpenApiRouteParityTest extends TestCase
{
    public function testSubscriptionSchemaIncludesBillingHealthContract(): void
    {
        $path = getenv('CONTROL_OPENAPI_FILE') ?: '/contracts/control-plane.yaml';
        if (!is_file($path)) $path = dirname(__DIR__, 4) . '/contracts/openapi/control-plane.yaml';
        $contract = Yaml::parseFile($path);
        $schema = $contract['components']['schemas']['Subscription'] ?? null;
        self::assertIsArray($schema);
        self::assertContains('billing_status', $schema['required']);
        self::assertSame(['current', 'past_due'], $schema['properties']['billing_status']['enum']);
        self::assertSame(['string', 'null'], $schema['properties']['past_due_since']['type']);
        self::assertSame(['string', 'null'], $schema['properties']['grace_until']['type']);
    }

    public function testEveryApplicationHttpRouteAndContractOperationRemainInParity(): void
    {
        $contractPath = getenv('CONTROL_OPENAPI_FILE') ?: '/contracts/control-plane.yaml';
        if (!is_file($contractPath)) $contractPath = dirname(__DIR__, 4) . '/contracts/openapi/control-plane.yaml';
        self::assertFileExists($contractPath, 'The repository OpenAPI contract must be available to parity tests.');
        $contract = Yaml::parseFile($contractPath);
        self::assertIsArray($contract);
        $documented = [];
        foreach (($contract['paths'] ?? []) as $path => $operations) {
            if (!is_array($operations)) continue;
            foreach ($operations as $method => $_operation) {
                $method = strtoupper((string) $method);
                if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) $documented[$method . ' ' . $path] = true;
            }
        }
        $routes = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Infrastructure/Http/*Controller.php') ?: [] as $file) {
            $class = 'ControlPlane\\Infrastructure\\Http\\' . basename($file, '.php');
            self::assertTrue(class_exists($class), "Controller {$class} must be autoloadable.");
            $reflection = new \ReflectionClass($class);
            $prefix = '';
            foreach ($reflection->getAttributes(Route::class) as $attribute) $prefix .= $attribute->newInstance()->getPath();
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Route::class) as $attribute) {
                    $route = $attribute->newInstance();
                    foreach ($route->getMethods() as $httpMethod) $routes[strtoupper($httpMethod) . ' ' . $prefix . $route->getPath()] = true;
                }
            }
        }
        ksort($routes); ksort($documented);
        self::assertSame(array_keys($routes), array_keys($documented), 'Symfony HTTP routes and OpenAPI operations must match exactly.');
    }
}
