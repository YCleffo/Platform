<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Audit\Application\AuditService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\LawfulProcessingBasisContributor;
use App\Core\Privacy\Application\LawfulProcessingBasisContributorRegistry;
use App\Core\Privacy\Application\LawfulProcessingBasisResolver;
use App\Core\Privacy\Application\LegalHoldService;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionDependencyContributorRegistry;
use App\Core\Privacy\Application\RetentionEvaluator;
use App\Core\Privacy\Application\RetentionDecisionService;
use App\Core\Privacy\Application\RetentionCandidateContributor;
use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributor;
use App\Core\Privacy\Application\ProcessingRestrictionContributorRegistry;
use App\Core\Privacy\Application\ProcessingTerminationService;
use App\Core\Privacy\Application\ProcessingBlockService;
use App\Core\Privacy\Application\PrivacyRequestExecutionCapabilityRegistry;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\DataSubjectRequestStateMachine;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Privacy\Infrastructure\ConfiguredBusinessCalendar;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PrivacyRetentionB3Cest
{
    public function unknownHoldAndMultiPurposeStaySafe(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        assertTrue($container->get(ProcessingTerminationService::class) instanceof ProcessingTerminationService);
        assertTrue($container->get(PrivacyRequestExecutionCapabilityRegistry::class)->available('processing_termination'));
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('PrivateSentinel', 'Subject') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text",
                [':subject' => $subject],
            )->queryScalar();
            $actor = new AuthenticatedIdentity(
                $account,
                $subject,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $clock = new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2030-01-02T00:00:00Z');
                }
            };
            $hold = new LegalHoldService($db, $clock, $container->get(AuditService::class));
            $policies = new RetentionPolicyRegistry([
                new RetentionPolicyDefinition('b3.delete', 'purpose_completed', 0, 'delete', durationUnit: 'hours'),
                new RetentionPolicyDefinition('b3.retain', 'purpose_completed', 0, 'delete', durationUnit: 'hours'),
                new RetentionPolicyDefinition('b3.anonymize', 'purpose_completed', 0, 'anonymize', durationUnit: 'hours'),
            ]);
            $categories = $container->get(\App\Core\Privacy\Application\DataCategoryRegistry::class);
            $purposes = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition('b3.delete', 'consent', true, false, ['identity'], ['person'], 'b3.delete', 'operator'),
                new ProcessingPurposeDefinition('b3.retain', 'contract', false, false, ['identity'], ['person'], 'b3.retain', 'operator'),
                new ProcessingPurposeDefinition('b3.anonymize', 'consent', true, false, ['identity'], ['person'], 'b3.anonymize', 'operator'),
            ], $categories, $policies);
            $basisContributor = new class implements LawfulProcessingBasisContributor {
                public function code(): string
                {
                    return 'b3.active';
                }
                public function supports(string $purposeCode, string $resourceType): bool
                {
                    return $purposeCode === 'b3.retain';
                }
                public function resolve(RetentionCandidate $candidate): string
                {
                    return 'active';
                }
            };
            $basis = new LawfulProcessingBasisResolver(
                $purposes,
                new LawfulProcessingBasisContributorRegistry([$basisContributor]),
                $db,
            );
            $evaluator = new RetentionEvaluator(
                $purposes,
                $policies,
                new PrivacyDeadlinePolicy(new ConfiguredBusinessCalendar('2029-01-01', '2031-12-31')),
                $hold,
                $basis,
                new RetentionDependencyContributorRegistry(),
                $clock,
            );
            $trigger = new DateTimeImmutable('2029-01-01T00:00:00Z');
            $delete = new RetentionCandidate(
                $subject,
                'core.person',
                $subject,
                'b3.delete',
                'b3.delete',
                'purpose_completed',
                $trigger,
                'b3.test',
            );
            $retain = new RetentionCandidate(
                $subject,
                'core.person',
                $subject,
                'b3.retain',
                'b3.retain',
                'purpose_completed',
                $trigger,
                'b3.test',
            );
            $anonymize = new RetentionCandidate(
                $subject,
                'core.person',
                $subject,
                'b3.anonymize',
                'b3.anonymize',
                'purpose_completed',
                $trigger,
                'b3.test',
            );
            $missing = new RetentionCandidate(
                $subject,
                'core.person',
                $subject,
                'b3.delete',
                'b3.missing',
                'purpose_completed',
                $trigger,
                'b3.test',
            );
            assertSame('blocked_by_missing_policy', $evaluator->evaluate($missing)->code);
            assertSame('blocked_by_unknown_basis', (new RetentionEvaluator(
                $purposes,
                $policies,
                new PrivacyDeadlinePolicy(new ConfiguredBusinessCalendar('2029-01-01', '2031-12-31')),
                $hold,
                new LawfulProcessingBasisResolver($purposes, new LawfulProcessingBasisContributorRegistry(), $db),
                new RetentionDependencyContributorRegistry(),
                $clock,
            ))->evaluate($retain)->code);
            $consentId = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_consents (granted_by_account_id, subject_person_id,
                    granted_by_person_id, purpose_code, consent_code, document_version, document_sha256,
                    locale, source, granted_at)
                VALUES (CAST(:account AS uuid), CAST(:subject AS uuid), CAST(:subject AS uuid),
                    'b3.delete', 'b3.delete', 'v1', decode(repeat('a', 64), 'hex'), 'en-US',
                    'test', '2029-01-01T00:00:00Z') RETURNING id::text
                SQL, [':account' => $account, ':subject' => $subject])->queryScalar();
            assertSame('blocked_by_active_basis', $evaluator->evaluate($delete)->code);
            $db->createCommand(
                "UPDATE core.privacy_consents SET withdrawn_at = '2029-01-02T00:00:00Z' WHERE id = CAST(:id AS uuid)",
                [':id' => $consentId],
            )->execute();
            assertSame('eligible_delete', $evaluator->evaluate($delete)->code);
            assertSame('eligible_anonymize', $evaluator->aggregate([$evaluator->evaluate($delete),
                $evaluator->evaluate($anonymize)]));
            $decisions = new RetentionDecisionService($db, $evaluator, $clock, $container->get(AuditService::class));
            assertSame('retain', $decisions->evaluateResource([$delete, $retain], $actor)['aggregate']);
            assertSame('retain', $decisions->evaluateResource([$delete, $retain], $actor)['aggregate']);
            assertSame(2, (int) $db->createCommand(
                'SELECT count(*) FROM core.privacy_retention_decisions WHERE subject_person_id = CAST(:subject AS uuid)',
                [':subject' => $subject],
            )->queryScalar());
            $decisionEvidence = (string) $db->createCommand(
                'SELECT string_agg(to_jsonb(d)::text, :separator) FROM core.privacy_retention_decisions d WHERE subject_person_id = CAST(:subject AS uuid)',
                [':separator' => ' ', ':subject' => $subject],
            )->queryScalar();
            assertFalse(str_contains($decisionEvidence, 'PrivateSentinel'));
            $decisions->evaluateResource([$anonymize]);
            assertSame(1, (int) $db->createCommand("SELECT count(*) FROM core.audit_events WHERE actor_type = 'system' AND action = 'privacy.retention.evaluated' AND resource_type = 'core.privacy_retention_decision'")->queryScalar());
            assertSame('blocked_by_active_basis', $evaluator->evaluate($retain)->code);
            assertSame('retain', $evaluator->aggregate([$evaluator->evaluate($delete), $evaluator->evaluate($retain)]));
            $id = $hold->create($actor, $subject, 'subject', null, null, null, 'legal_review');
            assertSame($id, $hold->list($subject)[0]['id']);
            $holdEvidence = (string) $db->createCommand(
                'SELECT to_jsonb(h)::text FROM core.privacy_legal_holds h WHERE id = CAST(:id AS uuid)',
                [':id' => $id],
            )->queryScalar();
            assertFalse(str_contains($holdEvidence, 'PrivateSentinel'));
            $auditEvidence = (string) $db->createCommand(
                "SELECT string_agg(metadata::text, ' ') FROM core.audit_events WHERE action LIKE 'privacy.%' AND actor_account_id = CAST(:account AS uuid)",
                [':account' => $account],
            )->queryScalar();
            assertFalse(str_contains($auditEvidence, 'PrivateSentinel'));
            assertSame($id, $hold->create($actor, $subject, 'subject', null, null, null, 'legal_review'));
            assertSame('blocked_by_legal_hold', $evaluator->evaluate($delete)->code);
            $block = new ProcessingBlockService($db, $clock, $purposes, $container->get(AuditService::class));
            $block->createBlock($actor, $subject, 'purpose', 'b3.delete', null, null, 'termination_review');
            assertTrue($block->isBlocked($subject, 'b3.delete'));
            assertTrue($hold->release($actor, $id, 'review_complete'));
            assertFalse($hold->release($actor, $id, 'review_complete'));
            assertSame('eligible_delete', $evaluator->evaluate($delete)->code);
            $hold->create(
                $actor,
                $subject,
                'subject',
                null,
                null,
                null,
                'temporary_hold',
                expiresAt: new DateTimeImmutable('2030-01-03T00:00:00Z'),
            );
            assertSame('blocked_by_legal_hold', $evaluator->evaluate($delete)->code);
            $laterClock = new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2030-01-04T00:00:00Z');
                }
            };
            $laterEvaluator = new RetentionEvaluator(
                $purposes,
                $policies,
                new PrivacyDeadlinePolicy(new ConfiguredBusinessCalendar('2029-01-01', '2031-12-31')),
                new LegalHoldService($db, $laterClock, $container->get(AuditService::class)),
                $basis,
                new RetentionDependencyContributorRegistry(),
                $laterClock,
            );
            assertSame('eligible_delete', $laterEvaluator->evaluate($delete)->code);
        } finally {
            $transaction->rollBack();
        }
    }

    public function terminationRequiresContributorAndRetryIsIdempotent(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('B3', 'Termination') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text",
                [':subject' => $subject],
            )->queryScalar();
            $actor = new AuthenticatedIdentity(
                $account,
                $subject,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $requests = $container->get(DataSubjectRequestService::class);
            $requestId = $requests->submitSelf($actor, 'processing_termination');
            $requests->transition($actor, $requestId, 'in_review', null);
            $requests->transition($actor, $requestId, 'in_progress', null);
            $candidateContributor = new class ($subject) implements RetentionCandidateContributor {
                public function __construct(private string $subject) {}
                public bool $coverRequired = false;
                public function code(): string
                {
                    return 'b3.fixture';
                }
                public function supportsPurpose(string $purposeCode): bool
                {
                    return $purposeCode === 'test.processing'
                        || ($this->coverRequired && $purposeCode === 'test.required');
                }
                public function candidates(int $limit, ?string $subjectPersonId = null): iterable
                {
                    if ($subjectPersonId === $this->subject) {
                        yield new RetentionCandidate(
                            $this->subject,
                            'core.person',
                            $this->subject,
                            'test.processing',
                            'test.consent',
                            'withdrawal',
                            null,
                            'b3.fixture',
                        );
                    }
                }
            };
            $candidates = new RetentionCandidateContributorRegistry([$candidateContributor]);
            $missing = new ProcessingTerminationService(
                $db,
                $container->get(ClockInterface::class),
                $candidates,
                $container->get(RetentionDecisionService::class),
                $container->get(LawfulProcessingBasisResolver::class),
                $container->get(ProcessingBlockService::class),
                new ProcessingRestrictionContributorRegistry(),
                $container->get(DataSubjectRequestStateMachine::class),
                $container->get(AuditService::class),
                $container->get(ProcessingPurposeRegistry::class),
            );
            try {
                $missing->execute($actor, $requestId);
                throw new \RuntimeException('Incomplete purpose coverage unexpectedly completed request.');
            } catch (\DomainException) {
                assertSame('in_progress', $requests->getVisible($actor, $requestId, true)?->status);
            }
            $candidateContributor->coverRequired = true;
            try {
                $missing->execute($actor, $requestId);
                throw new \RuntimeException('Missing restriction contributor unexpectedly completed request.');
            } catch (\DomainException) {
                assertSame('in_progress', $requests->getVisible($actor, $requestId, true)?->status);
            }
            $restriction = new class implements ProcessingRestrictionContributor {
                public int $calls = 0;
                public bool $fail = true;
                public function code(): string
                {
                    return 'b3.restriction';
                }
                public function supports(string $scopeType, ?string $purposeCode, ?string $resourceType): bool
                {
                    return $scopeType === 'purpose' && $purposeCode === 'test.processing';
                }
                public function applyRestriction(string $blockId, string $subjectPersonId): void
                {
                    $this->calls++;
                    if ($this->fail) {
                        throw new \DomainException('Fixture contributor failure.');
                    }
                }
                public function releaseRestriction(string $blockId, string $subjectPersonId): void {}
            };
            $service = new ProcessingTerminationService(
                $db,
                $container->get(ClockInterface::class),
                $candidates,
                $container->get(RetentionDecisionService::class),
                $container->get(LawfulProcessingBasisResolver::class),
                $container->get(ProcessingBlockService::class),
                new ProcessingRestrictionContributorRegistry([$restriction]),
                $container->get(DataSubjectRequestStateMachine::class),
                $container->get(AuditService::class),
                $container->get(ProcessingPurposeRegistry::class),
            );
            try {
                $service->execute($actor, $requestId);
                throw new \RuntimeException('Failing contributor unexpectedly completed request.');
            } catch (\DomainException) {
                assertSame('in_progress', $requests->getVisible($actor, $requestId, true)?->status);
            }
            $restriction->fail = false;
            $first = $service->execute($actor, $requestId);
            assertSame('completed', $first['status']);
            assertSame($first, $service->execute($actor, $requestId));
            assertSame(2, $restriction->calls);
            assertSame(1, (int) $db->createCommand(
                'SELECT count(*) FROM core.privacy_processing_blocks WHERE source_request_id = CAST(:id AS uuid)',
                [':id' => $requestId],
            )->queryScalar());
            assertSame(1, (int) $db->createCommand(
                "SELECT count(*) FROM core.data_subject_request_executions WHERE request_id = CAST(:id AS uuid) AND execution_code = 'processing_termination'",
                [':id' => $requestId],
            )->queryScalar());
            $partialRequest = $requests->submitSelf($actor, 'processing_termination');
            $requests->transition($actor, $partialRequest, 'in_review', null);
            $requests->transition($actor, $partialRequest, 'in_progress', null);
            $policyRegistry = new RetentionPolicyRegistry([
                new RetentionPolicyDefinition('test.consent', 'withdrawal', null, 'custom_handler'),
                new RetentionPolicyDefinition('b3.contract', 'relationship_ended', null, 'delete'),
            ]);
            $purposeRegistry = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition(
                    'test.processing',
                    'consent',
                    true,
                    true,
                    ['identity'],
                    ['person'],
                    'test.consent',
                    'operator',
                ),
                new ProcessingPurposeDefinition(
                    'b3.contract',
                    'contract',
                    false,
                    false,
                    ['identity'],
                    ['person'],
                    'b3.contract',
                    'operator',
                ),
            ], $container->get(\App\Core\Privacy\Application\DataCategoryRegistry::class), $policyRegistry);
            $contractBasis = new class implements LawfulProcessingBasisContributor {
                public function code(): string
                {
                    return 'b3.contract';
                }
                public function supports(string $purposeCode, string $resourceType): bool
                {
                    return $purposeCode === 'b3.contract';
                }
                public function resolve(RetentionCandidate $candidate): string
                {
                    return 'active';
                }
            };
            $basis = new LawfulProcessingBasisResolver(
                $purposeRegistry,
                new LawfulProcessingBasisContributorRegistry([$contractBasis]),
                $db,
            );
            $clock = $container->get(ClockInterface::class);
            $evaluator = new RetentionEvaluator(
                $purposeRegistry,
                $policyRegistry,
                $container->get(PrivacyDeadlinePolicy::class),
                $container->get(LegalHoldService::class),
                $basis,
                new RetentionDependencyContributorRegistry(),
                $clock,
            );
            $partialCandidates = new RetentionCandidateContributorRegistry([
                new class ($subject) implements RetentionCandidateContributor {
                    public function __construct(private string $subject) {}
                    public function code(): string
                    {
                        return 'b3.partial';
                    }
                    public function supportsPurpose(string $purposeCode): bool
                    {
                        return in_array($purposeCode, ['test.processing', 'b3.contract'], true);
                    }
                    public function candidates(int $limit, ?string $subjectPersonId = null): iterable
                    {
                        if ($subjectPersonId === $this->subject) {
                            yield new RetentionCandidate(
                                $this->subject,
                                'core.person',
                                $this->subject,
                                'test.processing',
                                'test.consent',
                                'withdrawal',
                                null,
                                'b3.partial',
                            );
                            yield new RetentionCandidate(
                                $this->subject,
                                'core.person',
                                $this->subject,
                                'b3.contract',
                                'b3.contract',
                                'relationship_ended',
                                null,
                                'b3.partial',
                            );
                        }
                    }
                },
            ]);
            $partial = new ProcessingTerminationService(
                $db,
                $clock,
                $partialCandidates,
                new RetentionDecisionService($db, $evaluator, $clock, $container->get(AuditService::class)),
                $basis,
                new ProcessingBlockService(
                    $db,
                    $clock,
                    $purposeRegistry,
                    $container->get(AuditService::class),
                ),
                new ProcessingRestrictionContributorRegistry([$restriction]),
                $container->get(DataSubjectRequestStateMachine::class),
                $container->get(AuditService::class),
                $purposeRegistry,
            );
            assertSame('partially_completed', $partial->execute($actor, $partialRequest)['status']);
            assertSame('partially_completed', $requests->getVisible($actor, $partialRequest, true)?->status);
        } finally {
            $transaction->rollBack();
        }
    }
}
