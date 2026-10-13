<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

final class OpenApiRouteParityTest extends TestCase
{
    public function testFinancialMutationContractsUseTypedBodiesAndResults(): void
    {
        $path = getenv('CONTROL_OPENAPI_FILE') ?: '/contracts/control-plane.yaml';
        if (!is_file($path)) $path = dirname(__DIR__, 4) . '/contracts/openapi/control-plane.yaml';
        $contract = Yaml::parseFile($path);
        self::assertIsArray($contract);

        $create = $contract['paths']['/api/control/accounts/{accountId}/subscriptions']['post'];
        self::assertSame('#/components/requestBodies/CreateSubscription', $create['requestBody']['$ref']);
        self::assertSame('#/components/responses/SubscriptionCreated', $create['responses'][201]['$ref']);
        self::assertSame(['plan_price_id', 'quantity'], $contract['components']['requestBodies']['CreateSubscription']['content']['application/json']['schema']['required']);
        self::assertSame(['id', 'status'], $contract['components']['schemas']['SubscriptionCreated']['required']);

        $checkout = $contract['paths']['/api/control/accounts/{accountId}/payments/{paymentId}/checkout']['post'];
        self::assertSame('#/components/responses/CheckoutResult', $checkout['responses'][200]['$ref']);
        self::assertSame(['payment_id', 'attempt_id', 'provider', 'method', 'status', 'confirmation'], $contract['components']['schemas']['CheckoutResult']['required']);

        $methods = $contract['paths']['/api/control/accounts/{accountId}/payment-methods']['get'];
        self::assertSame('#/components/responses/SavedPaymentMethods', $methods['responses'][200]['$ref']);
        $setDefault = $contract['paths']['/api/control/accounts/{accountId}/payment-methods/{methodId}/default']['post'];
        self::assertSame('#/components/requestBodies/EmptyObject', $setDefault['requestBody']['$ref']);
        self::assertSame('#/components/responses/SavedPaymentMethod', $setDefault['responses'][200]['$ref']);
        $savedMethod = $contract['components']['schemas']['SavedPaymentMethod'];
        self::assertContains('is_default', $savedMethod['required']);
        self::assertArrayNotHasKey('provider_method_reference', $savedMethod['properties']);

        $entitlements = $contract['paths']['/api/control/accounts/{accountId}/entitlements']['get'];
        self::assertSame('#/components/responses/EffectiveEntitlements', $entitlements['responses'][200]['$ref']);
        self::assertSame(['commercial_account_id', 'subscription', 'features'], $contract['components']['schemas']['EffectiveEntitlements']['required']);
    }

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
