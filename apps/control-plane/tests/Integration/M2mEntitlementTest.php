<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Entitlement\Application\DeploymentEntitlementIssuer;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Entitlement\Application\EntitlementSigner;
use ControlPlane\Entitlement\Application\SigningKeyResolver;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Infrastructure\Http\M2mEntitlementController;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopePolicy;
use ControlPlane\M2m\DeploymentScopeRegistry;
use Symfony\Component\HttpFoundation\Request;

final class M2mEntitlementTest extends DatabaseTestCase
{
    public function testScopeAndCredentialDeploymentBinding(): void
    {
        $account = $this->account('m2m-entitlement-account');
        $firstInstallation = $this->uuid();
        $secondInstallation = $this->uuid();
        $first = $this->deployment($account, $firstInstallation);
        $second = $this->deployment($account, $secondInstallation);
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys
            (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from)
            VALUES ('test-m2m-entitlement', 'Ed25519', 'entitlement', decode(?, 'hex'),
                    'vault://test/m2m', 'active', now() - interval '1 day')",
            [bin2hex(sodium_crypto_sign_publickey($pair))]);
        $resolver = new class($secret) implements SigningKeyResolver {
            public function __construct(private string $secret) {}
            public function resolve(string $secretReference): ?string
            {
                return $secretReference === 'vault://test/m2m' ? $this->secret : null;
            }
        };
        $credentials = new DeploymentCredentialService($this->connection, new DeploymentScopeRegistry(),
            new DatabaseRateLimiter($this->connection,
                new TokenCodec('test-only-control-token-secret-at-least-32-chars')));
        $controller = new M2mEntitlementController($credentials, new DeploymentScopePolicy(),
            new DeploymentEntitlementIssuer($this->connection,
                new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection)),
                new EntitlementSigner($this->connection, $resolver)));
        $withoutScope = $credentials->create($first, ['usage.write']);
        $withScope = $credentials->create($second, ['entitlements.read']);
        self::assertNotNull($withoutScope);
        self::assertNotNull($withScope);
        self::assertSame(401, $controller->latest(Request::create('/api/m2m/entitlements/latest'))->getStatusCode());
        self::assertSame(403, $controller->latest($this->request($withoutScope))->getStatusCode());
        $response = $controller->latest($this->request($withScope, $first));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['documentBase64', 'signatureBase64', 'keyId'],
            array_keys(json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR)));
        $document = json_decode(base64_decode(json_decode($response->getContent(), true)['documentBase64'], true),
            true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($secondInstallation, $document['installationId']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.deployment_entitlement_documents WHERE deployment_id = ?', [$first]));
    }

    private function deployment(string $account, string $installation): string
    {
        $id = $this->connection->fetchOne("INSERT INTO commercial.deployments
            (commercial_account_id, installation_id, mode, status)
            VALUES (?, ?, 'on_prem', 'active') RETURNING id", [$account, $installation]);
        self::assertIsString($id);
        return $id;
    }

    /** @param array{credential:array<string,mixed>,secret:string} $credential */
    private function request(array $credential, ?string $attemptedDeployment = null): Request
    {
        $query = $attemptedDeployment === null ? [] : ['deploymentId' => $attemptedDeployment];
        return Request::create('/api/m2m/entitlements/latest', 'GET', $query, [], [], [
            'HTTP_AUTHORIZATION' => 'Service ' . $credential['credential']['credentialId'] . '.' . $credential['secret'],
        ]);
    }
}
