<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\CorrectionCommand;
use App\Core\Privacy\Application\CorrectionContext;
use App\Core\Privacy\Application\CorrectionResult;
use App\Core\Privacy\Application\DataSubjectRequestTypeRegistry;
use App\Core\Privacy\Application\PersonalDataContributorRegistry;
use App\Core\Privacy\Application\PersonalDataCorrectionContributor;
use App\Core\Privacy\Application\PersonalDataCorrectionContributorRegistry;
use App\Core\Privacy\Application\PrivacyRequestExecutionCapabilityRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributor;
use App\Core\Privacy\Application\ProcessingRestrictionContributorRegistry;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PrivacyContributorRegistryTest extends TestCase
{
    public function testCorrectionAndRestrictionRegistriesRejectDuplicates(): void
    {
        $contributor = new class implements PersonalDataCorrectionContributor {
            public function code(): string { return 'test.correction'; }
            public function supportedResourceTypes(): array { return ['test.resource']; }
            public function apply(CorrectionContext $context, CorrectionCommand $command): CorrectionResult
            { return new CorrectionResult('test.resource', $command->resourceId, ['value']); }
        };
        self::assertSame($contributor,
            (new PersonalDataCorrectionContributorRegistry([$contributor]))->get('test.correction'));
        try {
            new PersonalDataCorrectionContributorRegistry([$contributor, $contributor]);
            self::fail('Duplicate correction contributor was accepted.');
        } catch (DomainException) { self::assertTrue(true); }

        $restriction = new class implements ProcessingRestrictionContributor {
            public function code(): string { return 'test.restriction'; }
            public function supports(string $scopeType, ?string $purposeCode, ?string $resourceType): bool
            { return $scopeType === 'subject'; }
            public function applyRestriction(string $blockId, string $subjectPersonId): void {}
            public function releaseRestriction(string $blockId, string $subjectPersonId): void {}
        };
        self::assertCount(1, (new ProcessingRestrictionContributorRegistry([$restriction]))->forScope('subject', null, null));
        self::assertCount(0, (new ProcessingRestrictionContributorRegistry([$restriction]))->forScope('purpose', null, null));
        $this->expectException(DomainException::class);
        new ProcessingRestrictionContributorRegistry([$restriction, $restriction]);
    }

    public function testUnsupportedCapabilitiesStayUnavailable(): void
    {
        $registry = new PrivacyRequestExecutionCapabilityRegistry(
            new PersonalDataContributorRegistry([]),
            new PersonalDataCorrectionContributorRegistry([]),
            new ProcessingRestrictionContributorRegistry([]),
            new DataSubjectRequestTypeRegistry(),
        );
        foreach (['access', 'export', 'correction', 'restriction', 'erasure',
            'processing_termination', 'processing_information', 'consent_withdrawal'] as $type) {
            self::assertFalse($registry->available($type));
        }
    }
}
