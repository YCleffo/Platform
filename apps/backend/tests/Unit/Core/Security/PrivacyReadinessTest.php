<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\BusinessCalendarInterface;
use App\Core\Privacy\Application\ComplianceReadinessChecker;
use App\Core\Privacy\Application\CrossBorderTransferRegistry;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\DataRecipientRegistry;
use App\Core\Privacy\Application\DataResidencyEvidence;
use App\Core\Privacy\Application\DestructionEvidencePolicy;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\ProcessingRoleRegistry;
use App\Core\Privacy\Application\ProcessingInformationRegistry;
use App\Core\Privacy\Application\ProcessorRegistry;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Infrastructure\ConfiguredBusinessCalendar;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PrivacyReadinessTest extends TestCase
{
    public function testMissingResidencyFailsProductionReadiness(): void
    {
        $calendar = new PrivacyDeadlinePolicy(new class implements BusinessCalendarInterface {
            public function isWorkingDay(DateTimeImmutable $date): bool
            {
                return true;
            }
            public function addWorkingDays(DateTimeImmutable $from, int $days): DateTimeImmutable
            {
                return $from->modify('+' . $days . ' days');
            }
        });
        $retention = new RetentionPolicyRegistry();
        $checker = new ComplianceReadinessChecker(
            new ProcessingPurposeRegistry([], new DataCategoryRegistry(), $retention),
            $retention,
            new DestructionEvidencePolicy($calendar),
            new ProcessorRegistry(),
            new DataRecipientRegistry(),
            new CrossBorderTransferRegistry(),
            new ProcessingRoleRegistry(),
            new DataResidencyEvidence(),
            new ProcessingInformationRegistry(),
            [],
            ['initialHours' => 24, 'investigationHours' => 72],
        );
        $result = $checker->check(true);
        self::assertSame('FAIL', $result['result']);
        self::assertContains('residency_evidence', $result['missingRequirements']);
        self::assertContains('rkn_notification_evidence', $result['missingRequirements']);
        self::assertContains('business_calendar_coverage', $result['missingRequirements']);
        self::assertContains('destruction_evidence_3_years', $result['missingRequirements']);
        self::assertContains('processors_recipients', $result['missingRequirements']);
        self::assertContains('legal_destruction_package', $result['missingRequirements']);
        self::assertContains('operator_identity', $result['missingRequirements']);
        self::assertContains('security_matrix_references', $result['missingRequirements']);
        self::assertContains('coverage_unavailable', $result['missingContributor']);
    }

    public function testExpiredProductionCalendarCoverageFails(): void
    {
        $calendar = new ConfiguredBusinessCalendar(
            '2026-01-01',
            '2026-09-30',
            sourceReference: 'urn:calendar:approved',
            verifiedAt: '2026-09-01T00:00:00Z',
        );
        self::assertFalse($calendar->coversCurrentPeriod(new DateTimeImmutable('2026-09-30T10:00:00Z')));
        self::assertTrue((new ConfiguredBusinessCalendar(
            '2026-01-01',
            '2026-12-31',
            sourceReference: 'urn:calendar:approved',
            verifiedAt: '2026-09-01T00:00:00Z',
        ))
            ->coversCurrentPeriod(new DateTimeImmutable('2026-09-30T10:00:00Z')));
    }
}
