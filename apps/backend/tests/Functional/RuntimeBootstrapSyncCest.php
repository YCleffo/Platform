<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Environment;
use App\PlatformRuntime\DeploymentEntitlementPuller;
use App\PlatformRuntime\PostgresEntitlementApplier;
use App\PlatformRuntime\PostgresInstallationRepository;
use App\PlatformRuntime\RuntimeEntitlementSynchronizer;
use App\PlatformRuntime\RuntimeInstallationBootstrap;
use App\PlatformRuntime\SodiumEntitlementSignatureVerifier;
use App\PlatformRuntime\TrustedEntitlementKeyRegistry;
use App\Tests\Support\FunctionalTester;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class RuntimeBootstrapSyncCest
{
    public function bootstrapIdentityAndSyncApplyVerifiedSnapshot(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $id = (string) $db->createCommand('SELECT uuidv7()::text')->queryScalar();
            $identity = ['installationId' => $id, 'mode' => 'shared', 'environment' => 'test',
                'applicationVersion' => '4.7.0', 'releaseId' => 'release-4-7', 'releaseChannel' => 'stable'];
            $bootstrap = new RuntimeInstallationBootstrap($db);
            assertSame($id, $bootstrap->initialize($identity));
            assertSame($id, $bootstrap->initialize($identity));
            foreach ([['installationId' => (string) $db->createCommand('SELECT uuidv7()::text')->queryScalar()],
                ['mode' => 'dedicated'], ['environment' => 'production'], ['releaseId' => 'other-release']] as $change) {
                try { $bootstrap->initialize(array_replace($identity, $change));
                    throw new \RuntimeException('Mismatched bootstrap identity was accepted.');
                } catch (DomainException) { assertTrue(true); }
            }
            assertSame('provisioned', $db->createCommand('SELECT status::text FROM platform_runtime.installations')->queryScalar());

            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $keys = new TrustedEntitlementKeyRegistry(['test-bootstrap-key' => [
                'publicKeyBase64' => base64_encode(sodium_crypto_sign_publickey($pair)), 'status' => 'trusted',
            ]]);
            $document = json_encode(['documentVersion' => 1, 'sequence' => 1, 'installationId' => $id,
                'issuedAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 10),
                'validFrom' => gmdate('Y-m-d\TH:i:s\Z', time() - 10),
                'validUntil' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
                'deploymentStatus' => 'active', 'features' => (object) ['bootstrap.module' => true]],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $response = ['documentBase64' => base64_encode($document),
                'signatureBase64' => base64_encode(str_repeat('x', 64)), 'keyId' => 'test-bootstrap-key'];
            $puller = new class($response) implements DeploymentEntitlementPuller {
                public function __construct(public array $response) {}
                public function pull(string $url, string $authorization): array
                {
                    if ($url !== 'https://control.example' || $authorization !== 'Service ' . getenv('TEST_BOOTSTRAP_CREDENTIAL')) {
                        throw new DomainException('Wrong entitlement pull configuration.');
                    }
                    return $this->response;
                }
            };
            $credential = '00000000-0000-4000-8000-000000000001.' . str_repeat('a', 43);
            putenv('TEST_BOOTSTRAP_CREDENTIAL=' . $credential);
            $sync = new RuntimeEntitlementSynchronizer(new PostgresInstallationRepository($db),
                new PostgresEntitlementApplier($db, new SodiumEntitlementSignatureVerifier($keys)),
                $puller, 'https://control.example', 'env:TEST_BOOTSTRAP_CREDENTIAL');
            try { $sync->sync(); throw new \RuntimeException('Invalid signature was accepted.'); }
            catch (DomainException) { assertSame('provisioned', $db->createCommand(
                'SELECT status::text FROM platform_runtime.installations')->queryScalar()); }
            $puller->response['signatureBase64'] = base64_encode(sodium_crypto_sign_detached($document, $secret));
            assertSame(1, $sync->sync());
            assertSame(1, $sync->sync());
            assertSame('active', $db->createCommand('SELECT status::text FROM platform_runtime.installations')->queryScalar());
        } finally {
            putenv('TEST_BOOTSTRAP_CREDENTIAL');
            $transaction->rollBack();
        }
    }
}
