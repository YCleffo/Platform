<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Account\Application\AccountLifecycleRepository;
use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Audit\Application\AuditService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\CredentialLifecycleRepository;
use App\Core\Auth\Application\SessionRepository;
use App\Core\Person\Application\CreatePersonContact;
use App\Core\Person\Application\CreatePersonContactCommand;
use App\Core\Person\Application\PersonRepository;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Privacy\Application\CorePrivacyContributorCoverage;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\DestructionEvidencePolicy;
use App\Core\Privacy\Application\ErasureCaseService;
use App\Core\Privacy\Application\ErasureCaseStateMachine;
use App\Core\Privacy\Application\ErasureExecutor;
use App\Core\Privacy\Application\ErasureRequestCompletionService;
use App\Core\Privacy\Application\LawfulProcessingBasisContributorRegistry;
use App\Core\Privacy\Application\LawfulProcessingBasisResolver;
use App\Core\Privacy\Application\LegalDestructionPackageService;
use App\Core\Privacy\Application\LegalHoldService;
use App\Core\Privacy\Application\PersonalDataErasureHandlerRegistry;
use App\Core\Privacy\Application\PendingErasureProcessor;
use App\Core\Privacy\Application\PersonalDataContributorRegistry;
use App\Core\Privacy\Application\PersonalDataCorrectionContributorRegistry;
use App\Core\Privacy\Application\PrivacyRequestExecutionCapabilityRegistry;
use App\Core\Privacy\Application\DataSubjectRequestTypeRegistry;
use App\Core\Privacy\Application\ProcessingInformationRegistry;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributorRegistry;
use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\RetentionDecisionService;
use App\Core\Privacy\Application\RetentionDependencyContributorRegistry;
use App\Core\Privacy\Application\RetentionEvaluator;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Privacy\Infrastructure\CoreProcessingRestrictionContributor;
use App\Core\Privacy\Infrastructure\CoreResourceBasisContributor;
use App\Core\Privacy\Infrastructure\CoreResourceDependencyContributor;
use App\Core\Privacy\Infrastructure\CoreRetentionCandidateProvider;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PrivacyIdentityErasureCest
{
    public function machineAccountsAndFinalSuperadminStayProtected(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            foreach (['service', 'system'] as $type) {
                $person = (string) $db->createCommand("INSERT INTO core.persons
                    (given_name, family_name) VALUES ('Protected', 'Person') RETURNING id::text")->queryScalar();
                $machine = (string) $db->createCommand(<<<'SQL'
                    INSERT INTO core.accounts (person_id, account_type, status)
                    VALUES (CAST(:person AS uuid), CAST(:type AS core.account_type), 'active')
                    RETURNING id::text
                    SQL, [':person' => $person, ':type' => $type])->queryScalar();
                $handler = $container->get(PersonalDataErasureHandlerRegistry::class)
                    ->forAction('core.human_account', 'anonymize');
                assertFalse($handler->owns($person, $machine));
                $rejected = false;
                try { $handler->execute($person, $machine, 'anonymize', $machine); }
                catch (\DomainException) { $rejected = true; }
                assertTrue($rejected);
                assertSame('active', $db->createCommand('SELECT status::text FROM core.accounts
                    WHERE id = CAST(:id AS uuid)', [':id' => $machine])->queryScalar());
                $personHandler = $container->get(PersonalDataErasureHandlerRegistry::class)
                    ->forAction('core.person', 'anonymize');
                $rejected = false;
                try { $personHandler->execute($person, $person, 'anonymize', $person); }
                catch (\DomainException) { $rejected = true; }
                assertTrue($rejected);
            }
            $admin = (string) $db->createCommand("INSERT INTO core.persons
                (given_name, family_name) VALUES ('Last', 'Admin') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand("INSERT INTO core.accounts
                (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active')
                RETURNING id::text", [':person' => $admin])->queryScalar();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.account_roles (account_id, role_id)
                SELECT CAST(:account AS uuid), id FROM core.roles WHERE code = 'core.superadmin'
                SQL, [':account' => $account])->execute();
            $rejected = false;
            try { $handler->execute($admin, $account, 'anonymize', $account); }
            catch (\App\Core\Shared\Application\ConflictException) { $rejected = true; }
            assertTrue($rejected);
            assertSame('active', $db->createCommand('SELECT status::text FROM core.accounts
                WHERE id = CAST(:id AS uuid)', [':id' => $account])->queryScalar());
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.account_roles
                WHERE account_id = CAST(:id AS uuid)', [':id' => $account])->queryScalar());
        } finally {
            $transaction->rollBack();
        }
    }

    public function realSubjectErasureAnonymizesRootsAfterDependants(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.persons (given_name, family_name, middle_name, display_name, birth_date)
                VALUES ('UniqueFirst49', 'UniqueLast49', 'UniqueMiddle49', 'UniqueDisplay49',
                    '1987-06-13') RETURNING id::text
                SQL)->queryScalar();
            $account = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.accounts (person_id, account_type, status, label, last_login_at)
                VALUES (CAST(:subject AS uuid), 'human', 'active', 'UniqueAccount49', now())
                RETURNING id::text
                SQL, [':subject' => $subject])->queryScalar();
            $role = (string) $db->createCommand("INSERT INTO core.roles (code, name)
                VALUES ('test.identity49', 'Identity test role') RETURNING id::text")->queryScalar();
            $db->createCommand("INSERT INTO core.account_roles (account_id, role_id)
                VALUES (CAST(:account AS uuid), CAST(:role AS uuid))",
                [':account' => $account, ':role' => $role])->execute();
            foreach ([['email', 'unique49@example.test'], ['phone', '+79990000049']] as [$type, $value]) {
                $db->createCommand(<<<'SQL'
                    INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value)
                    VALUES (CAST(:subject AS uuid), CAST(:type AS core.contact_type), :value, :value)
                    SQL, [':subject' => $subject, ':type' => $type, ':value' => $value])->execute();
            }
            $db->createCommand("INSERT INTO core.account_settings (account_id, settings)
                VALUES (CAST(:account AS uuid), '{\"marker\":\"UniqueSettings49\"}'::jsonb)",
                [':account' => $account])->execute();
            $organization = (string) $db->createCommand("INSERT INTO core.organizations (code, name)
                VALUES ('privacy-identity-49', 'Identity test') RETURNING id::text")->queryScalar();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.organization_memberships (organization_id, person_id, status, left_at)
                VALUES (CAST(:organization AS uuid), CAST(:subject AS uuid), 'left', now())
                SQL, [':organization' => $organization, ':subject' => $subject])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_identifiers (account_id, identifier_type, value,
                    normalized_value, is_primary, can_login, can_recover)
                VALUES (CAST(:account AS uuid), 'email', 'unique49@example.test',
                    'unique49@example.test', true, true, true)
                SQL, [':account' => $account])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_password_credentials (account_id, password_hash, password_algorithm)
                VALUES (CAST(:account AS uuid), 'UniquePasswordHash49', 'argon2id')
                SQL, [':account' => $account])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_sessions (account_id, session_token_hash, security_stamp,
                    authentication_method, client_type, device_name, user_agent,
                    access_expires_at, expires_at)
                SELECT id, decode(repeat('b', 64), 'hex'), security_stamp, 'password', 'web',
                    'UniqueDevice49', 'UniqueAgent49', now() + interval '1 hour',
                    now() + interval '1 day'
                FROM core.accounts WHERE id = CAST(:account AS uuid)
                SQL, [':account' => $account])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_passkeys (account_id, credential_id, public_key, label)
                VALUES (CAST(:account AS uuid), decode('0102', 'hex'), decode('0304', 'hex'),
                    'UniquePasskey49')
                SQL, [':account' => $account])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_totp_factors (account_id, label, secret_encrypted)
                VALUES (CAST(:account AS uuid), 'UniqueTotp49', decode('010203', 'hex'))
                SQL, [':account' => $account])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_recovery_codes (account_id, code_hash)
                VALUES (CAST(:account AS uuid), decode('040506', 'hex'))
                SQL, [':account' => $account])->execute();
            $provider = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_providers (code, protocol, display_name)
                VALUES ('privacy-id49', 'oidc', 'Privacy test') RETURNING id::text
                SQL)->queryScalar();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_external_identities (account_id, provider_id, provider_subject,
                    email, display_name, claims)
                VALUES (CAST(:account AS uuid), CAST(:provider AS uuid), 'UniqueOidc49',
                    'unique49@example.test', 'UniqueOidcName49', '{"marker":"UniqueClaim49"}'::jsonb)
                SQL, [':account' => $account, ':provider' => $provider])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_challenges (account_id, challenge_type, secret_hash,
                    target_normalized, expires_at)
                VALUES (CAST(:account AS uuid), 'password_reset', decode(repeat('c', 64), 'hex'),
                    'unique49@example.test', now() + interval '1 day')
                SQL, [':account' => $account])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_challenges (challenge_type, secret_hash,
                    target_normalized, expires_at)
                VALUES ('account_activation', decode(repeat('d', 64), 'hex'),
                    'unique49@example.test', now() + interval '1 day')
                SQL)->execute();

            $policies = new RetentionPolicyRegistry([
                new RetentionPolicyDefinition('test.child49', 'withdrawal', null, 'delete',
                    durationUnit: 'event_only'),
                new RetentionPolicyDefinition('test.root49', 'withdrawal', null, 'anonymize',
                    durationUnit: 'event_only'),
                new RetentionPolicyDefinition('test.settings49', 'withdrawal', null, 'delete',
                    durationUnit: 'event_only'),
            ]);
            $purposes = new ProcessingPurposeRegistry([
                new ProcessingPurposeDefinition('test.child49', 'consent', true, false,
                    ['identity'], ['person'], 'test.child49', 'operator'),
                new ProcessingPurposeDefinition('test.root49', 'consent', true, false,
                    ['identity'], ['person'], 'test.root49', 'operator'),
                new ProcessingPurposeDefinition('test.settings49', 'consent', true, false,
                    ['preferences'], ['person'], 'test.settings49', 'operator'),
            ], $container->get(DataCategoryRegistry::class), $policies);
            foreach ($purposes->codes() as $purpose) {
                $db->createCommand(<<<'SQL'
                    INSERT INTO core.privacy_consents (granted_by_account_id, subject_person_id,
                        granted_by_person_id, purpose_code, consent_code, document_version,
                        document_sha256, locale, source, granted_at, withdrawn_at)
                    VALUES (CAST(:account AS uuid), CAST(:subject AS uuid), CAST(:subject AS uuid),
                        :purpose, :purpose, 'v1', decode(repeat('a', 64), 'hex'), 'en-US', 'test',
                        now() - interval '2 days', now() - interval '1 day')
                    SQL, [':account' => $account, ':subject' => $subject, ':purpose' => $purpose])->execute();
            }
            $candidates = new RetentionCandidateContributorRegistry(
                new CoreRetentionCandidateProvider($db, $purposes, $policies));
            $handlers = $container->get(PersonalDataErasureHandlerRegistry::class);
            assertTrue($handlers->forAction('core.person', 'anonymize') !== null);
            assertTrue($handlers->forAction('core.human_account', 'anonymize') !== null);
            $bases = new LawfulProcessingBasisContributorRegistry([new CoreResourceBasisContributor($purposes)]);
            $dependencies = new RetentionDependencyContributorRegistry([new CoreResourceDependencyContributor($db)]);
            $coverage = new CorePrivacyContributorCoverage($purposes, $policies, $candidates,
                $bases, $dependencies,
                new ProcessingRestrictionContributorRegistry([new CoreProcessingRestrictionContributor($purposes)]),
                $handlers, $db);
            assertSame([], $coverage->missingForSubject($subject, $candidates->collect(100, $subject)));
            $capabilities = new PrivacyRequestExecutionCapabilityRegistry(
                $container->get(PersonalDataContributorRegistry::class),
                $container->get(PersonalDataCorrectionContributorRegistry::class),
                new ProcessingRestrictionContributorRegistry([new CoreProcessingRestrictionContributor($purposes)]),
                $container->get(DataSubjectRequestTypeRegistry::class), $candidates, $purposes,
                $handlers, $container->get(DestructionEvidencePolicy::class),
                $container->get(ProcessingInformationRegistry::class), $coverage);
            assertFalse($capabilities->available('erasure'));
            assertTrue($capabilities->availableForSubject('erasure', $subject));
            $clock = $container->get(ClockInterface::class);
            $audit = $container->get(AuditService::class);
            $basis = new LawfulProcessingBasisResolver($purposes, $bases, $db);
            $evaluator = new RetentionEvaluator($purposes, $policies,
                $container->get(PrivacyDeadlinePolicy::class), $container->get(LegalHoldService::class),
                $basis, $dependencies, $clock);
            $decisions = new RetentionDecisionService($db, $evaluator, $clock, $audit);
            $actor = new AuthenticatedIdentity($account, $subject,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', ['core.superadmin'], [], null, null);
            $requests = $container->get(DataSubjectRequestService::class);
            $requestId = $requests->submitSelf($actor, 'erasure');
            $requests->transition($actor, $requestId, 'in_review', null);
            $requests->transition($actor, $requestId, 'in_progress', null);
            $cases = new ErasureCaseService($db, $clock, $candidates, $purposes, $decisions,
                $handlers, $audit, $coverage);
            $caseId = $cases->create($actor, $subject, 'verified_erasure', $requestId,
                $clock->now()->modify('+10 days'), 'calendar_days');
            $legal = new LegalDestructionPackageService($db, $purposes,
                ['name' => 'Test operator', 'address' => 'Test address'],
                ['informationSystemName' => 'Core', 'informationSystemCode' => 'core',
                    'destructionMethod' => 'transactional_delete']);
            $executor = new ErasureExecutor($db, $clock, $candidates, $purposes, $decisions,
                $handlers, $container->get(DestructionEvidencePolicy::class),
                new ErasureCaseStateMachine(), $audit,
                $container->get(ErasureRequestCompletionService::class), $legal);
            $system = (string) $db->createCommand(
                "INSERT INTO core.accounts (account_type, status, label) VALUES ('system', 'active', 'Privacy automation') RETURNING id::text"
            )->queryScalar();
            $pending = new PendingErasureProcessor($db, $executor,
                $container->get(DestructionEvidencePolicy::class), $legal);
            $invalidActor = false;
            try { $pending->assertActor($account); }
            catch (\DomainException) { $invalidActor = true; }
            assertTrue($invalidActor);
            $db->createCommand("UPDATE core.privacy_erasure_cases SET status = 'blocked' WHERE id = CAST(:id AS uuid)",
                [':id' => $caseId])->execute();
            $pending->process($system, 10, 25);
            assertSame('blocked', $db->createCommand('SELECT status FROM core.privacy_erasure_cases
                WHERE id = CAST(:id AS uuid)', [':id' => $caseId])->queryScalar());
            $db->createCommand("UPDATE core.privacy_erasure_cases SET status = 'failed' WHERE id = CAST(:id AS uuid)",
                [':id' => $caseId])->execute();
            assertTrue($pending->process($system, 10, 25) >= 1);
            assertSame('completed', $db->createCommand('SELECT status FROM core.privacy_erasure_cases
                WHERE id = CAST(:id AS uuid)', [':id' => $caseId])->queryScalar());
            assertSame('completed', $requests->getVisible($actor, $requestId, true)?->status);
            assertSame(6, (int) $db->createCommand('SELECT count(*) FROM core.privacy_destruction_evidence
                WHERE case_id = CAST(:id AS uuid)', [':id' => $caseId])->queryScalar());
            $person = $db->createCommand('SELECT * FROM core.persons WHERE id = CAST(:id AS uuid)',
                [':id' => $subject])->queryOne();
            assertSame('archived', $person['status']);
            assertSame('Anonymized', $person['given_name']);
            assertSame('Person', $person['family_name']);
            foreach (['middle_name', 'display_name', 'birth_date'] as $field) {
                assertSame(null, $person[$field]);
            }
            $accountRow = $db->createCommand('SELECT * FROM core.accounts WHERE id = CAST(:id AS uuid)',
                [':id' => $account])->queryOne();
            assertSame('archived', $accountRow['status']);
            foreach (['label', 'locked_until', 'last_login_at'] as $field) {
                assertSame(null, $accountRow[$field]);
            }
            assertTrue($accountRow['disabled_at'] !== null && $accountRow['archived_at'] !== null);
            foreach (['person_contacts' => 'person_id', 'organization_memberships' => 'person_id'] as $table => $column) {
                assertSame(0, (int) $db->createCommand("SELECT count(*) FROM core.{$table}
                    WHERE {$column} = CAST(:id AS uuid)", [':id' => $subject])->queryScalar());
            }
            foreach (['account_settings', 'auth_identifiers', 'auth_password_credentials',
                'auth_sessions', 'auth_passkeys', 'auth_totp_factors', 'auth_recovery_codes',
                'auth_external_identities', 'auth_challenges', 'account_roles'] as $table) {
                assertSame(0, (int) $db->createCommand("SELECT count(*) FROM core.{$table}
                    WHERE account_id = CAST(:id AS uuid)", [':id' => $account])->queryScalar());
            }
            assertSame(0, (int) $db->createCommand("SELECT count(*) FROM core.auth_challenges
                WHERE target_normalized = 'unique49@example.test'")->queryScalar());
            $live = json_encode([$person, $accountRow], JSON_THROW_ON_ERROR);
            foreach (['UniqueFirst49', 'UniqueLast49', 'UniqueMiddle49', 'UniqueDisplay49',
                'UniqueAccount49', '1987-06-13', 'unique49@example.test', '+79990000049',
                'UniqueOidc49', 'UniquePasskey49'] as $value) {
                assertFalse(str_contains($live, $value));
            }
            assertSame(null, $container->get(CredentialLifecycleRepository::class)
                ->findRecoverableEmail('unique49@example.test'));
            assertSame(null, $container->get(SessionRepository::class)
                ->findByTokenHashHex(str_repeat('b', 64)));
            assertFalse($container->get(AccountSecurityRepository::class)->findSecurityState($account)
                ->allowsHumanAuthentication($clock->now()));
            assertSame('Anonymized', $container->get(PersonRepository::class)->findById($subject)?->givenName);
            assertFalse(in_array($subject, array_map(static fn($person): string => $person->id,
                $container->get(PersonRepository::class)->findPage(100, 0)->items), true));
            assertSame(null, $container->get(OrganizationRepository::class)
                ->addMember($organization, $subject));
            assertSame(null, $container->get(CreatePersonContact::class)->execute(
                new CreatePersonContactCommand($subject, 'email', 'resurrect49@example.test', null, false)));
            $rejected = false;
            try { $container->get(AccountLifecycleRepository::class)->enable($account); }
            catch (\Throwable) { $rejected = true; }
            assertTrue($rejected);
            $stamp = $accountRow['security_stamp'];
            foreach ($cases->get($caseId)['items'] as $item) {
                if (in_array($item['resource_type'], ['core.person', 'core.human_account'], true)) {
                    $handler = $handlers->forAction($item['resource_type'], 'anonymize');
                    assertSame('anonymized', $handler->reconcile($item['id']));
                    assertSame('anonymized', $handler->execute($subject, $item['resource_id'],
                        'anonymize', $item['id']));
                }
            }
            assertSame($stamp, $db->createCommand('SELECT security_stamp FROM core.accounts
                WHERE id = CAST(:id AS uuid)', [':id' => $account])->queryScalar());
        } finally {
            $transaction->rollBack();
        }
    }
}
