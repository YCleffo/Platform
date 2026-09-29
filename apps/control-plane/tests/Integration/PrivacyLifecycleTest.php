<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Privacy\ControlPrivacyCleanup;
use ControlPlane\Privacy\ControlPrivacyRetentionPolicy;
use ControlPlane\Privacy\ControlPersonalDataExporter;
use ControlPlane\Privacy\IdentityPersonalDataContributor;
use ControlPlane\Privacy\SecurityPersonalDataContributor;
use ControlPlane\Privacy\CommercialPersonalDataContributor;
use ControlPlane\Privacy\ControlUserErasure;

final class PrivacyLifecycleTest extends DatabaseTestCase
{
    public function testAuditMetadataRejectsUnlistedPersonalData(): void
    {
        $user = $this->user();
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement(
            "INSERT INTO commercial.audit_events (actor_type, actor_id, action, resource_type, resource_id, outcome, metadata) VALUES ('control_user', ?, 'control_session.login', 'control_user', ?, 'success', '{\"email\":\"secret@example.test\"}'::jsonb)",
            [$user, $user],
        ));
    }

    public function testCleanupDryRunAndHold(): void
    {
        $user = $this->user();
        $verificationId = $this->connection->fetchOne(
            "INSERT INTO commercial.control_user_email_verifications (control_user_id, token_hash, created_at, expires_at) VALUES (?, decode(repeat('ab', 32), 'hex'), now() - interval '5 days', now() - interval '4 days') RETURNING id",
            [$user],
        );
        $cleanup = new ControlPrivacyCleanup($this->connection, new ControlPrivacyRetentionPolicy(['verification' => 1]));
        self::assertSame(1, $cleanup->run('verification', recordId: $verificationId)['selected']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.control_user_email_verifications WHERE id = ?', [$verificationId]));
        self::assertSame(1, $cleanup->run('verification', dryRun: false, recordId: $verificationId)['deleted']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.control_user_email_verifications WHERE id = ?', [$verificationId]));

        $heldId = $this->connection->fetchOne(
            "INSERT INTO commercial.control_user_email_verifications (control_user_id, token_hash, created_at, expires_at) VALUES (?, decode(repeat('ac', 32), 'hex'), now() - interval '5 days', now() - interval '4 days') RETURNING id",
            [$user],
        );
        $this->connection->executeStatement("INSERT INTO commercial.privacy_holds (control_user_id, reason_code) VALUES (?, 'legal_hold')", [$user]);
        self::assertSame(0, $cleanup->run('verification', dryRun: false, recordId: $heldId)['deleted']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.control_user_email_verifications WHERE id = ?', [$heldId]));
    }

    public function testSelfExportUsesExplicitSafeFields(): void
    {
        $user = $this->user();
        $other = $this->user();
        $exporter = new ControlPersonalDataExporter($this->connection, [
            new IdentityPersonalDataContributor($this->connection),
            new SecurityPersonalDataContributor($this->connection),
            new CommercialPersonalDataContributor($this->connection),
        ]);
        $result = $exporter->exportSelf($user, $user);
        self::assertSame($user, $result['subject_id']);
        self::assertSame(1, $result['schema_version']);
        self::assertArrayNotHasKey('password_hash', $result['data']['control_identity'][0]);
        self::assertArrayNotHasKey('token_hash', $result['data']['control_security']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.privacy_export_events WHERE control_user_id = ?', [$user]));
        $this->expectException(\DomainException::class);
        $exporter->exportSelf($other, $user);
    }

    public function testErasureBlocksSoleOwnerAndPseudonymizesUnlinkedUser(): void
    {
        $owner = $this->user();
        $account = $this->connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Erasure Test') RETURNING id", ['erase-' . substr(str_replace('-', '', $owner), 0, 20)]);
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$owner, $account]);
        $erasure = new ControlUserErasure($this->connection, true, 0, 0);
        self::assertSame('sole_account_owner', $erasure->erase($owner)['reason_code']);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.control_users WHERE id = ?', [$owner]));

        $subject = $this->user();
        self::assertSame('completed', $erasure->erase($subject)['status']);
        self::assertSame('erased-' . $subject . '@privacy.invalid', $this->connection->fetchOne('SELECT email FROM commercial.control_users WHERE id = ?', [$subject]));
        self::assertSame('disabled', $this->connection->fetchOne('SELECT status FROM commercial.control_users WHERE id = ?', [$subject]));
    }

    public function testExpiredAuditAndOutboxCleanupRespectsHold(): void
    {
        $user = $this->user();
        $auditId = $this->connection->fetchOne(<<<'SQL'
INSERT INTO commercial.audit_events (actor_type, actor_id, action, resource_type, resource_id, outcome, occurred_at)
VALUES ('control_user', ?, 'control_session.logout', 'control_user', ?, 'success', now() - interval '4 days')
RETURNING id
SQL, [$user, $user]);
        $outboxId = $this->connection->fetchOne(<<<'SQL'
INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload, status, created_at, published_at)
VALUES ('control_user.email_verification_requested', 'control_user', ?, '{"recipient":"private@example.test"}'::jsonb,
 'published', now() - interval '4 days', now() - interval '4 days')
RETURNING id
SQL, [$user]);
        $cleanup = new ControlPrivacyCleanup($this->connection, new ControlPrivacyRetentionPolicy(['audit' => 1, 'outbox' => 1]));
        $hold = $this->connection->fetchOne("INSERT INTO commercial.privacy_holds (control_user_id, reason_code) VALUES (?, 'legal_hold') RETURNING id", [$user]);
        self::assertSame(0, $cleanup->run('audit', 10, false)['deleted']);
        self::assertSame(0, $cleanup->run('outbox', 10, false)['deleted']);
        $this->connection->executeStatement('UPDATE commercial.privacy_holds SET released_at = now() WHERE id = ?', [$hold]);
        self::assertGreaterThanOrEqual(1, $cleanup->run('audit', 1000)['selected']);
        self::assertGreaterThanOrEqual(1, $cleanup->run('outbox', 1000, false)['deleted']);
        self::assertGreaterThanOrEqual(1, $cleanup->run('audit', 1000, false)['deleted']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.audit_events WHERE id = ?', [$auditId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.outbox_events WHERE id = ?', [$outboxId]));
    }

    public function testErasureRequiresApprovedIdentityAndContactPeriods(): void
    {
        $user = $this->user();
        self::assertSame('policy_not_approved',
            (new ControlUserErasure($this->connection, true, null, null))->erase($user)['reason_code']);
        self::assertSame('retention_period_active',
            (new ControlUserErasure($this->connection, true, 30, 30))->erase($user)['reason_code']);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.control_users WHERE id = ?', [$user]));
    }

    public function testExpiredRateLimitCounterCleansInBoundedBatch(): void
    {
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO commercial.rate_limit_counters (scope, key_hash, window_started_at, hits, expires_at)
VALUES ('privacy.test', decode(repeat('ba', 32), 'hex'), now() - interval '5 days', 1, now() - interval '4 days')
SQL);
        $cleanup = new ControlPrivacyCleanup($this->connection, new ControlPrivacyRetentionPolicy(['rate_limit' => 1]));
        self::assertGreaterThanOrEqual(1, $cleanup->run('rate_limit', 1000, false)['deleted']);
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM commercial.rate_limit_counters WHERE scope = 'privacy.test' AND key_hash = decode(repeat('ba', 32), 'hex')"));
    }
}
