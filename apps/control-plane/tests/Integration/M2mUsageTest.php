<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Infrastructure\Http\M2mUsageController;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopePolicy;
use ControlPlane\M2m\DeploymentScopeRegistry;
use ControlPlane\Usage\Application\ApplyUsageDelta;
use ControlPlane\Usage\Application\IngestDeploymentUsage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class M2mUsageTest extends SubscriptionTestCase
{
    public function testAuthenticationScopeStateAndStrictInput(): void
    {
        [$account, $controller, $credentials] = $this->fixture('m2m-auth');
        $deployment = $this->deployment($account);
        $issued = $credentials->create($deployment, ['usage.write'], (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
        self::assertNotNull($issued);
        $header = $this->header($issued);
        self::assertSame(401, $this->post($controller, null, 'auth-missing')->getStatusCode());
        self::assertSame(401, $this->post($controller, 'Bearer ' . $issued['secret'], 'auth-bearer')->getStatusCode());
        self::assertSame(401, $this->post($controller, 'Service ' . $issued['credential']['credentialId'] . '.' . str_repeat('A', 43), 'auth-wrong')->getStatusCode());

        $noScope = $credentials->create($deployment, ['entitlements.read'], (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
        self::assertNotNull($noScope);
        self::assertSame(403, $this->post($controller, $this->header($noScope), 'auth-no-scope')->getStatusCode());

        self::assertSame(422, $this->post($controller, $header, 'auth-spoof', ['featureCode' => 'm2m.counter', 'delta' => 1, 'commercialAccountId' => $account])->getStatusCode());
        self::assertSame(422, $this->post($controller, $header, 'auth-float', ['featureCode' => 'm2m.counter', 'delta' => 1.5])->getStatusCode());
        self::assertSame(422, $this->post($controller, $header, 'auth-zero', ['featureCode' => 'm2m.counter', 'delta' => 0])->getStatusCode());
        self::assertSame(422, $this->post($controller, $header, '')->getStatusCode());
        self::assertSame(422, $this->post($controller, $header, str_repeat('k', 201))->getStatusCode());
        self::assertSame(413, $this->post($controller, $header, 'oversized', ['featureCode' => str_repeat('x', 2100), 'delta' => 1])->getStatusCode());

        foreach (['reserved', 'provisioning', 'suspended', 'failed'] as $status) {
            $this->connection->executeStatement('UPDATE commercial.deployments SET status = ? WHERE id = ?', [$status, $deployment]);
            self::assertSame(403, $this->post($controller, $header, 'state-' . $status)->getStatusCode());
        }
        $this->connection->executeStatement("UPDATE commercial.deployments SET status = 'decommissioned' WHERE id = ?", [$deployment]);
        self::assertSame(401, $this->post($controller, $header, 'state-decommissioned')->getStatusCode());
        $this->connection->executeStatement("UPDATE commercial.deployments SET status = 'active' WHERE id = ?", [$deployment]);
        $this->connection->executeStatement('UPDATE commercial.deployments SET archived_at = now() WHERE id = ?', [$deployment]);
        self::assertSame(401, $this->post($controller, $header, 'state-archived')->getStatusCode());
        $this->connection->executeStatement('UPDATE commercial.deployments SET archived_at = NULL WHERE id = ?', [$deployment]);
        $this->connection->executeStatement("UPDATE commercial.service_credentials SET created_at = now() - interval '3 days', expires_at = now() - interval '1 day' WHERE credential_id = ?", [$issued['credential']['credentialId']]);
        self::assertSame(401, $this->post($controller, $header, 'auth-expired')->getStatusCode());
        $this->connection->executeStatement('UPDATE commercial.service_credentials SET expires_at = NULL WHERE credential_id = ?', [$issued['credential']['credentialId']]);
        $credentials->revoke($deployment, $issued['credential']['credentialId']);
        self::assertSame(401, $this->post($controller, $header, 'auth-revoked')->getStatusCode());
    }

    public function testUsageReplayAndDeploymentNamespace(): void
    {
        [$account, $controller, $credentials] = $this->fixture('m2m-usage');
        $firstDeployment = $this->deployment($account);
        $secondDeployment = $this->deployment($account);
        $first = $credentials->create($firstDeployment, ['usage.write'], (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
        $second = $credentials->create($secondDeployment, ['usage.write'], (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
        self::assertNotNull($first);
        self::assertNotNull($second);
        $auditBefore = (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.audit_events');
        $outboxBefore = (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.outbox_events');
        $body = ['featureCode' => 'm2m.counter', 'delta' => 1];
        $result = $this->post($controller, $this->header($first), 'same-key', $body);
        self::assertSame(Response::HTTP_OK, $result->getStatusCode());
        $content = $result->getContent();
        self::assertIsString($content);
        self::assertSame(['featureCode' => 'm2m.counter', 'mode' => 'counter', 'used' => 1,
            'limit' => 10, 'remaining' => 9, 'exhausted' => false], json_decode($content, true));
        self::assertSame($result->getContent(), $this->post($controller, $this->header($first), 'same-key', $body)->getContent());
        self::assertSame(409, $this->post($controller, $this->header($first), 'same-key', ['featureCode' => 'm2m.counter', 'delta' => 2])->getStatusCode());
        self::assertSame(200, $this->post($controller, $this->header($second), 'same-key', $body)->getStatusCode());
        self::assertSame(2, $this->quantity('m2m.counter'));
        $keys = $this->connection->fetchFirstColumn('SELECT idempotency_key FROM commercial.idempotency_keys WHERE idempotency_key LIKE ? OR idempotency_key LIKE ? ORDER BY idempotency_key',
            ['m2m.deployment.' . $firstDeployment . '.%', 'm2m.deployment.' . $secondDeployment . '.%']);
        self::assertCount(2, $keys);
        self::assertTrue(str_contains($keys[0], $firstDeployment) || str_contains($keys[1], $firstDeployment));
        self::assertTrue(str_contains($keys[0], $secondDeployment) || str_contains($keys[1], $secondDeployment));
        self::assertFalse(in_array('same-key', $keys, true));
        self::assertSame(422, $this->post($controller, $this->header($first), 'negative', ['featureCode' => 'm2m.counter', 'delta' => -1])->getStatusCode());
        self::assertSame(422, $this->post($controller, $this->header($first), 'unknown', ['featureCode' => 'm2m.unknown', 'delta' => 1])->getStatusCode());
        self::assertSame(422, $this->post($controller, $this->header($first), 'plain', ['featureCode' => 'm2m.plain', 'delta' => 1])->getStatusCode());
        self::assertSame($auditBefore, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.audit_events'));
        self::assertSame($outboxBefore, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.outbox_events'));
    }

    /** @return array{string, M2mUsageController, DeploymentCredentialService} */
    private function fixture(string $suffix): array
    {
        [, $account, $profile] = $this->readyAccount($suffix);
        $counter = $this->feature('m2m.counter', 'integer', 'counter');
        $plain = $this->feature('m2m.plain', 'integer');
        [, $price] = $this->planPrice($suffix . '-plan', featureValues: [$counter => 10, $plain => 5]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());
        $tokens = new TokenCodec('test-only-control-token-secret-at-least-32-chars');
        $credentials = new DeploymentCredentialService($this->connection, new DeploymentScopeRegistry(), new DatabaseRateLimiter($this->connection, $tokens));
        return [$account, new M2mUsageController($credentials, new DeploymentScopePolicy(),
            new IngestDeploymentUsage($this->connection, new ApplyUsageDelta($this->connection))), $credentials];
    }

    private function deployment(string $account): string
    {
        $id = $this->connection->fetchOne("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode, status) VALUES (?, uuidv7(), 'on_prem', 'active') RETURNING id", [$account]);
        self::assertIsString($id);
        return $id;
    }

    /** @param array{credential: array<string, mixed>, secret: string} $issued */
    private function header(array $issued): string
    {
        return 'Service ' . $issued['credential']['credentialId'] . '.' . $issued['secret'];
    }

    /** @param array<string, mixed> $body */
    private function post(M2mUsageController $controller, ?string $authorization, string $key,
        array $body = ['featureCode' => 'm2m.counter', 'delta' => 1]): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key];
        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        return $controller->ingest(Request::create('/api/m2m/usage', 'POST', [], [], [], $server,
            json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function quantity(string $code): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM commercial.usage_counters uc JOIN commercial.features f ON f.id = uc.feature_id WHERE f.code = ?', [$code]);
    }
}
