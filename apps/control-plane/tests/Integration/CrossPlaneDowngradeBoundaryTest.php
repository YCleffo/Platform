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
use ControlPlane\Billing\Application\IssueRenewalInvoice;
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
use ControlPlane\Subscription\Application\ExpireSubscription;
use ControlPlane\Subscription\Application\DisableSubscriptionAutoRenewal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

#[Group('cross-plane')]
final class CrossPlaneDowngradeBoundaryTest extends RefundTestCase
{
    /** @return array<string, array{string}> */
    public static function downgradeCases(): array
    {
        return [
            'paid-downgrade' => ['paid-downgrade'],
            'free-downgrade' => ['free-downgrade'],
            'paid-renewal' => ['paid-renewal'],
            'unsettled-renewal' => ['unsettled-renewal'],
            'renewal-restart' => ['renewal-restart'],
            'auto-renew-disabled' => ['auto-renew-disabled'],
        ];
    }

    #[DataProvider('downgradeCases')]
    public function testLifecycleAtRealPeriodBoundary(string $case): void
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
            // A monthly period cannot end on the 31st if the preceding month
            // has only 30 days. Use a real annual period on those dates instead
            // of PHP's overflowing "-1 month" (which can sleep for a whole day).
            $boundary = $this->activationInstant()
                ->modify($case === 'renewal-restart' ? '+8 seconds' : '+5 seconds');
            $previousMonth = $boundary->modify('first day of previous month');
            $billingPeriod = (int) $boundary->format('j') <= (int) $previousMonth->format('t')
                ? 'month' : 'year';
            $activation = $billingPeriod === 'month'
                ? $boundary->setDate((int) $previousMonth->format('Y'),
                    (int) $previousMonth->format('n'), (int) $boundary->format('j'))
                : $boundary->setDate((int) $boundary->format('Y') - 1,
                    (int) $boundary->format('n'), (int) $boundary->format('j'));
            foreach ([$oldPrice, $paidPrice, $freePrice] as $priceId) {
                $this->connection->executeStatement(
                    'UPDATE commercial.plan_prices SET billing_period=? WHERE id=?', [$billingPeriod, $priceId]);
            }
            $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor=2000 WHERE id=?', [$oldPrice]);
            $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor=500 WHERE id=?', [$paidPrice]);
            $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor=0 WHERE id=?', [$freePrice]);
            foreach ([$oldPlan, $paidPlan, $freePlan] as $planId) {
                $this->connection->executeStatement("UPDATE commercial.plans SET status='published',
                    published_at=now() WHERE id=?", [$planId]);
            }
            $source = $this->draftSubscription($account, $profile, [$oldPrice]);
            // A genuine short remaining term avoids mutating immutable usage history.
            $this->activatePaid($account, $source, $activation, $this->uuid());
            $this->applyDocument($issuer, $deployment, $installationId, $applier);
            self::assertSame(['boundary.' . $suffix . '.old' => true], $runtime->forInstallation($installationId));

            if ($case === 'auto-renew-disabled') {
                $result = (new DisableSubscriptionAutoRenewal($this->connection,
                    new AccountAuthorization($this->connection)))->disable($owner, $account,
                    $source, 'boundary-auto-renew-' . $suffix, $this->uuid());
                self::assertSame($source, $result['id']);
                self::assertFalse(in_array($this->connection->fetchOne(
                    'SELECT auto_renew_enabled FROM commercial.subscriptions WHERE id=?', [$source]),
                    [true, 't', '1', 1], true));
                self::assertSame(1, $this->applyDocument($issuer, $deployment, $installationId, $applier));
                self::assertSame(['boundary.' . $suffix . '.old' => true],
                    $runtime->forInstallation($installationId));
                $this->crossBoundary($source);
                self::assertGreaterThanOrEqual(1, (new ExpireSubscription($this->connection, null, 900))
                    ->expireDue(new \DateTimeImmutable('now'), 100, $this->uuid()));
                self::assertSame('expired', $this->connection->fetchOne(
                    'SELECT status FROM commercial.subscriptions WHERE id=?', [$source]));
                self::assertSame(0, (int) $this->connection->fetchOne(
                    "SELECT count(*) FROM commercial.invoices WHERE subscription_id=? AND billing_reason='subscription_renewal'", [$source]));
                self::assertGreaterThan(1, $this->applyDocument($issuer, $deployment,
                    $installationId, $applier));
                self::assertSame([], $runtime->forInstallation($installationId));
                return;
            }

            if (str_ends_with($case, 'renewal')) {
                $renewal = (new IssueRenewalInvoice($this->connection,
                    new SubscriptionPeriodCalculator()))->issue($account, $source, $this->uuid());
                self::assertSame('issued', $renewal['status']);
                $payment = $this->paymentCreator()->create($owner, $account, $renewal['id'],
                    'boundary-renewal-' . $suffix, $this->uuid());
                $attempt = null;
                if ($case === 'paid-renewal' || $case === 'renewal-restart') {
                    $attempt = $this->attemptBeginner()->begin($payment['id'],
                        $this->providerAccount('boundary-renewal-' . $suffix), 'bank_transfer', $this->uuid());
                    $this->attemptBinder()->bind($attempt['attempt_id'], 'boundary-renewal-provider-' . $suffix);
                }
                $oldEnd = new \DateTimeImmutable((string) $this->connection->fetchOne(
                    'SELECT current_period_end FROM commercial.subscriptions WHERE id=?', [$source]));
                if ($case === 'renewal-restart') {
                    self::assertIsArray($attempt);
                    $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null,
                        new \DateTimeImmutable('now'), $this->uuid());
                    self::assertSame($oldEnd->format('Y-m-d H:i:s.uP'),
                        (new \DateTimeImmutable((string) $this->connection->fetchOne(
                            'SELECT current_period_end FROM commercial.subscriptions WHERE id=?', [$source])))
                            ->format('Y-m-d H:i:s.uP'));
                    $this->installAdvancePause($suffix, $source);
                }
                $this->crossBoundary($source);
                if ($case === 'renewal-restart') {
                    $this->assertSchedulerRestart($suffix, $source, $oldEnd);
                    self::assertSame(1, (int) $this->connection->fetchOne(
                        "SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.renewed' AND aggregate_id=?", [$source]));
                    self::assertSame(1, (int) $this->connection->fetchOne(
                        "SELECT count(*) FROM commercial.audit_events WHERE action='subscription.renewed' AND resource_id=?", [$source]));
                    self::assertSame(2, (int) $this->connection->fetchOne(
                        'SELECT count(*) FROM commercial.usage_periods WHERE subscription_id=?', [$source]));
                    self::assertSame('paid', $this->connection->fetchOne(
                        'SELECT status FROM commercial.invoices WHERE id=?', [$renewal['id']]));
                    $sequence = $this->applyDocument($issuer, $deployment,
                        $installationId, $applier);
                    self::assertGreaterThan(1, $sequence);
                    self::assertSame($sequence, $this->applyDocument($issuer, $deployment,
                        $installationId, $applier));
                    self::assertSame(2, (int) $this->connection->fetchOne(
                        "SELECT count(*) FROM commercial.outbox_events WHERE event_type='entitlement.issued' AND aggregate_id=?", [$deployment]));
                    self::assertSame(['boundary.' . $suffix . '.old' => true],
                        $runtime->forInstallation($installationId));
                    return;
                }
                $expirer = new ExpireSubscription($this->connection, null, 900);
                if ($case === 'paid-renewal') {
                    self::assertIsArray($attempt);
                    $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null,
                        new \DateTimeImmutable('now'), $this->uuid());
                    try {
                        $expirer->expire($account, $source, new \DateTimeImmutable('now'), $this->uuid());
                        self::fail('Renewed subscription expired at its old boundary.');
                    } catch (\DomainException) {
                        self::addToAssertionCount(1);
                    }
                    self::assertSame('active', $this->connection->fetchOne(
                        'SELECT status FROM commercial.subscriptions WHERE id=?', [$source]));
                    self::assertSame('paid', $this->connection->fetchOne(
                        'SELECT status FROM commercial.invoices WHERE id=?', [$renewal['id']]));
                    self::assertSame('succeeded', $this->connection->fetchOne(
                        'SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
                    self::assertSame(2, (int) $this->connection->fetchOne(
                        'SELECT count(*) FROM commercial.usage_periods WHERE subscription_id=?', [$source]));
                    self::assertSame(1, (int) $this->connection->fetchOne(
                        "SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.renewed' AND aggregate_id=?", [$source]));
                    self::assertSame(1, (int) $this->connection->fetchOne(
                        "SELECT count(*) FROM commercial.audit_events WHERE action='subscription.renewed' AND resource_id=?", [$source]));
                    self::assertSame(1, (int) $this->connection->fetchOne(
                        'SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$renewal['id']]));
                    $this->connection->commit();
                    $this->connection->beginTransaction();
                    self::assertSame(['boundary.' . $suffix . '.old' => true],
                        (new EffectiveEntitlements($this->connection,
                            new AccountAuthorization($this->connection)))->forDeploymentAccount($account)['features']);
                    self::assertGreaterThan(1, $this->applyDocument($issuer, $deployment,
                        $installationId, $applier));
                    self::assertSame(['boundary.' . $suffix . '.old' => true], $runtime->forInstallation($installationId));
                } else {
                    self::assertSame('pending', $this->connection->fetchOne(
                        'SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
                    self::assertSame('issued', $this->connection->fetchOne(
                        'SELECT status FROM commercial.invoices WHERE id=?', [$renewal['id']]));
                    try {
                        $expirer->expire($account, $source, new \DateTimeImmutable('now'), $this->uuid());
                        self::fail('Renewal settlement grace was ignored.');
                    } catch (\DomainException) {
                        self::addToAssertionCount(1);
                    }
                    $emptySequence = $this->applyDocument($issuer, $deployment,
                        $installationId, $applier);
                    self::assertGreaterThan(1, $emptySequence);
                    self::assertSame([], $runtime->forInstallation($installationId));
                    self::assertSame('expired', $expirer->expire($account, $source,
                        $oldEnd->modify('+901 seconds'), $this->uuid())['status']);
                    self::assertSame(1, (int) $this->connection->fetchOne(
                        "SELECT count(*) FROM commercial.audit_events WHERE action='subscription.expired' AND resource_id=?", [$source]));
                    self::assertSame(1, (int) $this->connection->fetchOne(
                        "SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.expired' AND aggregate_id=?", [$source]));
                    self::assertSame($emptySequence, $this->applyDocument($issuer, $deployment,
                        $installationId, $applier));
                    self::assertSame([], $runtime->forInstallation($installationId));
                }
                return;
            }

            $requests = new RequestSubscriptionPlanChange($this->connection,
                new AccountAuthorization($this->connection), new ProrationCalculator(),
                new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator()));
            $change = $requests->request($owner, $account, $source,
                $case === 'paid-downgrade' ? $paidPrice : $freePrice, 1,
                'boundary-change-' . $suffix, $this->uuid());
            self::assertSame('scheduled', $change['status']);
            $this->applyDocument($issuer, $deployment, $installationId, $applier);
            self::assertSame(['boundary.' . $suffix . '.old' => true], $runtime->forInstallation($installationId));
            $this->crossBoundary($source);
            $worker = new ProcessScheduledPlanChanges($this->connection, new PlanChangeInvoiceIssuer(),
                new PaymentCreator($this->settlement()), new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator()));
            self::assertGreaterThanOrEqual(1, $worker->runBatch(new \DateTimeImmutable('now'), 100, $this->uuid()));
            if ($case === 'paid-downgrade') {
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
            $feature = $case === 'paid-downgrade' ? 'paid' : 'free';
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
        self::assertLessThanOrEqual(15, $delay, 'Fixture period must end within seconds, not days.');
        if ($delay > 0) {
            sleep($delay);
        }
        $this->connection->beginTransaction();
    }

    private function installAdvancePause(string $suffix, string $subscriptionId): void
    {
        $name = 'x15_pause_' . $suffix;
        $this->connection->executeStatement("CREATE FUNCTION commercial.{$name}() RETURNS trigger
            LANGUAGE plpgsql AS \$pause\$ BEGIN
              IF NEW.id = '{$subscriptionId}'::uuid
                 AND NEW.current_period_start IS DISTINCT FROM OLD.current_period_start THEN
                PERFORM pg_sleep(30);
              END IF;
              RETURN NEW;
            END \$pause\$");
        $this->connection->executeStatement("CREATE TRIGGER {$name} BEFORE UPDATE
            ON commercial.subscriptions FOR EACH ROW EXECUTE FUNCTION commercial.{$name}()");
    }

    private function assertSchedulerRestart(string $suffix, string $subscriptionId,
        \DateTimeImmutable $oldEnd): void
    {
        $name = 'x15_pause_' . $suffix;
        [$first, $firstPipes] = $this->startAdvanceScheduler();
        try {
            $deadline = microtime(true) + 10;
            $sleeping = 0;
            do {
                $sleeping = (int) $this->connection->fetchOne(<<<'SQL'
SELECT count(*) FROM pg_stat_activity
WHERE datname=current_database() AND wait_event='PgSleep'
  AND query LIKE 'UPDATE commercial.subscriptions SET current_period_start%'
SQL);
                if ($sleeping > 0) break;
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertGreaterThan(0, $sleeping, 'Scheduler did not reach the pre-commit pause.');
            proc_terminate($first, 9);
            $signalDeadline = microtime(true) + 3;
            do {
                $status = proc_get_status($first);
                if (!$status['running']) break;
                usleep(10_000);
            } while (microtime(true) < $signalDeadline);
            self::assertFalse($status['running'], 'Scheduler did not terminate.');
            self::assertTrue($status['signaled'], 'Scheduler exit was not a signal.');
            self::assertSame(9, $status['termsig']);
            $this->closeScheduler($first, $firstPipes);
            $first = null;
            self::assertSame($oldEnd->format('Y-m-d H:i:s.uP'),
                (new \DateTimeImmutable((string) $this->connection->fetchOne(
                    'SELECT current_period_end FROM commercial.subscriptions WHERE id=?', [$subscriptionId])))
                    ->format('Y-m-d H:i:s.uP'));
            self::assertSame(0, (int) $this->connection->fetchOne(
                "SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.renewed' AND aggregate_id=?", [$subscriptionId]));
        } finally {
            if (is_resource($first)) {
                proc_terminate($first, 9);
                $this->closeScheduler($first, $firstPipes);
            }
            $this->connection->executeStatement("DROP TRIGGER IF EXISTS {$name} ON commercial.subscriptions");
            $this->connection->executeStatement("DROP FUNCTION IF EXISTS commercial.{$name}()");
            $this->connection->commit();
            $this->connection->beginTransaction();
        }

        [$second, $secondPipes] = $this->startAdvanceScheduler();
        try {
            $deadline = microtime(true) + 10;
            $advanced = false;
            do {
                $advanced = (bool) $this->connection->fetchOne(
                    'SELECT current_period_end > ?::timestamptz FROM commercial.subscriptions WHERE id=?',
                    [$oldEnd->format('Y-m-d H:i:s.uP'), $subscriptionId]);
                if ($advanced) break;
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertTrue($advanced, 'Fresh scheduler did not advance the durable paid renewal.');
        } finally {
            proc_terminate($second, 9);
            $this->closeScheduler($second, $secondPipes);
        }
    }

    /** @return array{resource, array<int, resource>} */
    private function startAdvanceScheduler(): array
    {
        $environment = getenv();
        foreach (['CONTROL_PLAN_CHANGES', 'CONTROL_RENEWALS', 'CONTROL_RENEWAL_COLLECTION',
            'CONTROL_DUNNING', 'CONTROL_EXPIRATION', 'CONTROL_PAYMENT_RECONCILIATION',
            'CONTROL_REFUND_RECONCILIATION', 'CONTROL_PLAN_CHANGE_REFUND_FINALIZATION',
            'CONTROL_PLAN_CHANGE_RECOVERY', 'CONTROL_RECEIPT_RECONCILIATION',
            'CONTROL_PRIVACY_CLEANUP'] as $job) {
            $environment[$job . '_ENABLED'] = '0';
        }
        $environment['CONTROL_SCHEDULER_ENABLED'] = '1';
        $environment['CONTROL_SUBSCRIPTIONS_ENABLED'] = '1';
        $environment['CONTROL_SUBSCRIPTIONS_INTERVAL_SECONDS'] = '1';
        $pipes = [];
        $process = proc_open([PHP_BINARY, 'bin/console', 'control:scheduler:run'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the Control scheduler entrypoint.');
        }
        return [$process, $pipes];
    }

    /** @param resource $process
     *  @param array<int, resource> $pipes
     */
    private function closeScheduler($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }
        proc_close($process);
    }

    private function applyDocument(DeploymentEntitlementIssuer $issuer, string $deploymentId,
        string $installationId, PostgresEntitlementApplier $applier): int
    {
        $envelope = $issuer->ensureLatest($deploymentId);
        $document = base64_decode($envelope['documentBase64'], true);
        $signature = base64_decode($envelope['signatureBase64'], true);
        self::assertIsString($document);
        self::assertIsString($signature);
        $applier->apply($installationId, $document, $signature, $envelope['keyId']);
        return $envelope['sequence'];
    }
}
