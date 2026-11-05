<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\DataRecipientRegistry;
use App\Core\Privacy\Application\DataSubjectRequestExecutionService;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\DistributionConsentService;
use App\Core\Privacy\Application\LawfulProcessingBasisContributorRegistry;
use App\Core\Privacy\Application\LawfulProcessingBasisResolver;
use App\Core\Privacy\Application\PersonalDataContributorRegistry;
use App\Core\Privacy\Application\PersonalDataCorrectionContributorRegistry;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\PrivacyIncidentService;
use App\Core\Privacy\Application\PrivacyCleanupPolicy;
use App\Core\Privacy\Application\PrivacyCleanupService;
use App\Core\Privacy\Application\PrivacyRequestExecutionCapabilityRegistry;
use App\Core\Privacy\Application\ProcessingInformationDefinition;
use App\Core\Privacy\Application\ProcessingInformationRegistry;
use App\Core\Privacy\Application\ProcessingInformationService;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributorRegistry;
use App\Core\Privacy\Application\ProcessingRoleDefinition;
use App\Core\Privacy\Application\ProcessingRoleRegistry;
use App\Core\Privacy\Application\ProcessorRegistry;
use App\Core\Privacy\Application\ProcessorDefinition;
use App\Core\Privacy\Application\RepresentativeAuthorityPolicy;
use App\Core\Privacy\Application\RetentionCandidateContributor;
use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Application\DataSubjectRequestStateMachine;
use App\Core\Privacy\Application\DataSubjectRequestTypeRegistry;
use App\Core\Privacy\Application\CrossBorderTransferRegistry;
use App\Core\Audit\Application\AuditService;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
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

