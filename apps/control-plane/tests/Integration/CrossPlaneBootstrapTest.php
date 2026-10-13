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
use ControlPlane\Infrastructure\Http\M2mEntitlementController;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopePolicy;
use ControlPlane\M2m\DeploymentScopeRegistry;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Group;

#[Group('cross-plane')]
final class CrossPlaneBootstrapTest extends DatabaseTestCase
{
    public function testStartInitSyncReadyAcrossIsolatedDatabases(): void
    {
        if (getenv('CROSS_PLANE_REQUIRED') !== '1') {
            self::fail('Required cross-plane test prerequisite is missing: CROSS_PLANE_REQUIRED=1.');
        }
        if (getenv('PHASE47_CROSS_PLANE') !== '1') {
            self::fail('Required cross-plane test prerequisite is missing: PHASE47_CROSS_PLANE=1.');
        }
        $backendRoot = is_dir('/backend') ? '/backend' : dirname(__DIR__, 3) . '/backend';
        $backendAutoload = $backendRoot . '/vendor/autoload.php';
        if (!is_file($backendAutoload)) {
            self::fail('Required cross-plane backend autoload prerequisite is missing.');
        }
        require_once $backendAutoload;
        \App\Environment::prepare();
        $container = (new \Yiisoft\Yii\Runner\Http\HttpApplicationRunner(
            rootPath: $backendRoot, environment: 'test'))->getContainer();
        $core = $container->get(\Yiisoft\Db\Connection\ConnectionInterface::class);
        $coreTransaction = $core->beginTransaction();
        try {
            $account = $this->account('cross-plane-bootstrap');
            $deployment = (new ReserveDeployment($this->connection, 'platform.example'))
                ->reserveCloud($account, 'shared', 'cross-plane-bootstrap', 'cross-plane-bootstrap-operation');
            $credentials = new DeploymentCredentialService($this->connection, new DeploymentScopeRegistry(),
                new DatabaseRateLimiter($this->connection,
                    new TokenCodec('test-only-control-token-secret-at-least-32-chars')));
            $bootstrapCredential = (new StartDeploymentProvisioning($this->connection, $credentials,
                new \ControlPlane\M2m\DeploymentCredentialLifetime('30')))->start($deployment);
            $installationId = $bootstrapCredential['installationId'];
            $bootstrap = new \App\PlatformRuntime\RuntimeInstallationBootstrap($core);
            self::assertSame($installationId, $bootstrap->initialize([
                'installationId' => $installationId, 'mode' => 'shared', 'environment' => 'test',
                'applicationVersion' => '4.7.0', 'releaseId' => 'cross-plane-test', 'releaseChannel' => 'stable',
            ]));

            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $public = sodium_crypto_sign_publickey($pair);
            $this->connection->executeStatement("INSERT INTO commercial.signing_keys
                (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from)
                VALUES ('cross-plane-key', 'Ed25519', 'entitlement', decode(?, 'hex'),
                        'vault://test/cross-plane', 'active', now() - interval '1 day')", [bin2hex($public)]);
            $resolver = new class($secret) implements SigningKeyResolver {
                public function __construct(private string $secret) {}
                public function resolve(string $reference): ?string
                { return $reference === 'vault://test/cross-plane' ? $this->secret : null; }
            };
            $pullController = new M2mEntitlementController($credentials, new DeploymentScopePolicy(),
                new DeploymentEntitlementIssuer($this->connection,
                    new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection)),
                    new EntitlementSigner($this->connection, $resolver)));
            $puller = new class($pullController) implements \App\PlatformRuntime\DeploymentEntitlementPuller {
                public function __construct(private M2mEntitlementController $controller) {}
                /** @return array<string, mixed> */
                public function pull(string $url, string $authorization): array
                {
                    if ($url !== 'https://control.example') { throw new \DomainException('Wrong Control Plane URL.'); }
                    $response = $this->controller->latest(Request::create('/api/m2m/entitlements/latest',
                        'GET', [], [], [], ['HTTP_AUTHORIZATION' => $authorization]));
                    if ($response->getStatusCode() !== 200) { throw new \DomainException('Entitlement pull failed.'); }
                    $body = $response->getContent();
                    if (!is_string($body)) { throw new \DomainException('Entitlement pull body is unavailable.'); }
                    $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($decoded)) { throw new \DomainException('Entitlement pull body is invalid.'); }
                    return $decoded;
                }
            };
            self::assertSame(401, $pullController->latest(Request::create('/api/m2m/entitlements/latest',
                'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Service ' . $bootstrapCredential['credentialId'] . '.' . str_repeat('A', 43)]))->getStatusCode());
            putenv('CROSS_PLANE_CREDENTIAL=' . $bootstrapCredential['credentialId'] . '.'
                . $bootstrapCredential['credentialSecret']);
            $installations = new \App\PlatformRuntime\PostgresInstallationRepository($core);
            $verifier = new \App\PlatformRuntime\SodiumEntitlementSignatureVerifier(
                new \App\PlatformRuntime\TrustedEntitlementKeyRegistry(['cross-plane-key' => [
                    'publicKeyBase64' => base64_encode($public), 'status' => 'trusted',
                ]]));
            $sync = new \App\PlatformRuntime\RuntimeEntitlementSynchronizer($installations,
                new \App\PlatformRuntime\PostgresEntitlementApplier($core, $verifier), $puller,
                'https://control.example', 'env:CROSS_PLANE_CREDENTIAL',
                new \App\PlatformRuntime\RuntimeSecretResolver());
            $sequence = $sync->sync();
            self::assertSame(1, $sequence);
            self::assertSame('active', $core->createCommand('SELECT status::text FROM platform_runtime.installations')->queryScalar());
            $reportController = new M2mDeploymentStatusController($credentials, new DeploymentScopePolicy(),
                new ReportDeploymentStatus($this->connection, $credentials));
            $reported = $reportController->report(Request::create('/api/m2m/deployment/status', 'POST',
                [], [], [], ['CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Service ' . $bootstrapCredential['credentialId'] . '.'
                        . $bootstrapCredential['credentialSecret']],
                json_encode(['status' => 'ready', 'entitlementSequence' => $sequence], JSON_THROW_ON_ERROR)));
            self::assertSame(200, $reported->getStatusCode());
            self::assertSame('active', $this->connection->fetchOne(
                'SELECT status FROM commercial.deployments WHERE id = ?', [$deployment]));
            self::assertSame('succeeded', $this->connection->fetchOne(
                "SELECT status FROM commercial.deployment_operations WHERE deployment_id = ? AND operation_type = 'provision'",
                [$deployment]));
            $repeat = $reportController->report(Request::create('/api/m2m/deployment/status', 'POST',
                [], [], [], ['CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Service ' . $bootstrapCredential['credentialId'] . '.'
                        . $bootstrapCredential['credentialSecret']],
                json_encode(['status' => 'ready', 'entitlementSequence' => $sequence], JSON_THROW_ON_ERROR)));
            self::assertSame(200, $repeat->getStatusCode());
            $repeatBody = $repeat->getContent();
            self::assertIsString($repeatBody);
            self::assertSame('already_ready', json_decode($repeatBody, true, 8, JSON_THROW_ON_ERROR)['status']);
        } finally {
            putenv('CROSS_PLANE_CREDENTIAL');
            $coreTransaction->rollBack();
        }
    }
}
