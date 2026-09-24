<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class CommercialConstraintsTest extends DatabaseTestCase
{
    public function testCommercialAccountCodeAndStatusAreDatabaseGuarded(): void
    {
        $id = $this->account();
        $this->expectDatabaseRejection(fn () => $this->account());
        $this->expectDatabaseRejection(fn () => $this->account('Core.Admin'));
        $this->expectDatabaseRejection(fn () => $this->account('café'));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET status = 'suspended' WHERE id = ?", [$id]));
    }

    public function testOneDefaultBillingProfile(): void
    {
        $account = $this->account();
        $this->connection->executeStatement("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, is_default) VALUES (?, 'School', 'RU', true)", [$account]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, is_default) VALUES (?, 'School 2', 'RU', true)", [$account]));
    }

    public function testCatalogTypesVersionsAndPrices(): void
    {
        $feature = $this->connection->fetchOne("INSERT INTO commercial.features (code, value_type) VALUES ('students.max', 'integer') RETURNING id");
        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES ('school', 1, 'School') RETURNING id");
        self::assertIsString($feature);
        self::assertIsString($plan);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.features (code, value_type) VALUES ('students.max', 'integer')"));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.plans (code, version, name) VALUES ('school', 1, 'Again')"));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.plan_features (plan_id, feature_id, value) VALUES (?, ?, 'true'::jsonb)", [$plan, $feature]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'rub', -1, 'month')", [$plan]));
        $this->connection->executeStatement("INSERT INTO commercial.plan_features (plan_id, feature_id, value) VALUES (?, ?, '100'::jsonb)", [$plan, $feature]);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.plan_features SET value = '200'::jsonb WHERE plan_id = ? AND feature_id = ?", [$plan, $feature]));
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'retired', retired_at = now() WHERE id = ?", [$plan]);
        self::assertSame('retired', $this->connection->fetchOne('SELECT status FROM commercial.plans WHERE id = ?', [$plan]));
    }

    public function testDeploymentSlugAndHostnameReservations(): void
    {
        $account = $this->account();
        $deployment = $this->connection->fetchOne("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode, system_slug) VALUES (?, uuidv7(), 'shared', 'school-1') RETURNING id", [$account]);
        self::assertIsString($deployment);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode, system_slug) VALUES (?, uuidv7(), 'shared', 'school-1')", [$account]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode, system_slug) VALUES (?, uuidv7(), 'shared', 'Admin')", [$account]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode, system_slug) VALUES (?, uuidv7(), 'unknown', 'other')", [$account]));
        $this->connection->executeStatement("INSERT INTO commercial.deployment_domains (deployment_id, kind, hostname) VALUES (?, 'system', 'school-1.platform.example')", [$deployment]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.deployment_domains (deployment_id, kind, hostname) VALUES (?, 'custom', 'school-1.platform.example')", [$deployment]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.deployment_domains (deployment_id, kind, hostname) VALUES (?, 'custom', ?)", [$deployment, str_repeat('a', 64) . '.example.com']));
        $this->connection->executeStatement("INSERT INTO commercial.deployment_domains (deployment_id, kind, hostname) VALUES (?, 'custom', 'diary.customer.example')", [$deployment]);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.deployment_domains WHERE deployment_id = ? AND kind = 'system'", [$deployment]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("DELETE FROM commercial.deployment_domains WHERE deployment_id = ?", [$deployment]));
        $this->connection->executeStatement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function testLicenseValidityAndInstallationLimit(): void
    {
        $account = $this->account();
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.licenses (commercial_account_id, kind, valid_from, valid_until, max_installations) VALUES (?, 'perpetual', now(), now() - interval '1 day', 1)", [$account]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.licenses (commercial_account_id, kind, valid_from, max_installations) VALUES (?, 'perpetual', now(), 0)", [$account]));
        $license = $this->connection->fetchOne("INSERT INTO commercial.licenses (commercial_account_id, kind, status, valid_from, max_installations) VALUES (?, 'perpetual', 'active', now(), 1) RETURNING id", [$account]);
        self::assertIsString($license);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.maintenance_contracts (license_id, valid_from, valid_until, status) VALUES (?, now(), now(), 'active')", [$license]));
        $this->connection->executeStatement("INSERT INTO commercial.maintenance_contracts (license_id, valid_from, valid_until, status) VALUES (?, now(), now() + interval '1 year', 'active')", [$license]);
        $this->connection->executeStatement("INSERT INTO commercial.license_installations (license_id, installation_id) VALUES (?, uuidv7())", [$license]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.license_installations (license_id, installation_id) VALUES (?, uuidv7())", [$license]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('UPDATE commercial.licenses SET max_installations = 0 WHERE id = ?', [$license]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.licenses SET status = 'revoked' WHERE id = ?", [$license]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.licenses WHERE id = ?', [$license]));
    }

    public function testSigningKeyMetadataAndImmutableIssuedDocument(): void
    {
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.signing_keys (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from) VALUES ('bad', 'RSA', 'entitlement', decode(repeat('00',32),'hex'), 'vault://keys/test', 'active', now())"));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.signing_keys (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from, valid_until) VALUES ('bad-interval', 'Ed25519', 'entitlement', decode(repeat('00',32),'hex'), 'vault://keys/test', 'active', now(), now() - interval '1 day')"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'commercial' AND table_name = 'signing_keys' AND column_name IN ('private_key', 'private_key_bytes')"));
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from) VALUES ('key-1', 'Ed25519', 'entitlement', decode(repeat('00',32),'hex'), 'vault://keys/test', 'active', now())");
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.signing_keys (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from) VALUES ('key-1', 'Ed25519', 'entitlement', decode(repeat('00',32),'hex'), 'vault://keys/test', 'active', now())"));
        $account = $this->account();
        $license = $this->connection->fetchOne("INSERT INTO commercial.licenses (commercial_account_id, kind, valid_from, max_installations) VALUES (?, 'perpetual', now(), 1) RETURNING id", [$account]);
        self::assertIsString($license);
        $document = $this->connection->fetchOne("INSERT INTO commercial.issued_entitlement_documents (license_id, installation_id, document_version, sequence, envelope, envelope_sha256, signature, signing_key_id, issued_at, valid_from) VALUES (?, uuidv7(), 1, 1, convert_to('payload','UTF8'), decode(repeat('00',32),'hex'), decode(repeat('00',64),'hex'), 'key-1', now(), now()) RETURNING id", [$license]);
        self::assertIsString($document);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.issued_entitlement_documents WHERE id = ?', [$document]));
    }

    public function testReleasePublicationIsCompleteAndImmutable(): void
    {
        $release = $this->connection->fetchOne("INSERT INTO commercial.releases (version, channel) VALUES ('1.2.3', 'stable') RETURNING id");
        self::assertIsString($release);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.releases (version, channel) VALUES ('1.2.3', 'beta')"));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.releases SET status = 'published', published_at = now() WHERE id = ?", [$release]));
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from) VALUES ('release-key', 'Ed25519', 'release', decode(repeat('00',32),'hex'), 'vault://keys/release', 'active', now())");
        $this->connection->executeStatement("UPDATE commercial.releases SET oci_reference = 'registry.example/app@sha256:abc', image_digest = 'sha256:' || repeat('a',64), manifest_sha256 = decode(repeat('00',32),'hex'), manifest_signature = decode(repeat('00',64),'hex'), signing_key_id = 'release-key', status = 'published', published_at = now() WHERE id = ?", [$release]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.releases SET channel = 'beta' WHERE id = ?", [$release]));
        $this->connection->executeStatement("UPDATE commercial.releases SET status = 'retired', retired_at = now() WHERE id = ?", [$release]);
        self::assertSame('retired', $this->connection->fetchOne('SELECT status FROM commercial.releases WHERE id = ?', [$release]));
    }

    public function testAuditIsAppendOnly(): void
    {
        $id = $this->connection->fetchOne("INSERT INTO commercial.audit_events (actor_type, action, resource_type, resource_id, outcome) VALUES ('system','test.created','test','1','success') RETURNING id");
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.audit_events WHERE id = ?', [$id]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.audit_events SET outcome = 'failure' WHERE id = ?", [$id]));
    }

    public function testOpaqueOperationAndIdempotencyKeysAreDatabaseGuarded(): void
    {
        $account = $this->account();
        $deployment = $this->connection->fetchOne("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode) VALUES (?, uuidv7(), 'on_prem') RETURNING id", [$account]);
        self::assertIsString($deployment);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.deployment_operations (deployment_id, operation_type, idempotency_key) VALUES (?, 'backup', '   ')", [$deployment]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status) VALUES ('test.scope', E'bad\\nkey', decode(repeat('00',32),'hex'), 'pending')"));
    }
}
