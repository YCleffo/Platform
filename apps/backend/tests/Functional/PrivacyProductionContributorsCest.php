<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Person\Application\ListPersonContacts;
use App\Core\Privacy\Application\CorePrivacyContributorCoverage;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\PersonalDataErasureHandlerRegistry;
use App\Core\Privacy\Application\ProcessingTerminationService;
use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Privacy\Infrastructure\CoreResourceRetentionCandidateContributor;
use App\Core\Privacy\Application\ComplianceReadinessChecker;
use App\Core\Privacy\Application\ErasureCaseService;
use App\Core\Privacy\Application\ErasureCaseStateMachine;
use App\Core\Privacy\Application\ErasureExecutor;
use App\Core\Privacy\Application\ErasureRequestCompletionService;
use App\Core\Privacy\Application\LawfulProcessingBasisContributorRegistry;
use App\Core\Privacy\Application\LawfulProcessingBasisResolver;
use App\Core\Privacy\Application\LegalDestructionPackageService;
use App\Core\Privacy\Application\LegalHoldService;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\RetentionDecisionService;
use App\Core\Privacy\Application\RetentionDependencyContributorRegistry;
use App\Core\Privacy\Application\RetentionEvaluator;
use App\Core\Privacy\Application\PrivacyRequestExecutionCapabilityRegistry;
use App\Core\Auth\Application\AuthCleanupRepository;
use App\Core\Audit\Application\AuditService;
use App\Core\Privacy\Application\DestructionEvidencePolicy;
use App\Core\Time\Application\ClockInterface;
use App\Core\AccountSettings\Application\AccountSettingsService;
use App\Core\Privacy\Application\ProcessingBlockService;
use App\Core\Privacy\Infrastructure\CoreResourceBasisContributor;
use App\Core\Privacy\Infrastructure\CoreResourceDependencyContributor;
use App\Console\PrivacyComplianceCheckCommand;
use Symfony\Component\Console\Tester\CommandTester;
use App\Core\Organization\Application\OrganizationRepository;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PrivacyProductionContributorsCest
{
    public function withdrawnConsentDoesNotEraseWhileAnotherPurposeRemainsActive(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        ))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name)
                VALUES ('Basis', 'Contributor') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status)
                VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text",
                [':subject' => $subject],
            )->queryScalar();
            $contact = (string) $db->createCommand(
                "INSERT INTO core.person_contacts
                (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'basis-contributor@example.test',
                    'basis-contributor@example.test') RETURNING id::text",
                [':subject' => $subject],
            )->queryScalar();
            foreach ([['test.basis_a', true], ['test.basis_b', true], ['test.basis_b', false]] as [$purpose, $withdrawn]) {
                $db->createCommand(<<<'SQL'
                    INSERT INTO core.privacy_consents (granted_by_account_id, subject_person_id,
                        granted_by_person_id, purpose_code, consent_code, document_version,
                        document_sha256, locale, source, granted_at, withdrawn_at)
                    VALUES (CAST(:account AS uuid), CAST(:subject AS uuid), CAST(:subject AS uuid),
                        :purpose, :purpose, 'v1', decode(repeat('a', 64), 'hex'), 'en-US', 'test',
                        now() - interval '2 days',
                        CASE WHEN :withdrawn THEN now() - interval '1 day' ELSE NULL END)
                    SQL, [':account' => $account, ':subject' => $subject, ':purpose' => $purpose,
                    ':withdrawn' => $withdrawn])->execute();
            }
            $policies = new RetentionPolicyRegistry([new RetentionPolicyDefinition(
                'test.basis_policy',
                'withdrawal',
                null,
                'delete',
                durationUnit: 'event_only',
            )]);
            $purposes = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition(
                    'test.basis_a',
                    'consent',
                    true,
                    false,
                    ['identity'],
                    ['person'],
                    'test.basis_policy',
                    'operator',
                ),
                new ProcessingPurposeDefinition(
                    'test.basis_b',
                    'consent',
                    true,
                    false,
                    ['identity'],
                    ['person'],
                    'test.basis_policy',
                    'operator',
                ),
            ], $container->get(DataCategoryRegistry::class), $policies);
            $candidates = (new CoreResourceRetentionCandidateContributor(
                $db,
                $purposes,
                $policies,
                'core.person_contact',
            ))->candidates(10, $subject);
            assertSame(2, count($candidates));
            assertSame($contact, $candidates[0]->resourceId);
            $basis = new LawfulProcessingBasisResolver(
                $purposes,
                new LawfulProcessingBasisContributorRegistry([new CoreResourceBasisContributor($purposes)]),
                $db,
            );
            assertSame('inactive', $basis->resolve($candidates[0]));
            assertSame('active', $basis->resolve($candidates[1]));
            $evaluator = new RetentionEvaluator(
                $purposes,
                $policies,
                $container->get(PrivacyDeadlinePolicy::class),
                $container->get(LegalHoldService::class),
                $basis,
                new RetentionDependencyContributorRegistry(),
                $container->get(ClockInterface::class),
            );
            assertSame('eligible_delete', $evaluator->evaluate($candidates[0])->code);
            assertSame('blocked_by_active_basis', $evaluator->evaluate($candidates[1])->code);
            assertSame('retain', $evaluator->aggregate([
                $evaluator->evaluate($candidates[0]), $evaluator->evaluate($candidates[1])]));
        } finally {
            $transaction->rollBack();
        }
    }

    public function exitedMembershipRunsRealErasureHandlerAndEvidence(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        ))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name)
                VALUES ('Erasure', 'Contributor') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status)
                VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text",
                [':subject' => $subject],
            )->queryScalar();
            $organization = (string) $db->createCommand("INSERT INTO core.organizations (code, name)
                VALUES ('privacy-erasure-contributor', 'Privacy erasure') RETURNING id::text")->queryScalar();
            $membership = (string) $db->createCommand(
                "INSERT INTO core.organization_memberships
                (organization_id, person_id, status, left_at)
                VALUES (CAST(:organization AS uuid), CAST(:subject AS uuid), 'left', now()) RETURNING id::text",
                [':organization' => $organization, ':subject' => $subject],
            )->queryScalar();
            $policies = new RetentionPolicyRegistry([new RetentionPolicyDefinition(
                'test.membership_exit',
                'relationship_end',
                null,
                'delete',
                durationUnit: 'event_only',
            )]);
            $purposes = new ProcessingPurposeRegistry([new ProcessingPurposeDefinition(
                'test.membership_exit',
                'consent',
                true,
                false,
                ['identity'],
                ['person'],
                'test.membership_exit',
                'operator',
            )], $container->get(DataCategoryRegistry::class), $policies);
            $contributor = new CoreResourceRetentionCandidateContributor(
                $db,
                $purposes,
                $policies,
                'core.organization_membership',
            );
            $candidates = new RetentionCandidateContributorRegistry([$contributor]);
            $candidate = $candidates->collect(10, $subject)[0];
            assertSame($membership, $candidate->resourceId);
            assertTrue($candidate->triggeredAt !== null);
            $clock = $container->get(ClockInterface::class);
            $audit = $container->get(AuditService::class);
            $unknownPurposes = new ProcessingPurposeRegistry([new ProcessingPurposeDefinition(
                'test.unknown_basis',
                'contract',
                false,
                false,
                ['identity'],
                ['person'],
                'test.membership_exit',
                'operator',
            )], $container->get(DataCategoryRegistry::class), $policies);
            $unknownCandidate = (new CoreResourceRetentionCandidateContributor(
                $db,
                $unknownPurposes,
                $policies,
                'core.organization_membership',
            ))->candidates(10, $subject)[0];
            $unknownBasis = new LawfulProcessingBasisResolver(
                $unknownPurposes,
                new LawfulProcessingBasisContributorRegistry([new CoreResourceBasisContributor($unknownPurposes)]),
                $db,
            );
            $unknownEvaluator = new RetentionEvaluator(
                $unknownPurposes,
                $policies,
                $container->get(PrivacyDeadlinePolicy::class),
                $container->get(LegalHoldService::class),
                $unknownBasis,
                new RetentionDependencyContributorRegistry([new CoreResourceDependencyContributor($db)]),
                $clock,
            );
            assertSame('blocked_by_unknown_basis', $unknownEvaluator->evaluate($unknownCandidate)->code);
            $basis = new LawfulProcessingBasisResolver(
                $purposes,
                new LawfulProcessingBasisContributorRegistry([new CoreResourceBasisContributor($purposes)]),
                $db,
            );
            $evaluator = new RetentionEvaluator(
                $purposes,
                $policies,
                $container->get(PrivacyDeadlinePolicy::class),
                $container->get(LegalHoldService::class),
                $basis,
                new RetentionDependencyContributorRegistry([new CoreResourceDependencyContributor($db)]),
                $clock,
            );
            assertSame('eligible_delete', $evaluator->evaluate($candidate)->code);
            $decisions = new RetentionDecisionService($db, $evaluator, $clock, $audit);
            $handlers = $container->get(PersonalDataErasureHandlerRegistry::class);
            $cases = new ErasureCaseService($db, $clock, $candidates, $purposes, $decisions, $handlers, $audit);
            $actor = new AuthenticatedIdentity(
                $account,
                $subject,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $caseId = $cases->create($actor, $subject, 'verified_erasure');
            $legalPackages = new LegalDestructionPackageService(
                $db,
                $purposes,
                ['name' => 'Test operator', 'address' => 'Test address'],
                ['informationSystemName' => 'Core', 'informationSystemCode' => 'core',
                    'destructionMethod' => 'transactional_delete'],
            );
            $executor = new ErasureExecutor(
                $db,
                $clock,
                $candidates,
                $purposes,
                $decisions,
                $handlers,
                $container->get(DestructionEvidencePolicy::class),
                new ErasureCaseStateMachine(),
                $audit,
                $container->get(ErasureRequestCompletionService::class),
                $legalPackages,
            );
            assertSame('completed', $executor->executeBatch($actor, $caseId)['status']);
            assertSame(0, (int) $db->createCommand("SELECT count(*) FROM core.organization_memberships
                WHERE id = CAST(:id AS uuid)", [':id' => $membership])->queryScalar());
            assertSame(1, (int) $db->createCommand("SELECT count(*) FROM core.privacy_destruction_evidence
                WHERE case_id = CAST(:id AS uuid)", [':id' => $caseId])->queryScalar());
        } finally {
            $transaction->rollBack();
        }
    }

    public function realContributorsRestrictUseAndCompleteTermination(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        ))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name)
                VALUES ('Privacy', 'Contributor') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status)
                VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text",
                [':subject' => $subject],
            )->queryScalar();
            $contact = (string) $db->createCommand(
                "INSERT INTO core.person_contacts
                (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'privacy-contributor@example.test',
                    'privacy-contributor@example.test') RETURNING id::text",
                [':subject' => $subject],
            )->queryScalar();
            $db->createCommand("INSERT INTO core.account_settings (account_id, settings)
                VALUES (CAST(:account AS uuid), '{}'::jsonb)", [':account' => $account])->execute();
            $organization = (string) $db->createCommand("INSERT INTO core.organizations (code, name)
                VALUES ('privacy-contributor-test', 'Privacy contributor test') RETURNING id::text")->queryScalar();
            $membership = (string) $db->createCommand("INSERT INTO core.organization_memberships
                (organization_id, person_id) VALUES (CAST(:organization AS uuid), CAST(:subject AS uuid))
                RETURNING id::text", [':organization' => $organization, ':subject' => $subject])->queryScalar();

            $candidates = $container->get(RetentionCandidateContributorRegistry::class)->collect(100, $subject);
            $resources = array_unique(array_map(static fn($candidate): string => $candidate->resourceType, $candidates));
            assertTrue(in_array('core.person_contact', $resources, true));
            assertTrue(in_array('core.organization_membership', $resources, true));
            $settingsPolicy = new RetentionPolicyRegistry([
                new RetentionPolicyDefinition(
                    'test.settings',
                    'withdrawal',
                    null,
                    'delete',
                    durationUnit: 'event_only',
                ),
            ]);
            $settingsPurpose = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition(
                    'test.settings',
                    'consent',
                    true,
                    false,
                    ['preferences'],
                    ['person'],
                    'test.settings',
                    'operator',
                ),
            ], $container->get(DataCategoryRegistry::class), $settingsPolicy);
            $settingsContributor = new CoreResourceRetentionCandidateContributor(
                $db,
                $settingsPurpose,
                $settingsPolicy,
                'core.account_settings',
            );
            $settingsCandidates = $settingsContributor->candidates(10, $subject);
            assertSame('core.account_settings', $settingsCandidates[0]->resourceType);
            assertSame($account, $settingsCandidates[0]->resourceId);
            foreach ($candidates as $candidate) {
                assertSame($subject, $candidate->subjectPersonId);
                assertFalse(str_contains(json_encode($candidate, JSON_THROW_ON_ERROR), 'privacy-contributor@example.test'));
            }
            assertTrue($container->get(PersonalDataErasureHandlerRegistry::class)
                ->forAction('core.person_contact', 'delete') !== null);
            assertTrue($container->get(PersonalDataErasureHandlerRegistry::class)
                ->forAction('core.account_settings', 'delete') !== null);
            assertTrue($container->get(PersonalDataErasureHandlerRegistry::class)
                ->forAction('core.organization_membership', 'delete') !== null);
            assertTrue($container->get(CorePrivacyContributorCoverage::class)->missing(true) !== []);
            assertFalse($container->get(PrivacyRequestExecutionCapabilityRegistry::class)->available('erasure'));
            assertTrue($container->get(AuthCleanupRepository::class) instanceof AuthCleanupRepository);
            assertSame(null, $container->get(PersonalDataErasureHandlerRegistry::class)
                ->forAction('core.person', 'delete'));
            assertSame(null, $container->get(PersonalDataErasureHandlerRegistry::class)
                ->forAction('core.account', 'delete'));
            assertTrue($container->get(ComplianceReadinessChecker::class)->check(false)['missingContributor'] !== []);
            $command = new CommandTester($container->get(PrivacyComplianceCheckCommand::class));
            assertSame(1, $command->execute([]));
            assertTrue(str_contains($command->getDisplay(), 'missingContributor: '));

            $actor = new AuthenticatedIdentity(
                $account,
                $subject,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $blocks = $container->get(ProcessingBlockService::class);
            $settingsBlock = $blocks->createBlock(
                $actor,
                $subject,
                'resource',
                null,
                'core.account_settings',
                $account,
                'subject_request',
            );
            try {
                $container->get(AccountSettingsService::class)->get($account);
                throw new \RuntimeException('Blocked account settings were returned.');
            } catch (\DomainException $expected) {
                assertSame('Account settings processing is restricted.', $expected->getMessage());
            }
            $blocks->releaseBlock($actor, $settingsBlock, 'review_complete');
            $requests = $container->get(DataSubjectRequestService::class);
            $requestId = $requests->submitSelf($actor, 'processing_termination');
            $requests->transition($actor, $requestId, 'in_review', null);
            $requests->transition($actor, $requestId, 'in_progress', null);
            $result = $container->get(ProcessingTerminationService::class)->execute($actor, $requestId);
            assertSame('completed', $result['status']);
            assertSame('completed', $requests->getVisible($actor, $requestId, true)?->status);
            assertSame([], $container->get(ListPersonContacts::class)->execute($subject));
            assertFalse($container->get(OrganizationRepository::class)->activeMember($subject, $organization));
            assertSame(1, (int) $db->createCommand("SELECT count(*) FROM core.person_contacts
                WHERE id = CAST(:id AS uuid)", [':id' => $contact])->queryScalar());
            assertSame(1, (int) $db->createCommand("SELECT count(*) FROM core.organization_memberships
                WHERE id = CAST(:id AS uuid)", [':id' => $membership])->queryScalar());
        } finally {
            $transaction->rollBack();
        }
    }
}
