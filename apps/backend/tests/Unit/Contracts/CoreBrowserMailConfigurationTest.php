<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class CoreBrowserMailConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['CORE_AUTH_BROWSER_SMTP' => '1', 'APP_ENV' => 'test', 'TEST_ISOLATED_RUNTIME' => '1',
            'DB_NAME' => 'platform_test', 'REDIS_HOST' => 'redis-test', 'E2E_ISOLATION_PROOF' => str_repeat('a', 64),
            'SMTP_HOST' => 'mailpit-core-test', 'SMTP_PORT' => '1025', 'SMTP_ENCRYPTION' => 'none'] as $key => $value) {
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

    public function testExplicitIsolatedMailpitCoversSecurityAndCommunication(): void
    {
        $params = require dirname(__DIR__, 3) . '/config/environments/test/params.php';
        $provider = $params['privacyProcessorDefinitions'][0];
        self::assertSame('smtp', $provider->code);
        self::assertSame(['communication_email', 'security_email'], $provider->purposes);
    }

    public function testDisabledBrowserMailDoesNotApproveAnyProvider(): void
    {
        putenv('CORE_AUTH_BROWSER_SMTP=0');
        $params = require dirname(__DIR__, 3) . '/config/environments/test/params.php';
        self::assertArrayNotHasKey('privacyProcessorDefinitions', $params);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeRuntime(): iterable
    {
        foreach (['APP_ENV' => 'prod', 'TEST_ISOLATED_RUNTIME' => '0', 'DB_NAME' => 'platform',
            'REDIS_HOST' => 'redis', 'E2E_ISOLATION_PROOF' => 'short', 'SMTP_HOST' => 'smtp.example.com',
            'SMTP_PORT' => '587', 'SMTP_ENCRYPTION' => 'tls'] as $key => $value) {
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
