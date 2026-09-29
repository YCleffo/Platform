<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Environment;
use App\PlatformRuntime\PostgresEffectiveRuntimeEntitlements;
use App\PlatformRuntime\PostgresEntitlementApplier;
use App\PlatformRuntime\PostgresEntitlementDocumentRepository;
use App\PlatformRuntime\PostgresInstallationRepository;
use App\PlatformRuntime\SodiumEntitlementSignatureVerifier;
use App\PlatformRuntime\TrustedEntitlementKeyRegistry;
use App\Tests\Support\FunctionalTester;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class RuntimeEntitlementsCest
{
    public function appliesOnlyTrustedCurrentDocuments(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $installation = $db->createCommand("INSERT INTO platform_runtime.installations
                (deployment_mode, environment, application_version, release_id, release_channel)
                VALUES ('on_prem', 'test', '1.0', 'test-release', 'stable') RETURNING id::text")->queryScalar();
            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $public = sodium_crypto_sign_publickey($pair);
            $keys = new TrustedEntitlementKeyRegistry([
                'test-key' => ['publicKeyBase64' => base64_encode($public), 'status' => 'trusted'],
                'revoked-key' => ['publicKeyBase64' => base64_encode($public), 'status' => 'revoked'],
            ]);
            $verifier = new SodiumEntitlementSignatureVerifier($keys);
            $applier = new PostgresEntitlementApplier($db, $verifier);
            $effective = new PostgresEffectiveRuntimeEntitlements(new PostgresInstallationRepository($db),
                new PostgresEntitlementDocumentRepository($db), $verifier);
            $active = $this->document($installation, 1, 'active', (object) ['module.crm' => true]);
            $signature = sodium_crypto_sign_detached($active, $secret);
            $applier->apply($installation, $active, $signature, 'test-key');
            $applier->apply($installation, $active, $signature, 'test-key');
            assertSame(['module.crm' => true], $effective->forInstallation($installation));
            assertSame(1, (int) $db->createCommand('SELECT current_entitlement_sequence FROM platform_runtime.installations WHERE id = CAST(:id AS uuid)',
                [':id' => $installation])->queryScalar());

            $this->reject(static fn() => $applier->apply($installation, $active . ' ', $signature, 'test-key'));
            $this->reject(static fn() => $applier->apply($installation, $active, $signature, 'revoked-key'));
            $this->reject(static fn() => $applier->apply($installation, $active, $signature, 'unknown-key'));
            $otherInstallation = (string) $db->createCommand('SELECT uuidv7()::text')->queryScalar();
            $foreign = $this->document($otherInstallation, 2, 'active', (object) ['module.crm' => true]);
            $this->reject(static fn() => $applier->apply($installation, $foreign,
                sodium_crypto_sign_detached($foreign, $secret), 'test-key'));
            $expired = $this->document($installation, 2, 'active', (object) ['module.crm' => true], -10);
            $this->reject(static fn() => $applier->apply($installation, $expired,
                sodium_crypto_sign_detached($expired, $secret), 'test-key'));
            $future = $this->document($installation, 2, 'active', (object) ['module.crm' => true], 3600);
            $this->reject(static fn() => $applier->apply($installation, $future,
                sodium_crypto_sign_detached($future, $secret), 'test-key'));
            $this->reject(fn() => $applier->apply($installation, $this->document($installation, 1, 'suspended', new \stdClass()),
                sodium_crypto_sign_detached($this->document($installation, 1, 'suspended', new \stdClass()), $secret), 'test-key'));

            $suspended = $this->document($installation, 2, 'suspended', new \stdClass());
            $applier->apply($installation, $suspended, sodium_crypto_sign_detached($suspended, $secret), 'test-key');
            assertSame([], $effective->forInstallation($installation));
            assertSame(2, (int) $db->createCommand('SELECT current_entitlement_sequence FROM platform_runtime.installations WHERE id = CAST(:id AS uuid)',
                [':id' => $installation])->queryScalar());
            $this->reject(static fn() => $applier->apply($installation, $active, $signature, 'test-key'));
        } finally {
            $transaction->rollBack();
        }
    }

    private function document(string $installation, int $sequence, string $status, \stdClass $features,
        ?int $validUntilOffset = null): string
    {
        $issued = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        return json_encode(['documentVersion' => 1, 'sequence' => $sequence, 'installationId' => $installation,
            'issuedAt' => $issued,
            'validFrom' => gmdate('Y-m-d\TH:i:s\Z', time() + ($validUntilOffset === 3600 ? 60 : -3600)),
            'validUntil' => gmdate('Y-m-d\TH:i:s\Z', time() + ($validUntilOffset ?? 3600)),
            'deploymentStatus' => $status, 'features' => $features],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function reject(\Closure $operation): void
    {
        try { $operation(); }
        catch (DomainException) { assertTrue(true); return; }
        throw new \RuntimeException('Invalid entitlement document was accepted.');
    }
}
