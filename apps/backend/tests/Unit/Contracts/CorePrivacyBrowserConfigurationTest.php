<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class CorePrivacyBrowserConfigurationTest extends TestCase
{
    private array $saved = [];
    protected function setUp(): void
    {
        foreach (['CORE_PRIVACY_BROWSER_FIXTURE' => '1', 'APP_ENV' => 'test', 'TEST_ISOLATED_RUNTIME' => '1',
            'DB_NAME' => 'platform_test', 'REDIS_HOST' => 'redis-test', 'E2E_ISOLATION_PROOF' => str_repeat('p', 64)] as $key => $value) {
            $this->saved[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }
    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
    public function testSyntheticMetadataRequiresExplicitOptIn(): void
    {
        $file = dirname(__DIR__, 3) . '/config/environments/test/params.php';
        $params = require $file;
        self::assertSame('Synthetic browser fixture operator', $params['privacyOperatorIdentity']['name']);
        self::assertCount(2, $params['privacyProcessingRoleDefinitions']);
        putenv('CORE_PRIVACY_BROWSER_FIXTURE=0');
        $disabled = require $file;
        self::assertArrayNotHasKey('privacyOperatorIdentity', $disabled);
        self::assertArrayNotHasKey('privacyProcessingRoleDefinitions', $disabled);
    }
    public static function unsafeRuntime(): iterable
    {
        foreach (['APP_ENV' => 'prod', 'TEST_ISOLATED_RUNTIME' => '0', 'DB_NAME' => 'platform', 'REDIS_HOST' => 'redis', 'E2E_ISOLATION_PROOF' => 'short'] as $key => $value) {
            yield $key => [$key, $value];
        }
    }
    /** @dataProvider unsafeRuntime */
    public function testUnsafeRuntimeIsRejected(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $this->expectException(\RuntimeException::class);
        require dirname(__DIR__, 3) . '/config/environments/test/params.php';
    }
}