final readonly class PrivacyComplianceB5Cest
{
    public function cleanupRespectsHoldAndRemovesExpiredOutboxPayload(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Cleanup', 'Subject') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.accounts (person_id, account_type, status)
                VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $auditId = (int) $db->createCommand(<<<'SQL'
                INSERT INTO core.audit_events (occurred_at, actor_type, actor_account_id, action)
                VALUES ('2026-01-01T00:00:00Z', 'account', CAST(:account AS uuid), 'privacy.test')
                RETURNING id
                SQL, [':account' => $account])->queryScalar();
            $holdId = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_legal_holds
                    (subject_person_id, scope_type, reason_code, created_at, created_by_account_id)
                VALUES (CAST(:subject AS uuid), 'subject', 'legal_review',
                    CAST(:now AS timestamptz), CAST(:account AS uuid)) RETURNING id::text
                SQL, [':subject' => $subject, ':account' => $account,
                ':now' => $container->get(ClockInterface::class)->now()->format(DATE_ATOM)])->queryScalar();
            $cleanup = new PrivacyCleanupService(
                $db,
                $container->get(ClockInterface::class),
                new PrivacyCleanupPolicy(['auditDays' => 1, 'outboxDays' => 1]),
            );
            assertSame(0, $cleanup->execute('audit', 100, false)['deleted']);
            assertTrue($db->createCommand(
                'SELECT 1 FROM core.audit_events WHERE id = :id',
                [':id' => $auditId],
            )->queryScalar() !== false);
            $db->createCommand(<<<'SQL'
                UPDATE core.privacy_legal_holds
                SET released_at = CAST(:now AS timestamptz),
                    released_by_account_id = CAST(:account AS uuid), release_reason_code = 'review_complete'
                WHERE id = CAST(:id AS uuid)
                SQL, [':now' => $container->get(ClockInterface::class)->now()->format(DATE_ATOM),
                ':account' => $account, ':id' => $holdId])->execute();
            assertSame(1, $cleanup->execute('audit', 100, true)['selected']);
            assertSame(1, $cleanup->execute('audit', 100, false)['deleted']);
            assertSame(0, $cleanup->execute('audit', 100, false)['deleted']);
            $outboxId = (int) $db->createCommand(<<<'SQL'
                INSERT INTO core.outbox_events
                    (producer_module, event_type, payload, status, occurred_at, published_at)
                VALUES ('core', 'communication.email.requested',
                    '{"recipient":{"address":"expired@example.test"}}'::jsonb,
                    'published', '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z')
                RETURNING id
                SQL)->queryScalar();
            assertSame(1, $cleanup->execute('outbox', 100, false)['deleted']);
            assertSame(false, $db->createCommand(
                'SELECT 1 FROM core.outbox_events WHERE id = :id',
                [':id' => $outboxId],
            )->queryScalar());
        } finally {
            $transaction->rollBack();
        }
    }

    public function processingInformationIsCompleteAndFinishesOnlyAfterIssuance(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Information', 'Subject') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.accounts (person_id, account_type, status)
                VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $contact = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                VALUES (CAST(:subject AS uuid), 'email', 'processing-info@example.test',
                    'processing-info@example.test') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
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
            $requestId = $requests->submitSelf($actor, 'processing_information');
            $requests->transition($actor, $requestId, 'in_review', null);
            $requests->transition($actor, $requestId, 'in_progress', null);
            assertSame('in_progress', $requests->getVisible($actor, $requestId, true)?->status);
            $policies = new RetentionPolicyRegistry([
                new RetentionPolicyDefinition(
                    'b5.info',
                    'purpose_completed',
                    null,
                    'delete',
                    durationUnit: 'event_only',
                ),
            ]);
            $purposes = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition(
                    'b5.info',
                    'consent',
                    true,
                    false,
                    ['contact'],
                    ['person'],
                    'b5.info',
                    'operator',
                ),
            ], $container->get(DataCategoryRegistry::class), $policies);
            $candidates = new RetentionCandidateContributorRegistry([
                new class ($subject, $contact) implements RetentionCandidateContributor {
                    public function __construct(private string $subject, private string $contact) {}
                    public function code(): string
                    {
                        return 'b5.info';
                    }
                    public function supportsPurpose(string $purposeCode): bool
                    {
                        return $purposeCode === 'b5.info';
                    }
                    public function candidates(int $limit, ?string $subjectPersonId = null): iterable
                    {
                        if ($subjectPersonId === $this->subject) {
                            yield new RetentionCandidate(
                                $this->subject,
                                'core.person_contact',
                                $this->contact,
                                'b5.info',
                                'b5.info',
                                'purpose_completed',
                                new DateTimeImmutable('2026-01-01T00:00:00Z'),
                                'b5.info',
                            );
                        }
                    }
                },
            ]);
            $information = new ProcessingInformationRegistry([
                new ProcessingInformationDefinition(
                    'b5.info',
                    ['automated'],
                    ['subject'],
                    'privacy.rights.v1',
                    ['access_control', 'audit'],
                    'Submit a verified request to the privacy operator.',
                    ['access_control' => 'Role based access is enforced.', 'audit' => 'Access events are recorded.'],
                ),
            ]);
            $roles = new ProcessingRoleRegistry([
                new ProcessingRoleDefinition(
                    'b5.info',
                    'operator',
                    'platform.operator',
                    null,
                    ['contact'],
                    ['collect', 'store'],
                    true,
                    true,
                    'erase_after_retention',
                ),
            ]);
            $capabilities = new PrivacyRequestExecutionCapabilityRegistry(
                $container->get(PersonalDataContributorRegistry::class),
                $container->get(PersonalDataCorrectionContributorRegistry::class),
                $container->get(ProcessingRestrictionContributorRegistry::class),
                $container->get(DataSubjectRequestTypeRegistry::class),
                $candidates,
                $purposes,
                null,
                null,
                $information,
            );
            $clock = $container->get(ClockInterface::class);
            $executions = new DataSubjectRequestExecutionService(
                $db,
                $clock,
                $container->get(PrivacyDeadlinePolicy::class),
                $container->get(DataSubjectRequestStateMachine::class),
                $container->get(AuditService::class),
                $capabilities,
            );
            $service = new ProcessingInformationService(
                $db,
                $clock,
                $requests,
                $container->get(RepresentativeAuthorityPolicy::class),
                $candidates,
                $purposes,
                $policies,
                new LawfulProcessingBasisResolver(
                    $purposes,
                    new LawfulProcessingBasisContributorRegistry(),
                    $db,
                ),
                $information,
                new ProcessorRegistry([
                    new ProcessorDefinition(
                        'processor.b5',
                        'processor.ref',
                        'processor_by_instruction',
                        ['b5.info'],
                        ['contact'],
                        'RU',
                        'contract.b5',
                        true,
                        true,
                        new DateTimeImmutable('2026-01-01T00:00:00Z'),
                        name: 'Test processor',
                        address: 'Processor address',
                    ),
                ]),
                new DataRecipientRegistry(),
                new CrossBorderTransferRegistry(),
                $roles,
                $executions,
                $container->get(\App\Core\Privacy\Application\PersonalDataExporter::class),
                ['name' => 'Test operator', 'address' => 'Test address'],
            );
            $result = json_decode($service->issue($actor, $requestId), true, flags: JSON_THROW_ON_ERROR);
            assertSame(true, $result['processed']);
            assertSame($subject, $result['subjectPersonId']);
            assertSame('Test operator', $result['operator']['name']);
            assertSame('Test address', $result['operator']['address']);
            assertTrue($result['actualPersonalData'] !== []);
            assertSame('b5.info', $result['purposes'][0]['purposeCode']);
            assertSame('Test processor', $result['purposes'][0]['processorsByInstruction'][0]['name']);
            assertSame('Processor address', $result['purposes'][0]['processorsByInstruction'][0]['address']);
            foreach (['legalBasisCode', 'methods', 'dataCategories', 'resourceTypes', 'sources',
                'storageAndProcessingPeriod', 'rightsProcedureReference', 'recipients',
                'processorsByInstruction', 'crossBorderTransfer', 'complianceMethods'] as $key) {
                assertTrue(array_key_exists($key, $result['purposes'][0]));
            }
            assertSame('completed', $requests->getVisible($actor, $requestId, true)?->status);
            assertSame(1, (int) $db->createCommand(<<<'SQL'
                SELECT count(*) FROM core.data_subject_request_deadlines
                WHERE request_id = CAST(:request AS uuid)
                    AND deadline_code = 'processing_information_response' AND satisfied_at IS NOT NULL
                SQL, [':request' => $requestId])->queryScalar());
        } finally {
            $transaction->rollBack();
        }
    }

    public function incidentDeadlinesAndDistributionBoundary(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.persons (given_name, family_name)
                VALUES ('Incident', 'Subject') RETURNING id::text
                SQL)->queryScalar();
            $account = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.accounts (person_id, account_type, status)
                VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $actor = new AuthenticatedIdentity(
                $account,
                $subject,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $clock = $container->get(ClockInterface::class);
            $incidents = new PrivacyIncidentService(
                $db,
                $clock,
                $container->get(DataCategoryRegistry::class),
            );
            $detected = new DateTimeImmutable('2026-09-28T10:00:00Z');
            $incidentId = $incidents->detect(
                $actor,
                'unauthorized_access',
                'investigation_required',
                ['contact'],
                1,
                [$subject],
                $detected,
            );
            $export = $incidents->export($incidentId);
            assertSame(
                $detected->modify('+24 hours')->format('Y-m-d H:i:sP'),
                (new DateTimeImmutable($export['initial_notification_due_at']))->format('Y-m-d H:i:sP'),
            );
            assertSame(
                $detected->modify('+72 hours')->format('Y-m-d H:i:sP'),
                (new DateTimeImmutable($export['investigation_notification_due_at']))->format('Y-m-d H:i:sP'),
            );
            assertSame(['contact'], $export['affectedCategories']);
            assertSame(1, $export['subjectScopeCount']);
            $incidents->transition($actor, $incidentId, 'investigating', 'review_started');
            $incidents->recordExternalNotification($actor, $incidentId, 'initial', 'operator.notice.1');
            $incidentEvidence = $incidents->export($incidentId);
            assertTrue($incidentEvidence['initial_notification_at'] !== null);
            assertSame(3, count($incidentEvidence['events']));

            $policies = new RetentionPolicyRegistry([
                new RetentionPolicyDefinition(
                    'b5.distribution',
                    'purpose_completed',
                    null,
                    'delete',
                    durationUnit: 'event_only',
                ),
            ]);
            $purposes = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition(
                    'b5.distribution',
                    'consent',
                    true,
                    false,
                    ['identity'],
                    ['person'],
                    'b5.distribution',
                    'operator',
                    distributionAllowed: true,
                ),
            ], $container->get(DataCategoryRegistry::class), $policies);
            $distribution = new DistributionConsentService(
                $db,
                $clock,
                $container->get(RepresentativeAuthorityPolicy::class),
                $purposes,
                $container->get(DataCategoryRegistry::class),
                new \App\Core\Privacy\Application\DistributionConsentDefinitionRegistry([
                    new \App\Core\Privacy\Application\DistributionConsentDefinition(
                        'b5.distribution',
                        'v1',
                        str_repeat('a', 64),
                        ['identity'],
                        ['display_name'],
                        ['moderated'],
                        ['leaderboard'],
                    ),
                ]),
            );
            assertFalse($distribution->allows(
                $subject,
                'b5.distribution',
                'identity',
                'display_name',
                'public_profile',
            ));
            $consentId = $distribution->grant(
                $actor,
                $subject,
                'b5.distribution',
                ['identity'],
                ['display_name'],
                ['moderated'],
                ['leaderboard'],
            );
            $document = $db->createCommand('SELECT document_version, document_sha256 FROM core.privacy_distribution_consents WHERE id = CAST(:id AS uuid)', [':id' => $consentId])->queryOne();
            assertSame('v1', $document['document_version']);
            assertSame(str_repeat('a', 64), $document['document_sha256']);
            assertFalse(\App\Core\Privacy\Api\PrivacyRequestInput::keys([
                'subjectPersonId' => $subject, 'purposeCode' => 'b5.distribution',
                'documentVersion' => 'forged', 'documentSha256' => str_repeat('b', 64),
            ], ['subjectPersonId','purposeCode','categoryCodes','fieldCodes',
                'conditionCodes','restrictionCodes','representationId']));
            assertFalse($distribution->allows(
                $subject,
                'b5.distribution',
                'identity',
                'display_name',
                'public_profile',
            ));
            assertTrue($distribution->allows(
                $subject,
                'b5.distribution',
                'identity',
                'display_name',
                'public_profile',
                ['moderated'],
            ));
            assertFalse($distribution->allows(
                $subject,
                'b5.distribution',
                'identity',
                'display_name',
                'leaderboard',
                ['moderated'],
            ));
            $distribution->withdraw($actor, $consentId);
            assertFalse($distribution->allows(
                $subject,
                'b5.distribution',
                'identity',
                'display_name',
                'public_profile',
                ['moderated'],
            ));
        } finally {
            $transaction->rollBack();
        }
    }
}
