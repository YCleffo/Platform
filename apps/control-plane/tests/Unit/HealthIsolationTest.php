<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Infrastructure\Http\HealthController;
use PHPUnit\Framework\TestCase;

final class HealthIsolationTest extends TestCase
{
    public function testProofIsExposedOnlyForTestEnvironmentWithNonce(): void
    {
        $nonce = str_repeat('a', 64);
        $response = (new HealthController('test', $nonce))->isolation();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['status' => 'ok', 'nonce' => hash('sha256', $nonce)],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testProofIsHiddenOutsideTestEnvironment(): void
    {
        $response = (new HealthController('prod', str_repeat('a', 64)))->isolation();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['status']);
    }

    public function testProofIsHiddenWhenNonceIsMissingOrShort(): void
    {
        foreach (['', 'too-short'] as $nonce) {
            $response = (new HealthController('test', $nonce))->isolation();
            self::assertSame(404, $response->getStatusCode());
        }
    }
}
