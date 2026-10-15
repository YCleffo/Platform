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
use ControlPlane\Billing\Domain\ProrationCalculator;
use ControlPlane\Subscription\Application\ApplySubscriptionPlanChange;
use ControlPlane\Subscription\Application\CancelSubscriptionPlanChange;
use ControlPlane\Subscription\Application\RequestSubscriptionPlanChange;
use ControlPlane\Subscription\Application\ExpireSubscription;
use ControlPlane\Subscription\Application\FinalizeRefundedPlanChanges;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Tests\Support\FakeRefundProviderGateway;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Group;

#[Group('cross-plane')]
final class CrossPlaneBootstrapTest extends RefundTestCase
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
            [$owner, $account, $billingProfile] = $this->readyAccount('cross-plane-bootstrap');
            $this->connection->executeStatement('UPDATE commercial.billing_profiles SET contact_name=?, contact_email=? WHERE id=?',
                ['Cross Plane Billing', 'cross-plane-billing@example.test', $billingProfile]);
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
            $oldFeature = $this->feature('cross-plane.plan.old', 'boolean');
            $newFeature = $this->feature('cross-plane.plan.new', 'boolean');
            [, $oldPrice] = $this->planPrice('cross-plane-plan-old', currency: 'RUB', featureValues: [$oldFeature => true]);
            [$newPlan, $newPrice] = $this->planPrice('cross-plane-plan-new', currency: 'RUB', status: 'draft', featureValues: [$newFeature => true]);
            $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor = 2000 WHERE id = ?', [$newPrice]);
            $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$newPlan]);
            $source = $this->draftSubscription($account, $billingProfile, [$oldPrice]);
            $runtimeFeatures = new \App\PlatformRuntime\PostgresEffectiveRuntimeEntitlements($installations,
                new \App\PlatformRuntime\PostgresEntitlementDocumentRepository($core), $verifier);
            $initialInvoice = $this->issuer()->issue($owner, $account, $source, 'cross-plane-initial-invoice', $this->uuid());
            $initialPayment = $this->paymentCreator()->create($owner, $account, $initialInvoice['id'],
                'cross-plane-initial-payment', $this->uuid());
            $initialProvider = $this->providerAccount('cross-plane-initial');
            $initialFailed = $this->attemptBeginner()->begin($initialPayment['id'], $initialProvider,
                'bank_transfer', $this->uuid());
            $this->attemptCompleter()->complete($initialFailed['attempt_id'], 'failed', 'declined',
                new \DateTimeImmutable('now'), $this->uuid());
            self::assertSame('issued', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$initialInvoice['id']]));
            self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$initialPayment['id']]));
            self::assertSame(1, $sync->sync());
            self::assertSame([], $runtimeFeatures->forInstallation($installationId));
            $initialSucceeded = $this->attemptBeginner()->begin($initialPayment['id'], $initialProvider,
                'bank_transfer', $this->uuid());
            $this->attemptBinder()->bind($initialSucceeded['attempt_id'], 'cross-plane-initial-provider');
            $this->attemptCompleter()->complete($initialSucceeded['attempt_id'], 'succeeded', null,
                new \DateTimeImmutable('now'), $this->uuid());
            self::assertSame(2, $sync->sync());
            self::assertSame(['cross-plane.plan.old' => true], $runtimeFeatures->forInstallation($installationId));
            $change = (new RequestSubscriptionPlanChange($this->connection,
                new AccountAuthorization($this->connection), new ProrationCalculator(),
                new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator())))
                ->request($owner, $account, $source, $newPrice, 1, 'cross-plane-upgrade', $this->uuid());
            self::assertSame('payment_required', $change['status']);
            self::assertSame(2, $sync->sync());
            self::assertSame(['cross-plane.plan.old' => true], $runtimeFeatures->forInstallation($installationId));
            $invoiceId = $change['invoice_id'];
            self::assertIsString($invoiceId);
            $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'cross-plane-upgrade-payment', $this->uuid());
            $providerAccount = $this->providerAccount('cross-plane-upgrade');
            $failed = $this->attemptBeginner()->begin($payment['id'], $providerAccount, 'bank_transfer', $this->uuid());
            $this->attemptCompleter()->complete($failed['attempt_id'], 'failed', 'declined', new \DateTimeImmutable('now'), $this->uuid());
            self::assertSame(2, $sync->sync());
            self::assertSame(['cross-plane.plan.old' => true], $runtimeFeatures->forInstallation($installationId));
            $attempt = $this->attemptBeginner()->begin($payment['id'], $providerAccount, 'bank_transfer', $this->uuid());
            $this->attemptBinder()->bind($attempt['attempt_id'], 'cross-plane-upgrade-provider');
            $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());
            self::assertSame(3, $sync->sync());
            self::assertSame(['cross-plane.plan.new' => true], $runtimeFeatures->forInstallation($installationId));
            $oldEnvelope = $puller->pull('https://control.example', 'Service ' . $bootstrapCredential['credentialId'] . '.' . $bootstrapCredential['credentialSecret']);
            [$otherOwner, $otherAccount, $otherBillingProfile] = $this->readyAccount('cross-plane-isolated');
            $otherDeployment = (new ReserveDeployment($this->connection, 'platform.example'))
                ->reserveCloud($otherAccount, 'shared', 'cross-plane-isolated', 'cross-plane-isolated-operation');
            $otherCredential = (new StartDeploymentProvisioning($this->connection, $credentials,
                new \ControlPlane\M2m\DeploymentCredentialLifetime('30')))->start($otherDeployment);
            $otherFeature = $this->feature('cross-plane.tenant.other', 'boolean');
            [, $otherPrice] = $this->planPrice('cross-plane-tenant-other', featureValues: [$otherFeature => true]);
            $otherSubscription = $this->draftSubscription($otherAccount, $otherBillingProfile, [$otherPrice]);
            $otherInvoice = $this->issuer()->issue($otherOwner, $otherAccount, $otherSubscription,
                'cross-plane-isolated-invoice', $this->uuid());
            $otherPayment = $this->paymentCreator()->create($otherOwner, $otherAccount, $otherInvoice['id'],
                'cross-plane-isolated-payment', $this->uuid());
            $otherAttempt = $this->attemptBeginner()->begin($otherPayment['id'],
                $this->providerAccount('cross-plane-isolated'), 'bank_transfer', $this->uuid());
            $this->attemptBinder()->bind($otherAttempt['attempt_id'], 'cross-plane-isolated-provider');
            $this->attemptCompleter()->complete($otherAttempt['attempt_id'], 'succeeded', null,
                new \DateTimeImmutable('now'), $this->uuid());
            $otherEnvelope = $puller->pull('https://control.example', 'Service ' . $otherCredential['credentialId']
                . '.' . $otherCredential['credentialSecret']);
            $otherDocument = base64_decode((string) $otherEnvelope['documentBase64'], true);
            $otherSignature = base64_decode((string) $otherEnvelope['signatureBase64'], true);
            self::assertIsString($otherDocument);
            self::assertIsString($otherSignature);
            $parsedOther = \App\PlatformRuntime\EntitlementDocumentV1::parse($otherDocument);
            self::assertSame($otherCredential['installationId'], $parsedOther->installationId);
            self::assertSame(['cross-plane.tenant.other' => true], get_object_vars($parsedOther->features));
            try {
                (new \App\PlatformRuntime\PostgresEntitlementApplier($core, $verifier))
                    ->apply($installationId, $otherDocument, $otherSignature, (string) $otherEnvelope['keyId']);
                self::fail('Another tenant entitlement document was accepted by this installation.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
            self::assertSame(['cross-plane.plan.new' => true], $runtimeFeatures->forInstallation($installationId));
            $target = $change['target_subscription_id'];
            $freeFeature = $this->feature('cross-plane.plan.free', 'boolean');
            [$freePlan, $freePrice] = $this->planPrice('cross-plane-plan-free', currency: 'RUB', status: 'draft',
                featureValues: [$freeFeature => true]);
            $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor = 0 WHERE id = ?', [$freePrice]);
            $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$freePlan]);
            $freeChange = (new RequestSubscriptionPlanChange($this->connection,
                new AccountAuthorization($this->connection), new ProrationCalculator(),
                new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator())))
                ->request($owner, $account, $target, $freePrice, 1, 'cross-plane-free-downgrade', $this->uuid());
            self::assertSame('scheduled', $freeChange['status']);
            self::assertSame(3, $sync->sync());
            self::assertSame(['cross-plane.plan.new' => true], $runtimeFeatures->forInstallation($installationId));
            self::assertSame('cancelled', (new CancelSubscriptionPlanChange($this->connection,
                new AccountAuthorization($this->connection)))->cancel($owner, $account, $freeChange['id'],
                'cross-plane-free-downgrade-cancel', $this->uuid())['status']);
            self::assertSame(3, $sync->sync());
            self::assertSame(['cross-plane.plan.new' => true], $runtimeFeatures->forInstallation($installationId));
            $targetEnd = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT current_period_end FROM commercial.subscriptions WHERE id=?', [$target]));
            $lateFeature = $this->feature('cross-plane.plan.late', 'boolean');
            [$latePlan, $latePrice] = $this->planPrice('cross-plane-plan-late', currency: 'RUB', status: 'draft',
                featureValues: [$lateFeature => true]);
            $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor=3000 WHERE id=?', [$latePrice]);
            $this->connection->executeStatement("UPDATE commercial.plans SET status='published', published_at=now() WHERE id=?", [$latePlan]);
            $lateChange = (new RequestSubscriptionPlanChange($this->connection,
                new AccountAuthorization($this->connection), new ProrationCalculator(),
                new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator())))
                ->request($owner, $account, $target, $latePrice, 1, 'cross-plane-late-upgrade', $this->uuid());
            $lateInvoiceId = $lateChange['invoice_id'];
            self::assertIsString($lateInvoiceId);
            $latePayment = $this->paymentCreator()->create($owner, $account, $lateInvoiceId,
                'cross-plane-late-payment', $this->uuid());
            $lateAttempt = $this->attemptBeginner()->begin($latePayment['id'],
                $this->yooKassaProviderAccount('cross-plane-late'), 'sbp', $this->uuid());
            $this->attemptBinder()->bind($lateAttempt['attempt_id'], 'cross-plane-late-provider');
            $this->attemptCompleter()->complete($lateAttempt['attempt_id'], 'succeeded', null, $targetEnd, $this->uuid());
            self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$lateChange['id']]));
            self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$lateChange['target_subscription_id']]));
            self::assertSame(3, $sync->sync());
            self::assertSame(['cross-plane.plan.new' => true], $runtimeFeatures->forInstallation($installationId));
            $lateRefund = $this->connection->fetchAssociative('SELECT id, amount_minor FROM commercial.refunds WHERE payment_id=?', [$latePayment['id']]);
            self::assertIsArray($lateRefund);
            self::assertIsString($lateRefund['id']);
            $refundGateway = new FakeRefundProviderGateway();
            $refundSubmission = $this->refundSubmission($refundGateway);
            self::assertFalse($refundSubmission->submit($lateRefund['id'], $this->uuid()));
            $providerRefundId = $this->connection->fetchOne('SELECT provider_refund_id FROM commercial.refunds WHERE id=?', [$lateRefund['id']]);
            self::assertIsString($providerRefundId);
            $pendingRefund = $refundGateway->refunds[$providerRefundId];
            self::assertInstanceOf(ProviderRefund::class, $pendingRefund);
            $refundGateway->refunds[$providerRefundId] = new ProviderRefund($pendingRefund->id,
                $pendingRefund->providerPaymentId, 'succeeded', $pendingRefund->amountMinor,
                $pendingRefund->currency, new \DateTimeImmutable('now'), $pendingRefund->localRefundId);
            self::assertTrue($refundSubmission->submit($lateRefund['id'], $this->uuid()));
            self::assertSame(1, (new FinalizeRefundedPlanChanges($this->connection))->runBatch(100, $this->uuid()));
            self::assertSame(0, (new FinalizeRefundedPlanChanges($this->connection))->runBatch(100, $this->uuid()));
            self::assertSame('refunded', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$lateChange['id']]));
            self::assertSame(3, $sync->sync());
            self::assertSame(['cross-plane.plan.new' => true], $runtimeFeatures->forInstallation($installationId));
            self::assertSame(3, $sync->sync());
            self::assertSame(['cross-plane.plan.new' => true], $runtimeFeatures->forInstallation($installationId));
            self::assertSame('expired', (new ExpireSubscription($this->connection))->expire($account, $target, $targetEnd, $this->uuid())['status']);
            self::assertSame(4, $sync->sync());
            self::assertSame([], $runtimeFeatures->forInstallation($installationId));
            self::assertSame(4, $sync->sync());
            self::assertSame([], $runtimeFeatures->forInstallation($installationId));
            $canonical = base64_decode((string) $oldEnvelope['documentBase64'], true);
            $signature = base64_decode((string) $oldEnvelope['signatureBase64'], true);
            self::assertIsString($canonical);
            self::assertIsString($signature);
            try {
                (new \App\PlatformRuntime\PostgresEntitlementApplier($core, $verifier))
                    ->apply($installationId, $canonical, $signature, (string) $oldEnvelope['keyId']);
                self::fail('Older paid entitlement document was accepted after expiry.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
            self::assertSame([], $runtimeFeatures->forInstallation($installationId));
        } finally {
            putenv('CROSS_PLANE_CREDENTIAL');
            $coreTransaction->rollBack();
        }
    }
}
