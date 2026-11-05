<?php

declare(strict_types=1);

// Only invoked by the disposable Core browser suite; never an HTTP route or a production seeder.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth\Application\RecoveryCodeService;
use App\Core\Auth\Application\TotpSecretCipher;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Environment;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
$db = $container->get(ConnectionInterface::class);
TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
$proof = getenv('E2E_ISOLATION_PROOF');
if (!is_string($proof) || strlen($proof) < 32) {
    throw new RuntimeException('An isolated browser runtime nonce is required.');
}
$command = $argv[1] ?? '';
$email = $argv[2] ?? '';
if ($command === 'seed') {
    // This command already passed the actual test DB/Redis guard and nonce gate.
    // Give each browser run a clean rate-limit/state namespace in its disposable Redis.
    $container->get(\Predis\ClientInterface::class)->flushdb();
    $result = ['nonce' => hash('sha256', $proof), 'accounts' => []];
    foreach (['operator', 'activation', 'reset', 'mfa', 'forced', 'forcedMfa', 'externalGoogle', 'externalTelegram', 'reader'] as $kind) {
        $email = 'core-e2e-' . strtolower($kind) . '-' . bin2hex(random_bytes(6)) . '@example.test';
        $password = bin2hex(random_bytes(18));
        $person = $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Browser', 'Fixture') RETURNING id::text")->queryScalar();
        $id = $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status, label) VALUES (CAST(:person AS uuid), 'human', CAST(:status AS core.account_status), :label) RETURNING id::text", [
            ':person' => $person, ':status' => $kind === 'activation' ? 'pending_verification' : 'active', ':label' => 'Core browser fixture ' . $kind,
        ])->queryScalar();
        if ($kind !== 'activation') {
            $db->createCommand("INSERT INTO core.auth_identifiers (account_id, identifier_type, value, normalized_value, is_primary, can_login, can_recover, verified_at) VALUES (CAST(:id AS uuid), 'email', :email, :email, true, true, true, now())", [':id' => $id, ':email' => $email])->execute();
            if (!str_starts_with($kind, 'external')) $db->createCommand("INSERT INTO core.auth_password_credentials (account_id, password_hash, password_algorithm, must_change_password) VALUES (CAST(:id AS uuid), :hash, 'argon2id', :forced)", [
                ':id' => $id, ':hash' => password_hash($password, PASSWORD_ARGON2ID), ':forced' => in_array($kind, ['forced', 'forcedMfa'], true),
            ])->execute();
        }
        $fixture = ['id' => $id, 'email' => $email, 'password' => $password];
        if (in_array($kind, ['operator', 'mfa', 'forcedMfa', 'externalGoogle', 'externalTelegram'], true)) {
            $encrypted = $container->get(TotpSecretCipher::class)->encrypt(random_bytes(20));
            $db->createCommand("INSERT INTO core.auth_totp_factors (account_id, label, secret_encrypted, algorithm, digits, period_seconds, verified_at, secret_key_version) VALUES (CAST(:id AS uuid), 'Browser fixture', decode(:secret, 'hex'), 'sha1', 6, 30, now(), :version)", [
                ':id' => $id, ':secret' => $encrypted->ciphertextHex, ':version' => $encrypted->keyVersion,
            ])->execute();
            $codes = new RecoveryCodeService();
            $fixture['recoveryCodes'] = $codes->generate($kind === 'operator' ? 2 : 1);
            $fixture['recoveryCode'] = $fixture['recoveryCodes'][0];
            foreach ($fixture['recoveryCodes'] as $code) $db->createCommand("INSERT INTO core.auth_recovery_codes (account_id, code_hash) VALUES (CAST(:id AS uuid), decode(:hash, 'hex'))", [':id' => $id, ':hash' => $codes->hashHex($code)])->execute();
        }
        if ($kind === 'operator') {
            $db->createCommand("INSERT INTO core.account_roles (account_id, role_id) SELECT CAST(:id AS uuid), id FROM core.roles WHERE code = 'core.superadmin'", [':id' => $id])->execute();
        }
        if (str_starts_with($kind, 'external')) {
            $provider = $kind === 'externalGoogle' ? 'google' : 'telegram';
            $providerId = $db->createCommand('UPDATE core.auth_providers SET enabled=true,allow_login=true WHERE code=:code RETURNING id::text', [':code' => $provider])->queryScalar();
            $container->get(\App\Core\Auth\Application\ExternalIdentityRepository::class)->link($id, $providerId,
                new \App\Core\Auth\Application\ExternalIdentityProfile('browser-subject-' . $id, null, false, 'Isolated fixture', null, []));
        }
        if ($kind === 'reader') {
            $role = $db->createCommand("INSERT INTO core.roles(code,name) VALUES(:code,'Limited browser reader') RETURNING id::text", [':code' => 'browser.reader.' . bin2hex(random_bytes(6))])->queryScalar();
            $db->createCommand("INSERT INTO core.role_permissions(role_id,permission_id) SELECT CAST(:role AS uuid),id FROM core.permissions WHERE code='core.accounts.read'", [':role' => $role])->execute();
            $db->createCommand('INSERT INTO core.account_roles(account_id,role_id) VALUES(CAST(:id AS uuid),CAST(:role AS uuid))', [':id' => $id, ':role' => $role])->execute();
        }
        $result['accounts'][$kind] = $fixture;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} elseif (str_starts_with($email, 'core-e2e-') && str_ends_with($email, '@example.test')) {
    $id = $db->createCommand('SELECT account_id::text FROM core.auth_identifiers WHERE normalized_value = :email', [':email' => $email])->queryScalar();
    if (!is_string($id)) throw new RuntimeException('Unknown browser fixture.');
    if ($command === 'privacy-prepare') {
        $operatorEmail = $argv[3] ?? '';
        if (!str_starts_with($operatorEmail, 'core-e2e-operator-') || !str_ends_with($operatorEmail, '@example.test')) throw new RuntimeException('A browser operator fixture is required.');
        $operator = $db->createCommand('SELECT a.id::text, a.person_id::text FROM core.accounts a JOIN core.auth_identifiers i ON i.account_id=a.id WHERE i.normalized_value=:email', [':email' => $operatorEmail])->queryOne();
        if (!is_array($operator)) throw new RuntimeException('Unknown browser operator fixture.');
        $person = (string) $db->createCommand('SELECT person_id::text FROM core.accounts WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryScalar();
        $db->createCommand("UPDATE core.persons SET given_name='PrivacyOwn', family_name='BrowserFixture' WHERE id=CAST(:person AS uuid)", [':person' => $person])->execute();
        $child = (string) $db->createCommand("INSERT INTO core.persons(given_name,family_name) VALUES('PrivacyChild','BrowserFixture') RETURNING id::text")->queryScalar();
        foreach ([$person, $child] as $subject) {
            $contact = 'privacy-' . bin2hex(random_bytes(6)) . '@example.test';
            $db->createCommand("INSERT INTO core.person_contacts(person_id,contact_type,value,normalized_value) VALUES(CAST(:person AS uuid),'email',:value,:value)", [':person' => $subject, ':value' => $contact])->execute();
        }
        $actor = new \App\Core\Auth\Application\AuthenticatedIdentity((string) $operator['id'], (string) $operator['person_id'], 'browser-cli-fixture', ['core.superadmin'], [], null, null);
        $representations = $container->get(\App\Core\Privacy\Application\LegalRepresentationService::class);
        $authority = $representations->declare($actor, $child, $person, 'legal_guardian', '2020-01-01T00:00:00Z', null,
            'fixture', 'vault://private-browser-proof', null,
            ['privacy.request.submit', 'privacy.request.view', 'privacy.export.request', 'privacy.data.access', 'privacy.data.export', 'privacy.processing_information.response']);
        $representations->verify($actor, $authority);
        echo json_encode(['subjectPersonId' => $person, 'childPersonId' => $child, 'representationId' => $authority], JSON_THROW_ON_ERROR);
    } elseif ($command === 'operator-state') {
        $person = (string) $db->createCommand('SELECT person_id::text FROM core.accounts WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryScalar();
        $requests = $db->createCommand('SELECT id::text, request_type, status FROM core.data_subject_requests WHERE subject_person_id=CAST(:person AS uuid) ORDER BY submitted_at,id', [':person' => $person])->queryAll();
        $executions = $db->createCommand('SELECT e.request_id::text,e.execution_code,e.status,e.result_sha256 AS sha256,e.result_size_bytes AS size FROM core.data_subject_request_executions e JOIN core.data_subject_requests r ON r.id=e.request_id WHERE r.subject_person_id=CAST(:person AS uuid)', [':person' => $person])->queryAll();
        $blocks = $db->createCommand('SELECT id::text,source_request_id::text,scope_type,purpose_code,resource_id::text,released_at FROM core.privacy_processing_blocks WHERE subject_person_id=CAST(:person AS uuid)', [':person' => $person])->queryAll();
        $contacts = $db->createCommand('SELECT id::text,value,label,is_primary FROM core.person_contacts WHERE person_id=CAST(:person AS uuid)', [':person' => $person])->queryAll();
        $profile = $db->createCommand('SELECT given_name,family_name,middle_name FROM core.persons WHERE id=CAST(:person AS uuid)', [':person' => $person])->queryOne();
        $redis = $container->get(\Predis\ClientInterface::class);
        $presence = $redis->get('presence:' . $id);
        try {
            $visible = $container->get(\App\Core\Presence\Application\PresenceVisibilityPolicy::class)->visibleState($id);
        } catch (\DomainException $error) {
            if ($error->getMessage() !== 'Account settings processing is restricted.') throw $error;
            $visible = 'restricted';
        }
        echo json_encode(['requests' => $requests, 'executions' => $executions, 'blocks' => $blocks, 'contacts' => $contacts, 'profile' => $profile,
            'presence' => ['exists' => is_string($presence), 'ttl' => $redis->ttl('presence:' . $id),
                'client' => is_string($presence) ? (json_decode($presence, true, flags: JSON_THROW_ON_ERROR)['client_instance_id'] ?? null) : null,
                'visible' => $visible]], JSON_THROW_ON_ERROR);
    } elseif ($command === 'privacy-state') {
        $requests = $db->createCommand('SELECT id::text, request_type, status, legal_representation_id::text FROM core.data_subject_requests WHERE submitted_by_account_id=CAST(:id AS uuid) ORDER BY submitted_at,id', [':id' => $id])->queryAll();
        $executions = $db->createCommand('SELECT e.request_id::text, e.execution_code, e.status, e.result_sha256 AS sha256, e.result_size_bytes AS size FROM core.data_subject_request_executions e JOIN core.data_subject_requests r ON r.id=e.request_id WHERE r.submitted_by_account_id=CAST(:id AS uuid) ORDER BY e.started_at,e.id', [':id' => $id])->queryAll();
        echo json_encode(['requests' => $requests, 'executions' => $executions], JSON_THROW_ON_ERROR);
    } elseif ($command === 'provider-code' || $command === 'link-code') {
        $provider = $argv[3] ?? '';
        $subject = $db->createCommand('SELECT e.provider_subject FROM core.auth_external_identities e JOIN core.auth_providers p ON p.id=e.provider_id WHERE e.account_id=CAST(:id AS uuid) AND p.code=:provider', [':id' => $id, ':provider' => $provider])->queryScalar();
        if (!is_string($subject) && $command === 'link-code' && in_array($provider, ['google', 'telegram'], true)) $subject = 'browser-subject-' . $id;
        if (!is_string($subject)) throw new RuntimeException('No fixture provider identity.');
        $code = bin2hex(random_bytes(32));
        $container->get(\Predis\ClientInterface::class)->setex('platform:core:e2e:oidc:' . hash('sha256', $code), 300,
            json_encode(['provider' => $provider, 'subject' => $subject, 'nonce' => $argv[4] ?? '', 'codeChallenge' => $argv[5] ?? ''], JSON_THROW_ON_ERROR));
        echo json_encode(['code' => $code], JSON_THROW_ON_ERROR);
    } elseif ($command === 'expire') {
        $db->createCommand("UPDATE core.auth_challenges SET expires_at = now() - interval '1 second' WHERE account_id = CAST(:id AS uuid) AND consumed_at IS NULL", [':id' => $id])->execute();
        echo '{"expired":true}';
    } elseif ($command === 'configuration-prepare') {
        if (!str_starts_with($email, 'core-e2e-operator-')) throw new RuntimeException('A browser operator fixture is required.');
        $code = 'test.configuration.browser';
        $db->createCommand("INSERT INTO core.modules (code,name,version,settings_schema_version,enabled,disabled_at) VALUES (:code,'Configuration browser module','1.0.0',1,false,now()) ON CONFLICT (code) DO NOTHING", [':code' => $code])->execute();
        echo json_encode(['moduleCode' => $code], JSON_THROW_ON_ERROR);
    } elseif ($command === 'configuration-state') {
        if (!str_starts_with($email, 'core-e2e-operator-')) throw new RuntimeException('A browser operator fixture is required.');
        $module = $db->createCommand("SELECT code,enabled FROM core.modules WHERE code='test.configuration.browser'")->queryOne();
        $system = $db->createCommand("SELECT setting_key AS key,value::text,is_public FROM core.system_settings WHERE setting_key LIKE 'test.configuration.browser.%' ORDER BY setting_key")->queryAll();
        $settings = $db->createCommand("SELECT setting_key AS key,value::text FROM core.module_settings WHERE module_id=(SELECT id FROM core.modules WHERE code='test.configuration.browser') ORDER BY setting_key")->queryAll();
        $flags = $db->createCommand("SELECT code,enabled,rollout_percent,config::text FROM core.feature_flags WHERE code LIKE 'test.configuration.browser.%' ORDER BY code")->queryAll();
        echo json_encode(['module' => $module, 'system' => $system, 'settings' => $settings, 'flags' => $flags], JSON_THROW_ON_ERROR);
    } elseif ($command === 'credentials-state') {
        $passkeys = $db->createCommand('SELECT id::text, label, revoked_at, last_used_at FROM core.auth_passkeys WHERE account_id=CAST(:id AS uuid)', [':id' => $id])->queryAll();
        $identities = $db->createCommand('SELECT p.code AS provider FROM core.auth_external_identities e JOIN core.auth_providers p ON p.id=e.provider_id WHERE e.account_id=CAST(:id AS uuid)', [':id' => $id])->queryAll();
        echo json_encode(['passkeys' => $passkeys, 'identities' => $identities], JSON_THROW_ON_ERROR);
    } elseif ($command === 'profile-state') {
        $preferences = $db->createCommand('SELECT locale, timezone FROM core.accounts WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryOne();
        $settings = $db->createCommand('SELECT settings::text, schema_version FROM core.account_settings WHERE account_id=CAST(:id AS uuid)', [':id' => $id])->queryOne();
        $consents = $db->createCommand('SELECT consent_code, document_version, encode(document_sha256, \'hex\') AS document_sha256, withdrawn_at FROM core.privacy_consents WHERE granted_by_account_id=CAST(:id AS uuid) ORDER BY granted_at', [':id' => $id])->queryAll();
        echo json_encode(['preferences' => $preferences, 'settings' => is_array($settings) ? $settings : null, 'consents' => $consents], JSON_THROW_ON_ERROR);
    } elseif ($command === 'remove-password') {
        $active = $db->createCommand('SELECT count(*) FROM core.auth_passkeys WHERE account_id=CAST(:id AS uuid) AND revoked_at IS NULL', [':id' => $id])->queryScalar();
        if ((int) $active < 1) throw new RuntimeException('A fixture passkey is required before removing its password.');
        $db->createCommand('DELETE FROM core.auth_password_credentials WHERE account_id=CAST(:id AS uuid)', [':id' => $id])->execute();
        echo '{"removed":true}';
    } elseif ($command === 'age-session') {
        $db->createCommand("UPDATE core.auth_sessions SET created_at = now() - interval '1 hour', mfa_verified_at = CASE WHEN mfa_verified_at IS NULL THEN NULL ELSE now() - interval '1 hour' END WHERE account_id = CAST(:id AS uuid) AND revoked_at IS NULL", [':id' => $id])->execute();
        echo '{"aged":true}';
    } elseif ($command === 'state') {
        $row = $db->createCommand("SELECT (SELECT count(*) FROM core.auth_sessions WHERE account_id = CAST(:id AS uuid) AND revoked_at IS NULL) AS sessions, (SELECT count(*) FROM core.auth_totp_factors WHERE account_id = CAST(:id AS uuid) AND verified_at IS NOT NULL AND revoked_at IS NULL) AS factors, (SELECT count(*) FROM core.auth_recovery_codes WHERE account_id = CAST(:id AS uuid) AND revoked_at IS NULL AND used_at IS NULL) AS codes", [':id' => $id])->queryOne();
        echo json_encode($row, JSON_THROW_ON_ERROR);
    } else throw new RuntimeException('Unknown fixture command.');
} else throw new RuntimeException('A browser fixture email is required.');
