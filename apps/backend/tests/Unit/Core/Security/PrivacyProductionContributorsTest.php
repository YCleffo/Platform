<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\CorePrivacyContributorCoverage;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\LawfulProcessingBasisContributorRegistry;
use App\Core\Privacy\Application\PersonalDataErasureHandlerRegistry;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributorRegistry;
use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\RetentionDependencyContributorRegistry;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Privacy\Infrastructure\CoreProcessingRestrictionContributor;
use App\Core\Privacy\Infrastructure\CoreResourceBasisContributor;
use App\Core\Privacy\Infrastructure\CoreResourceDependencyContributor;
use App\Core\Privacy\Infrastructure\CoreRetentionCandidateProvider;
use App\Core\Privacy\Infrastructure\CoreErasureHandlerProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Connection\ConnectionInterface;

final class PrivacyProductionContributorsTest extends TestCase
{
    public function testUnknownNonConsentBasisCannotAuthorizeErasure(): void
    {
        $policies = new RetentionPolicyRegistry([new RetentionPolicyDefinition(
            'test.policy', 'relationship_end', null, 'delete', durationUnit: 'event_only')]);
        $purposes = new ProcessingPurposeRegistry([new ProcessingPurposeDefinition(
            'test.purpose', 'contract', false, false, ['identity'], ['person'], 'test.policy', 'operator')],
            new DataCategoryRegistry(), $policies);
        $basis = new CoreResourceBasisContributor($purposes);
        self::assertFalse($basis->supports('test.purpose', 'core.organization_membership'));
        $candidate = new RetentionCandidate('018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
            'core.organization_membership', '018f2892-bbbb-7bbb-8bbb-eeeeeeeeeeee',
            'test.purpose', 'test.policy', 'relationship_end', new \DateTimeImmutable(),
            'core.organization_membership.retention');
        self::assertSame('unknown', (new LawfulProcessingBasisContributorRegistry([$basis]))->resolve($candidate));
    }

    public function testCoverageReportsUnsupportedSubjectErasureAndMissingBasis(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $policies = new RetentionPolicyRegistry([new RetentionPolicyDefinition(
            'test.policy', 'relationship_end', null, 'delete', durationUnit: 'event_only')]);
        $purposes = new ProcessingPurposeRegistry([new ProcessingPurposeDefinition(
            'test.purpose', 'contract', false, false, ['identity'], ['person'], 'test.policy', 'operator')],
            new DataCategoryRegistry(), $policies);
        $coverage = new CorePrivacyContributorCoverage($purposes, $policies,
            new RetentionCandidateContributorRegistry(new CoreRetentionCandidateProvider($db, $purposes, $policies)),
            new LawfulProcessingBasisContributorRegistry([new CoreResourceBasisContributor($purposes)]),
            new RetentionDependencyContributorRegistry([new CoreResourceDependencyContributor($db)]),
            new ProcessingRestrictionContributorRegistry([new CoreProcessingRestrictionContributor($purposes)]),
            new PersonalDataErasureHandlerRegistry(new CoreErasureHandlerProvider($db)));
        $missing = $coverage->missing(true);
        self::assertContains('test.purpose:core.person:erasure_handler', $missing);
        self::assertContains('test.purpose:core.account:erasure_handler', $missing);
        self::assertContains('test.purpose:core.person_contact:lawful_basis', $missing);
        self::assertNotContains('test.purpose:core.person_contact:retention', $missing);
        self::assertNotContains('test.purpose:core.organization_membership:dependency', $missing);
    }
}
