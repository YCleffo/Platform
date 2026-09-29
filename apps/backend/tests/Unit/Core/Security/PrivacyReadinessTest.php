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
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PrivacyReadinessTest extends TestCase
{
    public function testMissingResidencyFailsProductionReadiness(): void
    {
        $calendar = new PrivacyDeadlinePolicy(new class implements BusinessCalendarInterface {
            public function isWorkingDay(DateTimeImmutable $date): bool { return true; }
            public function addWorkingDays(DateTimeImmutable $from, int $days): DateTimeImmutable {
                return $from->modify('+' . $days . ' days');
            }
        });
        $retention = new RetentionPolicyRegistry();
        $checker = new ComplianceReadinessChecker(
            new ProcessingPurposeRegistry([], new DataCategoryRegistry(), $retention),
            $retention, new DestructionEvidencePolicy($calendar),
            new ProcessorRegistry(), new DataRecipientRegistry(),
            new CrossBorderTransferRegistry(), new ProcessingRoleRegistry(),
            new DataResidencyEvidence(), new ProcessingInformationRegistry(),
            [], ['initialHours' => 24, 'investigationHours' => 72]);
        $result = $checker->check(true);
        self::assertSame('FAIL', $result['result']);
        self::assertContains('residency_evidence', $result['missingRequirements']);
    }
}
