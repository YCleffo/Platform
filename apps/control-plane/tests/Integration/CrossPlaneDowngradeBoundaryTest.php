<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use App\PlatformRuntime\PostgresEffectiveRuntimeEntitlements;
use App\PlatformRuntime\PostgresEntitlementApplier;
use App\PlatformRuntime\PostgresEntitlementDocumentRepository;
use App\PlatformRuntime\PostgresInstallationRepository;
use App\PlatformRuntime\RuntimeInstallationBootstrap;
use App\PlatformRuntime\SodiumEntitlementSignatureVerifier;
use App\PlatformRuntime\TrustedEntitlementKeyRegistry;
use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\PlanChangeInvoiceIssuer;
use ControlPlane\Billing\Domain\ProrationCalculator;
use ControlPlane\Deployment\Application\ReserveDeployment;
use ControlPlane\Deployment\Application\StartDeploymentProvisioning;
use ControlPlane\Entitlement\Application\DeploymentEntitlementIssuer;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Entitlement\Application\EntitlementSigner;
use ControlPlane\Entitlement\Application\SigningKeyResolver;
use ControlPlane\Payment\Application\PaymentCreator;
use ControlPlane\M2m\DeploymentCredentialService;
use ControlPlane\M2m\DeploymentScopeRegistry;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Subscription\Application\ApplySubscriptionPlanChange;
use ControlPlane\Subscription\Application\ProcessScheduledPlanChanges;
use ControlPlane\Subscription\Application\RequestSubscriptionPlanChange;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

#[Group('cross-plane')]
final class CrossPlaneDowngradeBoundaryTest extends RefundTestCase
{
    /** @return array<string, array{bool}> */
    public static function downgradeCases(): array
    {
        return ['paid' => [true], 'free' => [false]];
    }

    #[DataProvider('downgradeCases')]
    public function testDowngradeAtRealPeriodBoundary(bool $paidDowngrade): void
    {
        if (getenv('CROSS_PLANE_REQUIRED') !== '1' || getenv('PHASE47_CROSS_PLANE') !== '1') {
            self::fail('Required cross-plane prerequisites are missing.');
        }
        $backendRoot = is_dir('/backend') ? '/backend' : dirname(__DIR__, 3) . '/backend';
        require_once $backendRoot . '/vendor/autoload.php';
        \App\Environment::prepare();
        $container = (new \Yiisoft\Yii\Runner\Http\HttpApplicationRunner(
            rootPath: $backendRoot, environment: 'test'))->getContainer();
        $core = $container->get(\Yiisoft\Db\Connection\ConnectionInterface::class);
        $coreTransaction = $core->beginTransaction();
        $suffix = bin2hex(random_bytes(7));
        try {
            [$owner, $account, $profile] = $this->readyAccount('boundary-' . $suffix);
            $deployment = (new ReserveDeployment($this->connection, 'platform.example'))
                ->reserveCloud($account, 'shared', 'boundary-' . $suffix, 'boundary-operation-' . $suffix);
            (new StartDeploymentProvisioning($this->connection,
                new DeploymentCredentialService($this->connection, new DeploymentScopeRegistry(),
                    new DatabaseRateLimiter($this->connection,
                        new TokenCodec('test-only-control-token-secret-at-least-32-chars'))),
                new \ControlPlane\M2m\DeploymentCredentialLifetime('30')))->start($deployment);
            $installationId = (string) $this->connection->fetchOne(
                'SELECT installation_id FROM commercial.deployments WHERE id=?', [$deployment]);
            (new RuntimeInstallationBootstrap($core))->initialize([
                'installationId' => $installationId, 'mode' => 'shared', 'environment' => 'test',
                'applicationVersion' => '4.7.0', 'releaseId' => 'boundary-test', 'releaseChannel' => 'stable',
            ]);
            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $public = sodium_crypto_sign_publickey($pair);
            $keyId = 'boundary-key-' . $suffix;
            $this->connection->executeStatement("INSERT INTO commercial.signing_keys
                (key_id, algorithm, purpose, public_key, private_key_secret_ref, status, valid_from)
                VALUES (?, 'Ed25519', 'entitlement', decode(?, 'hex'), ?, 'active', now() - interval '1 day')",
                [$keyId, bin2hex($public), 'vault://test/' . $keyId]);
            $resolver = new class($secret, $keyId) implements SigningKeyResolver {
                public function __construct(private string $secret, private string $keyId) {}
                public function resolve(string $reference): ?string
                { return $reference === 'vault://test/' . $this->keyId ? $this->secret : null; }
            };
            $issuer = new DeploymentEntitlementIssuer($this->connection,
                new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection)),
                new EntitlementSigner($this->connection, $resolver));
            $installations = new PostgresInstallationRepository($core);
            $verifier = new SodiumEntitlementSignatureVerifier(new TrustedEntitlementKeyRegistry([
                $keyId => ['publicKeyBase64' => base64_encode($public), 'status' => 'trusted'],
            ]));
            $applier = new PostgresEntitlementApplier($core, $verifier);
            $runtime = new PostgresEffectiveRuntimeEntitlements($installations,
                new PostgresEntitlementDocumentRepository($core), $verifier);

