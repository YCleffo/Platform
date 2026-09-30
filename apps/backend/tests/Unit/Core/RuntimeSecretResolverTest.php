<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\PlatformRuntime\RuntimeSecretResolver;
use App\PlatformRuntime\HttpDeploymentStatusReporter;
use PHPUnit\Framework\TestCase;

final class RuntimeSecretResolverTest extends TestCase
{
    public function testEnvReferenceResolvesCredentialAndMissingFailsClosed(): void
    {
        $secret = '00000000-0000-4000-8000-000000000001.' . str_repeat('a', 43);
        putenv('TEST_RUNTIME_SECRET=' . $secret);
        try {
            self::assertSame($secret, (new RuntimeSecretResolver())
                ->deploymentCredential('env:TEST_RUNTIME_SECRET'));
        } finally { putenv('TEST_RUNTIME_SECRET'); }
        $this->expectException(\DomainException::class);
        (new RuntimeSecretResolver())->deploymentCredential('env:TEST_RUNTIME_SECRET');
    }

    public function testRawSecretReferenceIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        (new RuntimeSecretResolver())->deploymentCredential('inline:secret');
    }

    public function testStatusReporterAcceptsOnlyPredefinedMachineCodes(): void
    {
        $reporter = new HttpDeploymentStatusReporter(new RuntimeSecretResolver(),
            'https://control.example', 'env:TEST_RUNTIME_SECRET');
        $this->expectException(\DomainException::class);
        $reporter->failed('Stack trace: secret');
    }
}
