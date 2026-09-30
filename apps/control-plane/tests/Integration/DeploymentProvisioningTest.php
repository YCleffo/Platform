<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Deployment\Application\ReportDeploymentStatus;
use ControlPlane\Deployment\Application\ReserveDeployment;
use ControlPlane\Deployment\Application\StartDeploymentProvisioning;
use ControlPlane\Entitlement\Application\DeploymentEntitlementIssuer;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Entitlement\Application\EntitlementSigner;
use ControlPlane\Entitlement\Application\SigningKeyResolver;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Infrastructure\Http\M2mDeploymentStatusController;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopePolicy;
use ControlPlane\M2m\DeploymentScopeRegistry;
use Symfony\Component\HttpFoundation\Request;

final class DeploymentProvisioningTest extends SubscriptionTestCase
{
    public function testStartAndReadyRequireIssuedEntitlement(): void
    {
        [, $account, $profile] = $this->readyAccount('provisioning-flow');
        $feature = $this->feature('provisioning.module.crm', 'boolean');
        [, $price] = $this->planPrice('provisioning-flow-plan', featureValues: [$feature => true]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());
        $deployment = (new ReserveDeployment($this->connection, 'platform.example'))
            ->reserveCloud($account, 'shared', 'provisioning-flow', 'provisioning-flow-operation');
        $credentials = $this->credentials();
        $start = new StartDeploymentProvisioning($this->connection, $credentials,
            new \ControlPlane\M2m\DeploymentCredentialLifetime('30'));
        $bootstrap = $start->start($deployment);
        self::assertSame(['entitlements.read', 'usage.write', 'deployment.status.write'], $bootstrap['scopes']);
        $expiry = $this->connection->fetchOne('SELECT expires_at::text FROM commercial.service_credentials
            WHERE credential_id = ?', [$bootstrap['credentialId']]);
        self::assertIsString($expiry);
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), new \DateTimeImmutable($expiry));
        self::assertSame('provisioning', $this->field($deployment, 'status'));
        self::assertSame('running', $this->operationField($deployment, 'status'));
        self::assertSame('runtime_bootstrap', $this->operationField($deployment, 'current_step'));
        self::assertSame('1', $this->operationField($deployment, 'attempt_count'));
        try { $start->start($deployment); self::fail('Repeated provisioning start succeeded.'); }
        catch (\DomainException) { self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM commercial.service_credentials WHERE deployment_id = ?', [$deployment])); }

        $reporter = new ReportDeploymentStatus($this->connection, $credentials);
        $controller = new M2mDeploymentStatusController($credentials, new DeploymentScopePolicy(), $reporter);
        $header = 'Service ' . $bootstrap['credentialId'] . '.' . $bootstrap['credentialSecret'];
        self::assertSame(422, $controller->report($this->request($header,
            ['status' => 'ready', 'entitlementSequence' => 1]))->getStatusCode());
        $withoutScope = $credentials->create($deployment, ['usage.write'], (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM));
        self::assertNotNull($withoutScope);
        self::assertSame(403, $controller->report($this->request('Service '
            . $withoutScope['credential']['credentialId'] . '.' . $withoutScope['secret'],
            ['status' => 'ready', 'entitlementSequence' => 1]))->getStatusCode());

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys
            (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from)
            VALUES ('test-provisioning-key', 'Ed25519', 'entitlement', decode(?, 'hex'),
                    'vault://test/provisioning', 'active', now() - interval '1 day')",
            [bin2hex(sodium_crypto_sign_publickey($pair))]);
        $resolver = new class($secret) implements SigningKeyResolver {
            public function __construct(private string $secret) {}
            public function resolve(string $reference): ?string
            { return $reference === 'vault://test/provisioning' ? $this->secret : null; }
        };
        $issuer = new DeploymentEntitlementIssuer($this->connection,
            new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection)),
            new EntitlementSigner($this->connection, $resolver));
        $issued = $issuer->ensureLatest($deployment);
        self::assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($issued['signatureBase64'], true),
            base64_decode($issued['documentBase64'], true),
            sodium_crypto_sign_publickey($pair)));
        $document = json_decode(base64_decode($issued['documentBase64'], true), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('active', $document['deploymentStatus']);
        self::assertSame(['provisioning.module.crm' => true], $document['features']);
        $ready = $controller->report($this->request($header,
            ['status' => 'ready', 'entitlementSequence' => $issued['sequence']], $this->uuid()));
        self::assertSame(200, $ready->getStatusCode());
        self::assertSame('ready', json_decode($ready->getContent(), true)['status']);
        self::assertSame('active', $this->field($deployment, 'status'));
        self::assertSame('ready', $this->field($deployment, 'provisioning_state'));
        self::assertSame('succeeded', $this->operationField($deployment, 'status'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events
            WHERE action = 'deployment.provisioned' AND resource_id = ?", [$deployment]));
        $active = $credentials->authenticate($header, 'provisioning-retry');
        self::assertNotNull($active);
        self::assertSame(['entitlements.read', 'usage.write'], $active->scopes);
        $repeat = $controller->report($this->request($header,
            ['status' => 'ready', 'entitlementSequence' => $issued['sequence']]));
        self::assertSame(200, $repeat->getStatusCode());
        self::assertSame('already_ready', json_decode($repeat->getContent(), true)['status']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events
            WHERE action = 'deployment.provisioned' AND resource_id = ?", [$deployment]));
        self::assertSame(422, $controller->report($this->request($header,
            ['status' => 'ready', 'entitlementSequence' => $issued['sequence'] + 1]))->getStatusCode());
        self::assertSame(403, $controller->report($this->request($header,
            ['status' => 'failed', 'errorCode' => 'runtime.bootstrap_failed']))->getStatusCode());
        self::assertSame($issued, $issuer->ensureLatest($deployment));
        $rotated = $credentials->rotate($deployment, $bootstrap['credentialId'],
            (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM));
        self::assertNotNull($rotated);
        $rotatedHeader = 'Service ' . $rotated['credential']['credentialId'] . '.' . $rotated['secret'];
        self::assertSame(200, $controller->report($this->request($rotatedHeader,
            ['status' => 'ready', 'entitlementSequence' => $issued['sequence']]))->getStatusCode());
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events
            WHERE action = 'deployment.provisioned' AND resource_id = ?", [$deployment]));
        $this->connection->executeStatement("UPDATE commercial.service_credentials
            SET expires_at = now() - interval '1 second' WHERE credential_id = ?", [$rotated['credential']['credentialId']]);
        self::assertSame(401, $controller->report($this->request($rotatedHeader,
            ['status' => 'ready', 'entitlementSequence' => $issued['sequence']]))->getStatusCode());
    }

    public function testFailedReportAcceptsOnlyMachineCode(): void
    {
        $account = $this->account('provisioning-failed-account');
        $deployment = (new ReserveDeployment($this->connection, 'platform.example'))
            ->reserveCloud($account, 'dedicated', 'provisioning-failed', 'provisioning-failed-operation');
        $credentials = $this->credentials();
        $bootstrap = (new StartDeploymentProvisioning($this->connection, $credentials,
            new \ControlPlane\M2m\DeploymentCredentialLifetime('30')))->start($deployment);
        $controller = new M2mDeploymentStatusController($credentials, new DeploymentScopePolicy(),
            new ReportDeploymentStatus($this->connection, $credentials));
        $header = 'Service ' . $bootstrap['credentialId'] . '.' . $bootstrap['credentialSecret'];
        self::assertSame(422, $controller->report($this->request($header,
            ['status' => 'failed', 'errorCode' => 'Stack trace: a secret']))->getStatusCode());
        self::assertSame('provisioning', $this->field($deployment, 'status'));
        self::assertSame(200, $controller->report($this->request($header,
            ['status' => 'failed', 'errorCode' => 'runtime.bootstrap_failed']))->getStatusCode());
        self::assertSame('failed', $this->field($deployment, 'status'));
        self::assertSame('failed', $this->operationField($deployment, 'status'));
        self::assertSame('runtime.bootstrap_failed', $this->operationField($deployment, 'last_error_code'));
    }

    private function credentials(): DeploymentCredentialService
    {
        return new DeploymentCredentialService($this->connection, new DeploymentScopeRegistry(),
            new DatabaseRateLimiter($this->connection,
                new TokenCodec('test-only-control-token-secret-at-least-32-chars')));
    }

    private function request(string $authorization, array $body, ?string $attemptedDeployment = null): Request
    {
        $query = $attemptedDeployment === null ? [] : ['deploymentId' => $attemptedDeployment];
        return Request::create('/api/m2m/deployment/status', 'POST', $query, [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $authorization],
            json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function field(string $deployment, string $name): string
    {
        return (string) $this->connection->fetchOne('SELECT ' . $name . '::text FROM commercial.deployments WHERE id = ?', [$deployment]);
    }

    private function operationField(string $deployment, string $name): string
    {
        return (string) $this->connection->fetchOne("SELECT " . $name . "::text FROM commercial.deployment_operations
            WHERE deployment_id = ? AND operation_type = 'provision'", [$deployment]);
    }
}