            $oldFeature = $this->feature('boundary.' . $suffix . '.old', 'boolean');
            $paidFeature = $this->feature('boundary.' . $suffix . '.paid', 'boolean');
            $freeFeature = $this->feature('boundary.' . $suffix . '.free', 'boolean');
            [$oldPlan, $oldPrice] = $this->planPrice('boundary-' . $suffix . '-old',
                status: 'draft', featureValues: [$oldFeature => true]);
            [$paidPlan, $paidPrice] = $this->planPrice('boundary-' . $suffix . '-paid',
                status: 'draft', featureValues: [$paidFeature => true]);
            [$freePlan, $freePrice] = $this->planPrice('boundary-' . $suffix . '-free',
                status: 'draft', featureValues: [$freeFeature => true]);
            $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor=2000 WHERE id=?', [$oldPrice]);
            $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor=500 WHERE id=?', [$paidPrice]);
            $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor=0 WHERE id=?', [$freePrice]);
            foreach ([$oldPlan, $paidPlan, $freePlan] as $planId) {
                $this->connection->executeStatement("UPDATE commercial.plans SET status='published',
                    published_at=now() WHERE id=?", [$planId]);
            }
            $source = $this->draftSubscription($account, $profile, [$oldPrice]);
            // A genuine short remaining term avoids mutating immutable usage history.
            $activation = $this->activationInstant()->modify('-1 month')->modify('+5 seconds');
            $this->activatePaid($account, $source, $activation, $this->uuid());
            $this->applyDocument($issuer, $deployment, $installationId, $applier);
            self::assertSame(['boundary.' . $suffix . '.old' => true], $runtime->forInstallation($installationId));

            $requests = new RequestSubscriptionPlanChange($this->connection,
                new AccountAuthorization($this->connection), new ProrationCalculator(),
                new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator()));
            $change = $requests->request($owner, $account, $source,
                $paidDowngrade ? $paidPrice : $freePrice, 1,
                'boundary-change-' . $suffix, $this->uuid());
            self::assertSame('scheduled', $change['status']);
            $this->applyDocument($issuer, $deployment, $installationId, $applier);
            self::assertSame(['boundary.' . $suffix . '.old' => true], $runtime->forInstallation($installationId));
            $this->crossBoundary($source);
            $worker = new ProcessScheduledPlanChanges($this->connection, new PlanChangeInvoiceIssuer(),
                new PaymentCreator($this->settlement()), new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator()));
            self::assertGreaterThanOrEqual(1, $worker->runBatch(new \DateTimeImmutable('now'), 100, $this->uuid()));
            if ($paidDowngrade) {
                self::assertSame('payment_required', $this->connection->fetchOne(
                    'SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
                $this->applyDocument($issuer, $deployment, $installationId, $applier);
                self::assertSame([], $runtime->forInstallation($installationId));
                $invoiceId = (string) $this->connection->fetchOne(
                    'SELECT invoice_id FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]);
                $paymentId = (string) $this->connection->fetchOne('SELECT id FROM commercial.payments WHERE invoice_id=?', [$invoiceId]);
                $attempt = $this->attemptBeginner()->begin($paymentId,
                    $this->providerAccount('boundary-' . $suffix), 'bank_transfer', $this->uuid());
                $this->attemptBinder()->bind($attempt['attempt_id'], 'boundary-provider-' . $suffix);
                $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null,
                    new \DateTimeImmutable('now'), $this->uuid());
            }
            // Entitlement composition uses PostgreSQL now(), which is fixed at
            // transaction start; observe the completed switch in a new one.
            $this->connection->commit();
            $this->connection->beginTransaction();
            self::assertSame('applied', $this->connection->fetchOne(
                'SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame('cancelled', $this->connection->fetchOne(
                'SELECT status FROM commercial.subscriptions WHERE id=?', [$source]));
            $feature = $paidDowngrade ? 'paid' : 'free';
            self::assertSame(['boundary.' . $suffix . '.' . $feature => true],
                (new EffectiveEntitlements($this->connection,
                    new AccountAuthorization($this->connection)))->forDeploymentAccount($account)['features']);
            $this->applyDocument($issuer, $deployment, $installationId, $applier);
            self::assertSame(['boundary.' . $suffix . '.' . $feature => true],
                $runtime->forInstallation($installationId));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->connection->beginTransaction();
            $coreTransaction->rollBack();
        }
    }

    private function crossBoundary(string $subscriptionId): void
    {
        $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne(
            'SELECT current_period_end FROM commercial.subscriptions WHERE id=?', [$subscriptionId]));
        $this->connection->commit();
        $delay = $boundary->getTimestamp() - time() + 1;
        if ($delay > 0) {
            sleep($delay);
        }
        $this->connection->beginTransaction();
    }

    private function applyDocument(DeploymentEntitlementIssuer $issuer, string $deploymentId,
        string $installationId, PostgresEntitlementApplier $applier): void
    {
        $envelope = $issuer->ensureLatest($deploymentId);
        $document = base64_decode($envelope['documentBase64'], true);
        $signature = base64_decode($envelope['signatureBase64'], true);
        self::assertIsString($document);
        self::assertIsString($signature);
        $applier->apply($installationId, $document, $signature, $envelope['keyId']);
    }
}
