<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Billing\Application\RenewalDunningPolicy;
use ControlPlane\Payment\Provider\ProviderPaymentFailure;
use PHPUnit\Framework\TestCase;

final class RenewalDunningPolicyTest extends TestCase
{
    public function testRetryAndGraceScheduleIsDeterministic(): void
    {
        $policy = new RenewalDunningPolicy(5, '3600,21600,86400,259200', 604800, 300);
        $now = new \DateTimeImmutable('2026-02-15T10:00:00Z');
        self::assertSame('2026-02-15T11:00:00+00:00', $policy->retryAt(1, $now)?->format('c'));
        self::assertSame('2026-02-15T16:00:00+00:00', $policy->retryAt(2, $now)?->format('c'));
        self::assertNull($policy->retryAt(5, $now));
        self::assertSame('2026-02-22T10:00:00+00:00', $policy->graceDeadline($now)->format('c'));
        self::assertSame('2026-02-15T10:05:00+00:00', $policy->uncertainRetryAt($now)->format('c'));
        self::assertFalse($policy->consumesAttempt(ProviderPaymentFailure::OPERATIONAL));
        self::assertTrue($policy->mayRetry(ProviderPaymentFailure::RETRYABLE));
        self::assertFalse($policy->mayRetry(ProviderPaymentFailure::PAYMENT_METHOD_INVALID));
    }

    public function testInvalidPolicyFailsAtStartupBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RenewalDunningPolicy(5, '60,30,20,10', 3600, 5);
    }

    public function testSingleAttemptPolicyNeedsNoRetryDelay(): void
    {
        $policy = new RenewalDunningPolicy(1, '', 3600, 30);
        self::assertSame(1, $policy->maximumAttempts());
        self::assertNull($policy->retryAt(1, new \DateTimeImmutable('2026-02-15T10:00:00Z')));
    }
}
