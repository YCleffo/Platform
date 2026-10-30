<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use App\Core\Privacy\Application\ProcessorDefinition;
use PHPUnit\Framework\TestCase;

final class LocalMailConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (['LOCAL_MAIL_ENABLED', 'LOCAL_MAIL_COUNTRY', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_ENCRYPTION'] as $key) {
            $this->original[$key] = getenv($key);
        }
        putenv('LOCAL_MAIL_ENABLED=true');
        putenv('LOCAL_MAIL_COUNTRY=RU');
        putenv('SMTP_HOST=mailpit');
        putenv('SMTP_PORT=1025');
        putenv('SMTP_ENCRYPTION=none');
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testLocalMailpitCoversBothEmailPurposes(): void
    {
        $params = require dirname(__DIR__, 3) . '/config/environments/dev/params.php';
        $provider = $params['privacyProcessorDefinitions'][0];
        self::assertInstanceOf(ProcessorDefinition::class, $provider);
        self::assertSame('smtp', $provider->code);
        self::assertSame(['communication_email', 'security_email'], $provider->purposes);
        self::assertSame(['contact', 'message_content'], $provider->dataCategories);
    }

    public function testDisabledLocalMailDoesNotRegisterAProvider(): void
    {
        putenv('LOCAL_MAIL_ENABLED=false');
        $params = require dirname(__DIR__, 3) . '/config/environments/dev/params.php';
        self::assertArrayNotHasKey('privacyProcessorDefinitions', $params);
    }

    public function testExternalSmtpHostIsRejected(): void
    {
        $this->assertTransportRejected('SMTP_HOST', 'smtp.example.test');
    }

    public function testDifferentSmtpPortIsRejected(): void
    {
        $this->assertTransportRejected('SMTP_PORT', '587');
    }

    public function testTlsTransportIsRejected(): void
    {
        $this->assertTransportRejected('SMTP_ENCRYPTION', 'tls');
    }

    private function assertTransportRejected(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $this->expectException(\RuntimeException::class);
        require dirname(__DIR__, 3) . '/config/environments/dev/params.php';
    }
}
