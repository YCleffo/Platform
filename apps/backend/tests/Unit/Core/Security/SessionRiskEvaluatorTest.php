<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Application\SessionRiskEvaluator;
use App\Core\Security\Domain\SessionRiskInput;
use App\Core\Security\Domain\SessionRiskLevel;
use Codeception\Test\Unit;
use DateTimeImmutable;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;

final class SessionRiskEvaluatorTest extends Unit
{
    public function testNormalStableSession(): void
    {
        $assessment = (new SessionRiskEvaluator())->evaluate($this->input());

        assertSame(SessionRiskLevel::NORMAL, $assessment->level);
        assertSame(0, $assessment->score);
        assertSame([], $assessment->signals);
    }

    public function testNewDeviceRequestsReviewButDoesNotBlock(): void
    {
        $assessment = (new SessionRiskEvaluator())->evaluate($this->input(
            currentFingerprint: 'new-fingerprint',
        ));

        assertSame(SessionRiskLevel::REVIEW, $assessment->level);
        assertContains('new_device', $assessment->signals);
    }

    public function testFastCountryAndDeviceChangesAreHighRiskSignals(): void
    {
        $assessment = (new SessionRiskEvaluator())->evaluate($this->input(
            currentFingerprint: 'new-fingerprint',
            currentCountry: 'DE',
            occurredAt: new DateTimeImmutable('2026-09-20T10:30:00Z'),
        ));

        assertSame(SessionRiskLevel::HIGH_RISK, $assessment->level);
        assertContains('country_change', $assessment->signals);
        assertContains('fast_country_change', $assessment->signals);
    }

    public function testTimezoneMismatchIsAdvisoryOnly(): void
    {
        $assessment = (new SessionRiskEvaluator())->evaluate($this->input(
            geoTimezone: 'Europe/Berlin',
        ));

        assertSame(SessionRiskLevel::NORMAL, $assessment->level);
        assertSame(10, $assessment->score);
        assertContains('timezone_mismatch', $assessment->signals);
    }

    private function input(
        string $currentFingerprint = 'same-fingerprint',
        string $currentCountry = 'US',
        ?DateTimeImmutable $occurredAt = null,
        string $geoTimezone = 'America/New_York',
    ): SessionRiskInput {
        return new SessionRiskInput(
            previousFingerprintHash: 'same-fingerprint',
            currentFingerprintHash: $currentFingerprint,
            previousClientInstanceId: 'instance-1',
            currentClientInstanceId: 'instance-1',
            previousBrowserName: 'Chrome',
            currentBrowserName: 'Chrome',
            previousOsName: 'Windows',
            currentOsName: 'Windows',
            previousCountryCode: 'US',
            currentCountryCode: $currentCountry,
            previousSeenAt: new DateTimeImmutable('2026-09-20T10:00:00Z'),
            occurredAt: $occurredAt ?? new DateTimeImmutable('2026-09-21T10:00:00Z'),
            resolvedTimezone: 'America/New_York',
            geoTimezone: $geoTimezone,
        );
    }
}
