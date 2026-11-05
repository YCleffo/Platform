<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Privacy\Infrastructure\CorePersonAnonymizationHandler;
use App\Core\Privacy\Infrastructure\CoreResourceRetentionCandidateContributor;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Connection\ConnectionInterface;

final class PrivacyIdentityErasureTest extends TestCase
{
    public function testRootAndChildCandidatesUseOnlyMatchingConfiguredActions(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $policies = new RetentionPolicyRegistry([
            new RetentionPolicyDefinition(
                'test.delete',
                'withdrawal',
                null,
                'delete',
                durationUnit: 'event_only',
            ),
            new RetentionPolicyDefinition(
                'test.anonymize',
                'withdrawal',
                null,
                'anonymize',
                durationUnit: 'event_only',
            ),
        ]);
        $purposes = new ProcessingPurposeRegistry([
            new ProcessingPurposeDefinition(
                'test.delete',
                'consent',
                true,
                false,
                ['identity'],
                ['person'],
                'test.delete',
                'operator',
            ),
            new ProcessingPurposeDefinition(
                'test.anonymize',
                'consent',
                true,
                false,
                ['identity'],
                ['person'],
                'test.anonymize',
                'operator',
            ),
        ], new DataCategoryRegistry(), $policies);
        foreach (['core.person', 'core.human_account'] as $type) {
            $contributor = new CoreResourceRetentionCandidateContributor($db, $purposes, $policies, $type);
            self::assertTrue($contributor->supportsPurpose('test.anonymize'));
            self::assertFalse($contributor->supportsPurpose('test.delete'));
        }
        foreach (['core.person_contact', 'core.organization_membership'] as $type) {
            $contributor = new CoreResourceRetentionCandidateContributor($db, $purposes, $policies, $type);
            self::assertTrue($contributor->supportsPurpose('test.delete'));
            self::assertFalse($contributor->supportsPurpose('test.anonymize'));
        }
    }

    public function testPersonHandlerExposesAnonymizeOnly(): void
    {
        $handler = new CorePersonAnonymizationHandler($this->createMock(ConnectionInterface::class));
        self::assertSame('core.person', $handler->resourceType());
        self::assertTrue($handler->supportsAction('anonymize'));
        self::assertFalse($handler->supportsAction('delete'));
    }
}
