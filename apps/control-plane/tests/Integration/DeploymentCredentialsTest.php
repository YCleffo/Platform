<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentCredentialLifetime;
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
            $service->create($deployment, ['unknown.scope'], (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
            self::fail('Unknown scope was accepted.');
        } catch (InvalidArgumentException) {
        }

        $issued = $service->create($deployment, ['usage.write'], (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
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

        $rotated = $service->rotate($deployment, $id, (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM));
        self::assertNotNull($rotated);
        self::assertNull($service->authenticate($header, 'client-a'));
        $newId = $rotated['credential']['credentialId'];
        $newHeader = 'Service ' . $newId . '.' . $rotated['secret'];
        self::assertNotNull($service->authenticate($newHeader, 'client-a'));
        $firstRevocation = $service->revoke($deployment, $newId);
        self::assertSame('revoked', $firstRevocation['status']);
        self::assertSame($firstRevocation, $service->revoke($deployment, $newId));
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

    public function testOperatorRotationIsFiniteAndFailsClosedOnAmbiguity(): void
    {
        $account = $this->account();
        $deployment = $this->connection->fetchOne("INSERT INTO commercial.deployments
            (commercial_account_id, installation_id, mode) VALUES (?, uuidv7(), 'on_prem') RETURNING id", [$account]);
        $service = new DeploymentCredentialService($this->connection, new DeploymentScopeRegistry(),
            new DatabaseRateLimiter($this->connection,
                new TokenCodec('test-only-control-token-secret-at-least-32-chars')));
        $old = $service->create($deployment, ['entitlements.read', 'usage.write'],
            (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
        self::assertNotNull($old);
        $metadata = $service->activeRuntimeCredential($deployment);
        self::assertSame($old['credential']['credentialId'], $metadata['credentialId']);
        self::assertGreaterThanOrEqual(1, $metadata['daysUntilExpiry']);
        $command = new \ControlPlane\Infrastructure\Console\RotateDeploymentCredentialCommand(
            $service, new DeploymentCredentialLifetime('30'));
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['--deployment-id' => $deployment]));
        $output = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, substr_count($tester->getDisplay(), $output['credential']));
        self::assertSame(['entitlements.read', 'usage.write'], $output['scopes']);
        self::assertNull($service->authenticate('Service ' . $old['credential']['credentialId'] . '.'
            . $old['secret'], 'rotation-test'));
        self::assertNotNull($service->authenticate('Service ' . $output['credential'], 'rotation-test'));
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), new \DateTimeImmutable($output['expiresAt']));
        $status = new \Symfony\Component\Console\Tester\CommandTester(
            new \ControlPlane\Infrastructure\Console\DeploymentCredentialStatusCommand($service));
        self::assertSame(0, $status->execute(['--deployment-id' => $deployment]));
        self::assertStringNotContainsString($output['credential'], $status->getDisplay());
        self::assertGreaterThanOrEqual(29, json_decode($status->getDisplay(), true)['daysUntilExpiry']);
        $service->create($deployment, ['entitlements.read', 'usage.write'],
            (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
        self::assertSame(1, (new \Symfony\Component\Console\Tester\CommandTester($command))
            ->execute(['--deployment-id' => $deployment]));
        self::assertNotNull($service->authenticate('Service ' . $output['credential'], 'rotation-test'));
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
