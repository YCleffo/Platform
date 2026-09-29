<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Audit\Application\AuditService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\DestructionEvidenceExporter;
use App\Core\Privacy\Application\DestructionEvidencePolicy;
use App\Core\Privacy\Application\ErasureCaseService;
use App\Core\Privacy\Application\ErasureCaseStateMachine;
use App\Core\Privacy\Application\ErasureExecutor;
use App\Core\Privacy\Application\ErasureRequestCompletionService;
use App\Core\Privacy\Application\LawfulProcessingBasisContributorRegistry;
use App\Core\Privacy\Application\LawfulProcessingBasisResolver;
use App\Core\Privacy\Application\LegalHoldService;
use App\Core\Privacy\Application\LegalDestructionPackageService;
use App\Core\Privacy\Application\PersonalDataErasureHandlerRegistry;
use App\Core\Privacy\Application\PersonalDataErasureHandler;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionCandidateContributor;
use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\RetentionDecisionService;
use App\Core\Privacy\Application\RetentionDependencyContributorRegistry;
use App\Core\Privacy\Application\RetentionEvaluator;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Privacy\Infrastructure\CorePersonContactErasureHandler;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\TestReset;
use DateTimeImmutable;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PrivacyErasureB4Cest
{
    public function doubleWorkerCreatesOneDestructionEvidence(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        try {
            $subject = (string) $db->createCommand(
                "INSERT INTO core.persons (given_name, family_name) VALUES ('Race', 'Erasure') RETURNING id::text",
            )->queryScalar();
            $account = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.accounts (person_id, account_type, status)
                VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $contact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'race-erasure@example.test',
                    'race-erasure@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $actor = new AuthenticatedIdentity($account, $subject,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', ['core.superadmin'], [], null, null);
            $policies = new RetentionPolicyRegistry([
                new RetentionPolicyDefinition('b4.race', 'purpose_completed', null, 'delete',
                    durationUnit: 'event_only'),
            ]);
            $purposes = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition('b4.race', 'consent', true, false,
                    ['identity'], ['person'], 'b4.race', 'operator'),
            ], $container->get(DataCategoryRegistry::class), $policies);
            $candidates = new RetentionCandidateContributorRegistry([
                new class($subject, $contact) implements RetentionCandidateContributor {
                    public function __construct(private string $subject, private string $contact) {}
                    public function code(): string { return 'b4.race'; }
                    public function supportsPurpose(string $purposeCode): bool { return $purposeCode === 'b4.race'; }
                    public function candidates(int $limit, ?string $subjectPersonId = null): iterable {
                        if ($subjectPersonId === $this->subject) {
                            yield new RetentionCandidate($this->subject, 'core.person_contact', $this->contact,
                                'b4.race', 'b4.race', 'purpose_completed',
                                new DateTimeImmutable('2026-01-01T00:00:00Z'), 'b4.race');
                        }
                    }
                },
            ]);
            $clock = $container->get(ClockInterface::class);
            $audit = $container->get(AuditService::class);
            $basis = new LawfulProcessingBasisResolver($purposes,
                new LawfulProcessingBasisContributorRegistry(), $db);
            $evaluator = new RetentionEvaluator($purposes, $policies,
                $container->get(PrivacyDeadlinePolicy::class),
                $container->get(LegalHoldService::class), $basis,
                new RetentionDependencyContributorRegistry(), $clock);
            $decisions = new RetentionDecisionService($db, $evaluator, $clock, $audit);
            $handlers = new PersonalDataErasureHandlerRegistry([new CorePersonContactErasureHandler($db)]);
            $cases = new ErasureCaseService($db, $clock, $candidates, $purposes, $decisions, $handlers, $audit);
            $caseId = $cases->create($actor, $subject, 'verified_erasure');
            $key = 'privacy.erasure.execute:' . $caseId;
            $lock = $db->beginTransaction();
            $db->createCommand('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                [':key' => $key])->queryScalar();
            $workers = [];
            try {
                for ($index = 0; $index < 2; ++$index) {
                    $pipes = [];
                    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/privacy-erasure-race-worker.php',
                        $account, $subject, $contact, $caseId],
                        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                    if (!is_resource($process)) {
                        throw new RuntimeException('Could not start erasure race worker.');
                    }
                    fclose($pipes[0]);
                    $workers[] = [$process, $pipes];
                }
                usleep(300000);
                $lock->commit();
            } catch (\Throwable $exception) {
                $lock->rollBack();
                throw $exception;
            }
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($process) !== 0) {
                    throw new RuntimeException('Erasure race worker failed: ' . $error);
                }
                $summary = json_decode(trim((string) $output), true, flags: JSON_THROW_ON_ERROR);
                assertSame('completed', $summary['status']);
            }
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)',
                [':id' => $caseId])->queryScalar());
            assertSame(1, (int) $db->createCommand("SELECT count(*) FROM core.audit_events WHERE action = 'privacy.erasure.item_completed' AND resource_id = :id",
                [':id' => $cases->get($caseId)['items'][0]['id']])->queryScalar());
        } finally {
            TestReset::reset($db);
        }
    }

    public function eligibleCoreContactIsDeletedWithMinimalEvidence(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.persons (given_name, family_name, birth_date)
                VALUES ('FullNameSentinel', 'PrivateSurnameSentinel', '1991-04-05') RETURNING id::text
                SQL)->queryScalar();
            $account = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.accounts (person_id, account_type, status)
                VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $contact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'private-sentinel@example.test',
                    'private-sentinel@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $actor = new AuthenticatedIdentity($account, $subject,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', ['core.superadmin'], [], null, null);
            $contributor = new class($subject, $contact) implements RetentionCandidateContributor {
                public function __construct(private string $subject, public string $contact) {}
                public function code(): string { return 'b4.contact'; }
                public function supportsPurpose(string $purposeCode): bool { return $purposeCode === 'b4.contact'; }
                public function candidates(int $limit, ?string $subjectPersonId = null): iterable {
                    if ($subjectPersonId === null || $subjectPersonId === $this->subject) {
                        yield new RetentionCandidate($this->subject, 'core.person_contact', $this->contact,
                            'b4.contact', 'b4.contact', 'purpose_completed',
                            new DateTimeImmutable('2026-01-01T00:00:00Z'), 'b4.contact');
                    }
                }
            };
            $candidates = new RetentionCandidateContributorRegistry([$contributor]);
            $policies = new RetentionPolicyRegistry([
                new RetentionPolicyDefinition('b4.contact', 'purpose_completed', null, 'delete',
                    durationUnit: 'event_only'),
            ]);
            $purposes = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition('b4.contact', 'consent', true, false,
                    ['identity'], ['person'], 'b4.contact', 'operator'),
            ], $container->get(DataCategoryRegistry::class), $policies);
            $basis = new LawfulProcessingBasisResolver($purposes,
                new LawfulProcessingBasisContributorRegistry(), $db);
            $clock = $container->get(ClockInterface::class);
            $audit = $container->get(AuditService::class);
            $evaluator = new RetentionEvaluator($purposes, $policies,
                $container->get(PrivacyDeadlinePolicy::class),
                $container->get(LegalHoldService::class), $basis,
                new RetentionDependencyContributorRegistry(), $clock);
            $decisions = new RetentionDecisionService($db, $evaluator, $clock, $audit);
            $handlers = new PersonalDataErasureHandlerRegistry([new CorePersonContactErasureHandler($db)]);
            $cases = new ErasureCaseService($db, $clock, $candidates, $purposes, $decisions, $handlers, $audit);
            $legalPackages = new LegalDestructionPackageService($db, $purposes,
                ['name' => 'Test operator', 'address' => 'Test address'],
                ['informationSystemName' => 'Core', 'informationSystemCode' => 'core',
                    'destructionMethod' => 'transactional_delete']);
            $executor = new ErasureExecutor($db, $clock, $candidates, $purposes, $decisions,
                $handlers, $container->get(DestructionEvidencePolicy::class),
                new ErasureCaseStateMachine(), $audit,
                $container->get(ErasureRequestCompletionService::class),
                $legalPackages);
            $caseId = $cases->create($actor, $subject, 'verified_erasure');
            assertSame('planned', $cases->get($caseId)['status']);
            $summary = $executor->executeBatch($actor, $caseId);
            assertSame('completed', $summary['status']);
            assertSame(1, $summary['completed']);
            assertFalse($db->createCommand('SELECT 1 FROM core.person_contacts WHERE id = CAST(:id AS uuid)',
                [':id' => $contact])->queryScalar() !== false);
            $evidence = (new DestructionEvidenceExporter($db, $clock))->export($caseId);
            assertSame(1, $evidence['totalEvidenceItems']);
            assertSame('deleted', $evidence['items'][0]['result']);
            $act = $evidence['items'][0]['act'];
            assertSame('Test operator', $act['operator']['name']);
            assertSame('PrivateSurnameSentinel FullNameSentinel', $act['subject']['name']);
            assertSame(['identity'], $act['dataCategories']);
            assertSame('core', $act['informationSystem']['code']);
            assertTrue((new DateTimeImmutable($act['retainUntil'])) >= (new DateTimeImmutable($act['destroyedAt']))->modify('+3 years'));
            assertSame('core', $evidence['items'][0]['journalExport']['informationSystemCode']);
            $legalPackages->attest($caseId, $evidence['items'][0]['evidenceId'], $account,
                'urn:signed-act:test-45', 'urn:signature:test-45');
            $signed = (new DestructionEvidenceExporter($db, $clock))->export($caseId)['items'][0]['act'];
            assertTrue($signed['signed']);
            assertSame('urn:signed-act:test-45', $signed['documentReference']);
            assertSame('urn:signature:test-45', $signed['signatureReference']);
            assertFalse(str_contains(json_encode($evidence, JSON_THROW_ON_ERROR), 'private-sentinel@example.test'));
            assertSame($summary, $executor->executeBatch($actor, $caseId));
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)',
                [':id' => $caseId])->queryScalar());

            $contributor->contact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'hold-sentinel@example.test',
                    'hold-sentinel@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $heldCase = $cases->create($actor, $subject, 'verified_erasure');
            $holds = $container->get(LegalHoldService::class);
            $holdId = $holds->create($actor, $subject, 'resource', null,
                'core.person_contact', $contributor->contact, 'legal_review');
            assertSame('blocked', $executor->executeBatch($actor, $heldCase)['status']);
            assertTrue($db->createCommand('SELECT 1 FROM core.person_contacts WHERE id = CAST(:id AS uuid)',
                [':id' => $contributor->contact])->queryScalar() !== false);
            assertSame(0, (int) $db->createCommand('SELECT count(*) FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)',
                [':id' => $heldCase])->queryScalar());
            $holds->release($actor, $holdId, 'review_complete');
            assertSame('completed', $executor->executeBatch($actor, $heldCase)['status']);

            $contributor->contact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'prehold-sentinel@example.test',
                    'prehold-sentinel@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $preHold = $holds->create($actor, $subject, 'resource', null,
                'core.person_contact', $contributor->contact, 'legal_review');
            $preHeldCase = $cases->create($actor, $subject, 'verified_erasure');
            assertSame('blocked', $cases->get($preHeldCase)['status']);
            assertSame('blocked', $executor->executeBatch($actor, $preHeldCase)['status']);
            assertTrue($db->createCommand('SELECT 1 FROM core.person_contacts WHERE id = CAST(:id AS uuid)',
                [':id' => $contributor->contact])->queryScalar() !== false);
            $holds->release($actor, $preHold, 'review_complete');
            assertSame('completed', $executor->executeBatch($actor, $preHeldCase)['status']);

            $contributor->contact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'basis-sentinel@example.test',
                    'basis-sentinel@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $basisCase = $cases->create($actor, $subject, 'verified_erasure');
            $consentId = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_consents (granted_by_account_id, subject_person_id,
                    granted_by_person_id, purpose_code, consent_code, document_version, document_sha256,
                    locale, source, granted_at)
                VALUES (CAST(:account AS uuid), CAST(:subject AS uuid), CAST(:subject AS uuid),
                    'b4.contact', 'b4.contact', 'v1', decode(repeat('a', 64), 'hex'), 'en-US',
                    'test', CAST(:now AS timestamptz)) RETURNING id::text
                SQL, [':account' => $account, ':subject' => $subject,
                    ':now' => $clock->now()->format(DATE_ATOM)])->queryScalar();
            assertSame('blocked', $executor->executeBatch($actor, $basisCase)['status']);
            assertTrue($db->createCommand('SELECT 1 FROM core.person_contacts WHERE id = CAST(:id AS uuid)',
                [':id' => $contributor->contact])->queryScalar() !== false);
            $db->createCommand('UPDATE core.privacy_consents SET withdrawn_at = CAST(:now AS timestamptz) WHERE id = CAST(:id AS uuid)',
                [':now' => $clock->now()->format(DATE_ATOM), ':id' => $consentId])->execute();
            assertSame('completed', $executor->executeBatch($actor, $basisCase)['status']);

            $contributor->contact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'dsar-sentinel@example.test',
                    'dsar-sentinel@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $requests = $container->get(DataSubjectRequestService::class);
            $requestId = $requests->submitSelf($actor, 'erasure');
            $requests->transition($actor, $requestId, 'in_review', null);
            $requests->transition($actor, $requestId, 'in_progress', null);
            $dsarCase = $cases->create($actor, $subject, 'verified_erasure', $requestId,
                $clock->now()->modify('+2 days'), 'calendar_days');
            assertSame('in_progress', $requests->getVisible($actor, $requestId, true)?->status);
            assertSame('completed', $executor->executeBatch($actor, $dsarCase)['status']);
            assertSame('completed', $requests->getVisible($actor, $requestId, true)?->status);
            assertSame('completed', $executor->executeBatch($actor, $dsarCase)['status']);
            assertSame(1, (int) $db->createCommand(<<<'SQL'
                SELECT count(*) FROM core.data_subject_request_deadlines
                WHERE request_id = CAST(:request AS uuid) AND deadline_code = 'erasure_completion'
                    AND satisfied_at IS NOT NULL
                SQL, [':request' => $requestId])->queryScalar());
            assertSame(1, (int) $db->createCommand(<<<'SQL'
                SELECT count(*) FROM core.data_subject_request_executions
                WHERE request_id = CAST(:request AS uuid) AND execution_code = 'data_erasure'
                SQL, [':request' => $requestId])->queryScalar());
            assertSame($dsarCase, $cases->create($actor, $subject, 'verified_erasure', $requestId,
                $clock->now()->modify('+2 days'), 'calendar_days'));
            assertSame(1, (int) $db->createCommand(<<<'SQL'
                SELECT count(*) FROM core.data_subject_request_executions
                WHERE request_id = CAST(:request AS uuid) AND execution_code = 'data_erasure'
                SQL, [':request' => $requestId])->queryScalar());

            $recordJson = (string) $db->createCommand(<<<'SQL'
                SELECT jsonb_build_object(
                    'items', (SELECT coalesce(jsonb_agg(to_jsonb(i)), '[]'::jsonb)
                        FROM core.privacy_erasure_items i WHERE i.case_id = CAST(:case AS uuid)),
                    'destruction', (SELECT coalesce(jsonb_agg(to_jsonb(e)), '[]'::jsonb)
                        FROM core.privacy_destruction_evidence e WHERE e.case_id = CAST(:case AS uuid)),
                    'execution', (SELECT coalesce(jsonb_agg(to_jsonb(x)), '[]'::jsonb)
                        FROM core.data_subject_request_executions x WHERE x.request_id = CAST(:request AS uuid)),
                    'audit', (SELECT coalesce(jsonb_agg(to_jsonb(a)), '[]'::jsonb)
                        FROM core.audit_events a WHERE a.action LIKE 'privacy.erasure.%'),
                    'outbox', (SELECT coalesce(jsonb_agg(to_jsonb(o)), '[]'::jsonb)
                        FROM core.outbox_events o))::text
                SQL, [':case' => $dsarCase, ':request' => $requestId])->queryScalar();
            foreach (['FullNameSentinel', 'PrivateSurnameSentinel', 'private-sentinel@example.test',
                'dsar-sentinel@example.test', '1991-04-05'] as $secret) {
                assertFalse(str_contains($recordJson, $secret));
            }

            $sharedContact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'shared-sentinel@example.test',
                    'shared-sentinel@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $multiContributor = new class($subject, $sharedContact) implements RetentionCandidateContributor {
                public function __construct(private string $subject, private string $contact) {}
                public function code(): string { return 'b4.multi'; }
                public function supportsPurpose(string $purposeCode): bool {
                    return in_array($purposeCode, ['b4.contact', 'b4.second'], true);
                }
                public function candidates(int $limit, ?string $subjectPersonId = null): iterable {
                    if ($subjectPersonId !== null && $subjectPersonId !== $this->subject) { return; }
                    foreach (['b4.contact', 'b4.second'] as $purpose) {
                        yield new RetentionCandidate($this->subject, 'core.person_contact', $this->contact,
                            $purpose, $purpose, 'purpose_completed',
                            new DateTimeImmutable('2026-01-01T00:00:00Z'), 'b4.multi');
                    }
                }
            };
            $multiPolicies = new RetentionPolicyRegistry([
                new RetentionPolicyDefinition('b4.contact', 'purpose_completed', null, 'delete',
                    durationUnit: 'event_only'),
                new RetentionPolicyDefinition('b4.second', 'purpose_completed', null, 'delete',
                    durationUnit: 'event_only'),
            ]);
            $multiPurposes = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition('b4.contact', 'consent', true, false,
                    ['identity'], ['person'], 'b4.contact', 'operator'),
                new ProcessingPurposeDefinition('b4.second', 'consent', true, false,
                    ['identity'], ['person'], 'b4.second', 'operator'),
            ], $container->get(DataCategoryRegistry::class), $multiPolicies);
            $multiCandidates = new RetentionCandidateContributorRegistry([$multiContributor]);
            $multiBasis = new LawfulProcessingBasisResolver($multiPurposes,
                new LawfulProcessingBasisContributorRegistry(), $db);
            $multiEvaluator = new RetentionEvaluator($multiPurposes, $multiPolicies,
                $container->get(PrivacyDeadlinePolicy::class), $holds, $multiBasis,
                new RetentionDependencyContributorRegistry(), $clock);
            $multiDecisions = new RetentionDecisionService($db, $multiEvaluator, $clock, $audit);
            $multiCases = new ErasureCaseService($db, $clock, $multiCandidates, $multiPurposes,
                $multiDecisions, $handlers, $audit);
            $multiExecutor = new ErasureExecutor($db, $clock, $multiCandidates, $multiPurposes,
                $multiDecisions, $handlers, $container->get(DestructionEvidencePolicy::class),
                new ErasureCaseStateMachine(), $audit,
                $container->get(ErasureRequestCompletionService::class));
            $db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_consents (granted_by_account_id, subject_person_id,
                    granted_by_person_id, purpose_code, consent_code, document_version, document_sha256,
                    locale, source, granted_at)
                VALUES (CAST(:account AS uuid), CAST(:subject AS uuid), CAST(:subject AS uuid),
                    'b4.second', 'b4.second', 'v1', decode(repeat('b', 64), 'hex'), 'en-US',
                    'test', CAST(:now AS timestamptz))
                SQL, [':account' => $account, ':subject' => $subject,
                    ':now' => $clock->now()->format(DATE_ATOM)])->execute();
            $sharedCase = $multiCases->create($actor, $subject, 'verified_erasure');
            assertSame('blocked', $multiCases->get($sharedCase)['status']);
            assertSame('blocked', $multiExecutor->executeBatch($actor, $sharedCase)['status']);
            assertTrue($db->createCommand('SELECT 1 FROM core.person_contacts WHERE id = CAST(:id AS uuid)',
                [':id' => $sharedContact])->queryScalar() !== false);
            assertSame(0, (int) $db->createCommand('SELECT count(*) FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)',
                [':id' => $sharedCase])->queryScalar());

            $partialContacts = [];
            foreach (['partial-one@example.test', 'partial-two@example.test'] as $value) {
                $partialContacts[] = (string) $db->createCommand(<<<'SQL'
                    INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                    VALUES (CAST(:subject AS uuid), 'email', :value, :value) RETURNING id::text
                    SQL, [':subject' => $subject, ':value' => $value])->queryScalar();
            }
            $partialContributor = new class($subject, $partialContacts) implements RetentionCandidateContributor {
                public function __construct(private string $subject, public array $contacts) {}
                public function code(): string { return 'b4.partial'; }
                public function supportsPurpose(string $purposeCode): bool { return $purposeCode === 'b4.contact'; }
                public function candidates(int $limit, ?string $subjectPersonId = null): iterable {
                    if ($subjectPersonId !== null && $subjectPersonId !== $this->subject) { return; }
                    foreach ($this->contacts as $contact) {
                        yield new RetentionCandidate($this->subject, 'core.person_contact', $contact,
                            'b4.contact', 'b4.contact', 'purpose_completed',
                            new DateTimeImmutable('2026-01-01T00:00:00Z'), 'b4.partial');
                    }
                }
            };
            $partialCandidates = new RetentionCandidateContributorRegistry([$partialContributor]);
            $partialCases = new ErasureCaseService($db, $clock, $partialCandidates, $purposes,
                $decisions, $handlers, $audit);
            $partialExecutor = new ErasureExecutor($db, $clock, $partialCandidates, $purposes,
                $decisions, $handlers, $container->get(DestructionEvidencePolicy::class),
                new ErasureCaseStateMachine(), $audit,
                $container->get(ErasureRequestCompletionService::class));
            $partialCase = $partialCases->create($actor, $subject, 'verified_erasure');
            $partialHold = $holds->create($actor, $subject, 'resource', null,
                'core.person_contact', $partialContacts[1], 'legal_review');
            $partial = $partialExecutor->executeBatch($actor, $partialCase);
            assertSame('partially_completed', $partial['status']);
            assertSame(1, $partial['completed']);
            assertSame(1, $partial['blocked']);
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)',
                [':id' => $partialCase])->queryScalar());
            $holds->release($actor, $partialHold, 'review_complete');
            assertSame('completed', $partialExecutor->executeBatch($actor, $partialCase)['status']);
            assertSame(2, (int) $db->createCommand('SELECT count(*) FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)',
                [':id' => $partialCase])->queryScalar());

            $lateContact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'late-one@example.test',
                    'late-one@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $partialContributor->contacts = [$lateContact];
            $lateCase = $partialCases->create($actor, $subject, 'verified_erasure');
            $partialContributor->contacts[] = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'late-two@example.test',
                    'late-two@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            assertSame('blocked', $partialExecutor->executeBatch($actor, $lateCase)['status']);
            assertSame('candidate_set_changed', $partialCases->get($lateCase)['items'][0]['failure_code']);
            assertTrue($db->createCommand('SELECT 1 FROM core.person_contacts WHERE id = CAST(:id AS uuid)',
                [':id' => $lateContact])->queryScalar() !== false);

            $contributor->contact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'retry-sentinel@example.test',
                    'retry-sentinel@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $retryHandler = new class($db) implements PersonalDataErasureHandler {
                private bool $fail = true;
                private CorePersonContactErasureHandler $delegate;
                public function __construct(ConnectionInterface $db) {
                    $this->delegate = new CorePersonContactErasureHandler($db);
                }
                public function code(): string { return 'b4.retry'; }
                public function resourceType(): string { return $this->delegate->resourceType(); }
                public function supportsAction(string $action): bool { return $this->delegate->supportsAction($action); }
                public function owns(string $subjectPersonId, string $resourceId): bool {
                    return $this->delegate->owns($subjectPersonId, $resourceId);
                }
                public function reconcile(string $itemId): ?string { return null; }
                public function execute(string $subjectPersonId, string $resourceId,
                    string $action, string $itemId): string {
                    if ($this->fail) {
                        $this->fail = false;
                        throw new \RuntimeException('private exception sentinel');
                    }
                    return $this->delegate->execute($subjectPersonId, $resourceId, $action, $itemId);
                }
            };
            $retryHandlers = new PersonalDataErasureHandlerRegistry([$retryHandler]);
            $retryCases = new ErasureCaseService($db, $clock, $candidates, $purposes,
                $decisions, $retryHandlers, $audit);
            $retryExecutor = new ErasureExecutor($db, $clock, $candidates, $purposes,
                $decisions, $retryHandlers, $container->get(DestructionEvidencePolicy::class),
                new ErasureCaseStateMachine(), $audit,
                $container->get(ErasureRequestCompletionService::class));
            $retryRequest = $requests->submitSelf($actor, 'erasure');
            $requests->transition($actor, $retryRequest, 'in_review', null);
            $requests->transition($actor, $retryRequest, 'in_progress', null);
            $retryCase = $retryCases->create($actor, $subject, 'verified_erasure', $retryRequest,
                $clock->now()->modify('+2 days'), 'calendar_days');
            assertSame('failed', $retryExecutor->executeBatch($actor, $retryCase)['status']);
            assertSame('in_progress', $requests->getVisible($actor, $retryRequest, true)?->status);
            assertSame(0, (int) $db->createCommand(<<<'SQL'
                SELECT count(*) FROM core.data_subject_request_deadlines
                WHERE request_id = CAST(:request AS uuid) AND deadline_code = 'erasure_completion'
                    AND satisfied_at IS NOT NULL
                SQL, [':request' => $retryRequest])->queryScalar());
            assertSame(0, (int) $db->createCommand('SELECT count(*) FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)',
                [':id' => $retryCase])->queryScalar());
            assertTrue($db->createCommand('SELECT 1 FROM core.person_contacts WHERE id = CAST(:id AS uuid)',
                [':id' => $contributor->contact])->queryScalar() !== false);
            assertSame('completed', $retryExecutor->executeBatch($actor, $retryCase)['status']);
            assertSame('completed', $requests->getVisible($actor, $retryRequest, true)?->status);
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)',
                [':id' => $retryCase])->queryScalar());
        } finally {
            $transaction->rollBack();
        }
    }
}
