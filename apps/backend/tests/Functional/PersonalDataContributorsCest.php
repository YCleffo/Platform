<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Privacy\Application\PersonalDataContributorRegistry;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataExporter;
use App\Core\Privacy\Infrastructure\AuthPersonalDataContributor;
use App\Core\Privacy\Infrastructure\CorePersonalDataContributor;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PersonalDataContributorsCest
{
    public function coreQueriesAreSubjectBoundAndAllowlisted(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Export', 'Subject') RETURNING id::text")->queryScalar();
            $other = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('OtherSecret', 'Person') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $subject])->queryScalar();
            $db->createCommand("INSERT INTO core.auth_identifiers (account_id, identifier_type, value, normalized_value) VALUES (CAST(:account AS uuid), 'email', 'export@example.test', 'export@example.test')", [':account' => $account])->execute();
            $db->createCommand("INSERT INTO core.auth_password_credentials (account_id, password_hash, password_algorithm) VALUES (CAST(:account AS uuid), 'SECRET_HASH_123', 'argon2id')", [':account' => $account])->execute();
            $db->createCommand("INSERT INTO core.auth_totp_factors (account_id, secret_encrypted) VALUES (CAST(:account AS uuid), convert_to('SECRET_TOTP_123', 'UTF8'))", [':account' => $account])->execute();
            $db->createCommand("INSERT INTO core.auth_recovery_codes (account_id, code_hash) VALUES (CAST(:account AS uuid), convert_to('SECRET_RECOVERY_123', 'UTF8'))", [':account' => $account])->execute();
            $db->createCommand("INSERT INTO core.auth_passkeys (account_id, credential_id, public_key) VALUES (CAST(:account AS uuid), convert_to('SECRET_PASSKEY_ID_123', 'UTF8'), convert_to('SECRET_PASSKEY_KEY_123', 'UTF8'))", [':account' => $account])->execute();
            $db->createCommand("INSERT INTO core.auth_sessions (account_id, session_token_hash, security_stamp, authentication_method, client_type, expires_at, access_expires_at) SELECT id, convert_to('SECRET_SESSION_123', 'UTF8'), security_stamp, 'password', 'web', now() + interval '1 day', now() + interval '1 hour' FROM core.accounts WHERE id = CAST(:account AS uuid)", [':account' => $account])->execute();
            $provider = (string) $db->createCommand("INSERT INTO core.auth_providers (code, protocol, display_name) VALUES ('secret_fixture', 'oidc', 'Secret Fixture') RETURNING id::text")->queryScalar();
            $db->createCommand("INSERT INTO core.auth_external_identities (account_id, provider_id, provider_subject, claims) VALUES (CAST(:account AS uuid), CAST(:provider AS uuid), 'subject', '{\"secret\":\"SECRET_CLAIMS_123\"}'::jsonb)", [':account' => $account, ':provider' => $provider])->execute();
            $db->createCommand("INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value) VALUES (CAST(:subject AS uuid), 'email', 'contact@example.test', 'contact@example.test')", [':subject' => $subject])->execute();
            $db->createCommand("INSERT INTO core.account_settings (account_id, settings) VALUES (CAST(:account AS uuid), '{\"privacy\":{\"show_online_status\":false}}'::jsonb)", [':account' => $account])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_consents
                    (granted_by_account_id, subject_person_id, granted_by_person_id, purpose_code,
                     consent_code, document_version, document_sha256, locale, source, granted_at)
                VALUES (CAST(:account AS uuid), CAST(:subject AS uuid), CAST(:subject AS uuid),
                        'test.processing', 'test.consent', 'v1', decode(repeat('00', 32), 'hex'),
                        'en-US', 'test', now())
                SQL, [':account' => $account, ':subject' => $subject])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.legal_representations
                    (subject_person_id, representative_person_id, authority_type, valid_from)
                VALUES (CAST(:subject AS uuid), CAST(:other AS uuid), 'legal_guardian',
                        '2020-01-01T00:00:00Z')
                SQL, [':subject' => $subject, ':other' => $other])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.data_subject_requests
                    (subject_person_id, submitted_by_person_id, submitted_by_account_id,
                     request_type, submitted_at)
                VALUES (CAST(:subject AS uuid), CAST(:subject AS uuid), CAST(:account AS uuid),
                        'export', now())
                SQL, [':subject' => $subject, ':account' => $account])->execute();
            $db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_processing_blocks
                    (subject_person_id, scope_type, reason_code, started_at)
                VALUES (CAST(:subject AS uuid), 'subject', 'accuracy_review', now())
                SQL, [':subject' => $subject])->execute();
            $contributors = [new AuthPersonalDataContributor($db)];
            foreach (CorePersonalDataContributor::codes() as $code) {
                $contributors[] = new CorePersonalDataContributor($db, $code);
            }
            $export = (new PersonalDataExporter(new PersonalDataContributorRegistry($contributors)))
                ->export(new PersonalDataExportContext(
                    $subject,
                    'request',
                    $subject,
                    $account,
                    null,
                    new DateTimeImmutable('2026-09-29T10:00:00Z'),
                ));
            assertSame(9, count($export->sections));
            foreach ($export->sections as $section) {
                assertTrue($section->records !== []);
            }
            assertSame(10, count($container->get(PersonalDataContributorRegistry::class)->all()));
            assertTrue(str_contains($export->json, 'export@example.test'));
            assertTrue(str_contains($export->json, 'contact@example.test'));
            assertTrue(!str_contains($export->json, 'SECRET_HASH_123'));
            foreach (['SECRET_TOTP_123', 'SECRET_RECOVERY_123', 'SECRET_PASSKEY_ID_123',
                'SECRET_PASSKEY_KEY_123', 'SECRET_SESSION_123', 'SECRET_CLAIMS_123'] as $secret) {
                assertTrue(!str_contains($export->json, $secret));
            }
            assertTrue(!str_contains($export->json, 'OtherSecret'));
        } finally {
            $transaction->rollBack();
        }
    }
}
