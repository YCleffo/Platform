<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopePolicy;
use ControlPlane\M2m\DeploymentScopeRegistry;
use ControlPlane\Privacy\ControlPrivacyCleanup;
use ControlPlane\Privacy\ControlPrivacyRetentionPolicy;
use DomainException;
use InvalidArgumentException;

final class DeploymentCredentialsTest extends DatabaseTestCase
{
    public function testLifecycleAndIsolation(): void
    {
        $account = $this->account();
        $deployment = $this->connection->fetchOne("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode) VALUES (?, uuidv7(), 'on_prem') RETURNING id", [$account]);
        self::assertIsString($deployment);
        $tokens = new TokenCodec('test-only-control-token-secret-at-least-32-chars');
        $service = new DeploymentCredentialService($this->connection, new DeploymentScopeRegistry(), new DatabaseRateLimiter($this->connection, $tokens));

        try {
            $service->create($deployment, ['unknown.scope']);
            self::fail('Unknown scope was accepted.');
        } catch (InvalidArgumentException) {
        }

        $issued = $service->create($deployment, ['usage.write']);
        self::assertNotNull($issued);
        $id = $issued['credential']['credentialId'];
        $secret = $issued['secret'];
        self::assertSame(43, strlen($secret));
        $hash = $this->connection->fetchOne("SELECT encode(secret_hash, 'hex') FROM commercial.service_credentials WHERE credential_id = ?", [$id]);
        self::assertSame(hash('sha256', $secret), $hash);
        self::assertArrayNotHasKey('secret', $issued['credential']);
        self::assertArrayNotHasKey('secretHash', $issued['credential']);

        $header = 'Service ' . $id . '.' . $secret;
        $identity = $service->authenticate($header, 'client-a');
        self::assertNotNull($identity);
        self::assertSame($deployment, $identity->deploymentId);
        (new DeploymentScopePolicy())->requireScope($identity, 'usage.write');
        $this->expectScopeDenied($identity);
        self::assertNull($service->authenticate('Service ' . $id . '.' . str_repeat('A', 43), 'client-a'));
        self::assertNull($service->authenticate('Bearer ' . $secret, 'client-a'));

        $rotated = $service->rotate($deployment, $id);
        self::assertNotNull($rotated);
        self::assertNull($service->authenticate($header, 'client-a'));
        $newId = $rotated['credential']['credentialId'];
        $newHeader = 'Service ' . $newId . '.' . $rotated['secret'];
        self::assertNotNull($service->authenticate($newHeader, 'client-a'));
        self::assertSame('revoked', $service->revoke($deployment, $newId)['status']);
        self::assertSame('revoked', $service->revoke($deployment, $newId)['status']);
        self::assertNull($service->authenticate($newHeader, 'client-a'));

        $this->connection->executeStatement("UPDATE commercial.service_credentials SET created_at = now() - interval '4 days', revoked_at = now() - interval '3 days' WHERE credential_id = ?", [$newId]);
        $cleanup = new ControlPrivacyCleanup($this->connection, new ControlPrivacyRetentionPolicy(['service_credential' => 1]));
        self::assertSame(1, $cleanup->run('service_credential', dryRun: true)['selected']);
        self::assertSame(1, $cleanup->run('service_credential', dryRun: false)['deleted']);

        $expiring = $service->create($deployment, ['entitlements.read'], (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
        self::assertNotNull($expiring);
        $this->connection->executeStatement("UPDATE commercial.service_credentials SET created_at = now() - interval '3 days', expires_at = now() - interval '1 day' WHERE credential_id = ?", [$expiring['credential']['credentialId']]);
        self::assertNull($service->authenticate('Service ' . $expiring['credential']['credentialId'] . '.' . $expiring['secret'], 'client-a'));
    }

    private function expectScopeDenied(\ControlPlane\M2m\DeploymentIdentity $identity): void
    {
        try {
            (new DeploymentScopePolicy())->requireScope($identity, 'entitlements.read');
            self::fail('Missing scope was accepted.');
        } catch (DomainException) {
        }
    }
}
