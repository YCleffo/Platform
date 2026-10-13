<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Entitlement\Application\DeploymentEntitlementIssuer;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Entitlement\Application\EntitlementSigner;
use ControlPlane\Entitlement\Application\SigningKeyResolver;

final class DeploymentEntitlementIssuerTest extends SubscriptionTestCase
{
    public function testUnavailableSigningSecretFailsClosedWithoutSnapshot(): void
    {
        [, $account] = $this->readyAccount('signed-entitlements-no-key');
        $deployment = $this->connection->fetchOne("INSERT INTO commercial.deployments
            (commercial_account_id, installation_id, mode, status)
            VALUES (?, uuidv7(), 'on_prem', 'active') RETURNING id", [$account]);
        self::assertIsString($deployment);
        $pair = sodium_crypto_sign_keypair();
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys
            (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from)
            VALUES ('test-unavailable-key', 'Ed25519', 'entitlement', decode(?, 'hex'),
                    'vault://test/entitlement', 'active', now() - interval '1 day')",
            [bin2hex(sodium_crypto_sign_publickey($pair))]);
        try {
            $this->issuer(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))->ensureLatest($deployment);
            self::fail('Mismatched signing secret was accepted.');
        } catch (\DomainException) {
            self::assertSame(0, (int) $this->connection->fetchOne(
                'SELECT count(*) FROM commercial.deployment_entitlement_documents WHERE deployment_id = ?', [$deployment]));
        }
    }

    public function testSignedSnapshotIsStableUntilSourceChangesAndSuspensionClearsFeatures(): void
    {
        [, $account, $profile] = $this->readyAccount('signed-entitlements');
        $feature = $this->feature('signed.module.crm', 'boolean');
        [, $price] = $this->planPrice('signed-entitlements-plan', featureValues: [$feature => true]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());
        $installation = $this->uuid();
        $deployment = $this->connection->fetchOne("INSERT INTO commercial.deployments
            (commercial_account_id, installation_id, mode, status) VALUES (?, ?, 'on_prem', 'active') RETURNING id",
            [$account, $installation]);
        self::assertIsString($deployment);

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys
            (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from)
            VALUES ('test-entitlement-1', 'Ed25519', 'entitlement', decode(?, 'hex'),
                    'vault://test/entitlement', 'active', now() - interval '1 day')", [bin2hex($public)]);
        $issuer = $this->issuer($secret);
        $first = $issuer->ensureLatest($deployment);
        $again = $issuer->ensureLatest($deployment);
        self::assertSame($first, $again);
        self::assertSame(1, $first['sequence']);
        $canonical = base64_decode($first['documentBase64'], true);
        $signature = base64_decode($first['signatureBase64'], true);
        self::assertIsString($canonical);
        self::assertIsString($signature);
        if ($signature === '') { self::fail('Issuer produced an empty signature.'); }
        self::assertTrue(sodium_crypto_sign_verify_detached($signature, $canonical, $public));
        $document = json_decode($canonical, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['documentVersion', 'sequence', 'installationId', 'issuedAt', 'validFrom',
            'validUntil', 'deploymentStatus', 'features'], array_keys($document));
        self::assertSame(['signed.module.crm' => true], $document['features']);

        $this->connection->executeStatement("UPDATE commercial.subscriptions SET current_period_start=now()-interval '1 month', current_period_end=now()-interval '1 minute', billing_status='past_due', past_due_since=now()-interval '1 minute', grace_until=now()+interval '1 hour' WHERE id=?", [$subscription]);
        $grace = $issuer->ensureLatest($deployment);
        self::assertSame(2, $grace['sequence']);
        $graceCanonical = base64_decode($grace['documentBase64'], true);
        self::assertIsString($graceCanonical);
        $graceDocument = json_decode($graceCanonical, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['signed.module.crm' => true], $graceDocument['features']);
        self::assertGreaterThan(new \DateTimeImmutable('now'), new \DateTimeImmutable($graceDocument['validUntil']));

        $this->connection->executeStatement("UPDATE commercial.subscriptions SET status = 'expired'
            WHERE id = ?", [$subscription]);
        $changed = $issuer->ensureLatest($deployment);
        self::assertSame(3, $changed['sequence']);
        self::assertSame(3, $issuer->ensureLatest($deployment)['sequence']);

        $this->connection->executeStatement("UPDATE commercial.deployments SET status = 'suspended' WHERE id = ?", [$deployment]);
        $suspended = $issuer->ensureLatest($deployment);
        self::assertSame(4, $suspended['sequence']);
        $suspendedCanonical = base64_decode($suspended['documentBase64'], true);
        self::assertIsString($suspendedCanonical);
        $suspendedDocument = json_decode($suspendedCanonical, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('suspended', $suspendedDocument['deploymentStatus']);
        self::assertSame([], $suspendedDocument['features']);
        self::assertSame(4, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events
            WHERE event_type = 'entitlement.issued' AND aggregate_id = ?", [$deployment]));

        $this->connection->executeStatement("UPDATE commercial.deployments SET status = 'decommissioned' WHERE id = ?", [$deployment]);
        $this->expectException(\DomainException::class);
        $issuer->ensureLatest($deployment);
    }

    private function issuer(string $secret): DeploymentEntitlementIssuer
    {
        $resolver = new class($secret) implements SigningKeyResolver {
            public function __construct(private string $secret) {}
            public function resolve(string $secretReference): ?string
            {
                return $secretReference === 'vault://test/entitlement' ? $this->secret : null;
            }
        };
        return new DeploymentEntitlementIssuer($this->connection,
            new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection)),
            new EntitlementSigner($this->connection, $resolver));
    }
}
