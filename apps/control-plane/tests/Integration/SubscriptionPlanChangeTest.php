<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\PlanChangeInvoiceIssuer;
use ControlPlane\Billing\Domain\ProrationCalculator;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Payment\Application\PaymentCreator;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Refund\Application\ReconcileYooKassaRefunds;
use ControlPlane\Subscription\Application\ApplySubscriptionPlanChange;
use ControlPlane\Subscription\Application\CancelSubscriptionPlanChange;
use ControlPlane\Subscription\Application\FinalizeRefundedPlanChanges;
use ControlPlane\Subscription\Application\ProcessScheduledPlanChanges;
use ControlPlane\Subscription\Application\RequestSubscriptionPlanChange;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use ControlPlane\Subscription\Application\CancelSubscription;
use ControlPlane\Tests\Support\FakeRefundProviderGateway;

final class SubscriptionPlanChangeTest extends RefundTestCase
{
    public function testPaidUpgradeSwitchesEntitlementsExactlyOnceAndPreservesInvoiceSnapshot(): void
    {
        [$owner, $account, $source, $oldPrice, $newPrice, $oldFeature, $newFeature] = $this->context('upgrade', 1000, 2000);
        $requests = $this->requests();
        $change = $requests->request($owner, $account, $source, $newPrice, 1, 'upgrade-key', $this->uuid());
        self::assertSame('payment_required', $change['status']);
        self::assertSame('upgrade', $change['change_kind']);
        self::assertGreaterThan(0, $change['amount_due_minor']);
        self::assertSame($change, $requests->request($owner, $account, $source, $newPrice, 1, 'upgrade-key', $this->uuid()));
        self::assertSame(['plan-change-upgrade.old' => true], $this->features($owner, $account));
        self::assertSame('active', $this->subscriptionStatus($source));
        self::assertSame('draft', $this->subscriptionStatus($change['target_subscription_id']));
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        self::assertSame($change['amount_due_minor'], (int) $this->connection->fetchOne('SELECT total_minor FROM commercial.invoices WHERE id = ?', [$invoiceId]));
        self::assertSame($newPrice, $this->connection->fetchOne('SELECT plan_price_id FROM commercial.invoice_lines WHERE invoice_id = ?', [$invoiceId]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscription_plan_changes WHERE source_subscription_id = ?', [$source]));

        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'upgrade-payment', $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'], $this->providerAccount('plan-change-upgrade'), 'bank_transfer', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'plan-change-upgrade-provider');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());

        self::assertSame('cancelled', $this->subscriptionStatus($source));
        self::assertSame('active', $this->subscriptionStatus($change['target_subscription_id']));
        self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoiceId]));
        self::assertSame(['plan-change-upgrade.new' => true], $this->features($owner, $account));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.plan_changed' AND resource_id = ?", [$change['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.activated' AND aggregate_id = ?", [$change['target_subscription_id']]));
        self::assertSame($oldPrice, $this->connection->fetchOne('SELECT plan_price_id FROM commercial.subscription_items WHERE subscription_id = ?', [$source]));
        self::assertSame($oldFeature, $this->connection->fetchOne('SELECT feature_id FROM commercial.plan_features WHERE plan_id = (SELECT plan_id FROM commercial.plan_prices WHERE id = ?)', [$oldPrice]));
        self::assertSame($newFeature, $this->connection->fetchOne('SELECT feature_id FROM commercial.plan_features WHERE plan_id = (SELECT plan_id FROM commercial.plan_prices WHERE id = ?)', [$newPrice]));
    }

    public function testFreeInitialPurchaseCanUpgradeToPaidOnlyAfterSettlement(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('plan-change-free-to-paid');
        $freeFeature = $this->feature('plan-change-free-to-paid.free', 'boolean');
        $paidFeature = $this->feature('plan-change-free-to-paid.paid', 'boolean');
        $freePrice = $this->pricedPlan('plan-change-free-to-paid-free', 0, $freeFeature, 'USD');
        $paidPrice = $this->pricedPlan('plan-change-free-to-paid-paid', 1000, $paidFeature, 'USD');
        $source = $this->draftSubscription($account, $profile, [$freePrice]);
        $freeInvoice = $this->issuer()->issue($owner, $account, $source, 'free-invoice', $this->uuid());
        $this->paymentCreator()->create($owner, $account, $freeInvoice['id'], 'free-payment', $this->uuid());
        self::assertSame('active', $this->subscriptionStatus($source));
        self::assertSame(['plan-change-free-to-paid.free' => true], $this->features($owner, $account));
        $change = $this->requests()->request($owner, $account, $source, $paidPrice, 1, 'free-to-paid', $this->uuid());
        self::assertSame('payment_required', $change['status']);
        self::assertSame(['plan-change-free-to-paid.free' => true], $this->features($owner, $account));
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'free-to-paid-payment', $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'], $this->providerAccount('plan-change-free-to-paid'), 'bank_transfer', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'plan-change-free-to-paid-provider');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());
        self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        self::assertSame(['plan-change-free-to-paid.paid' => true], $this->features($owner, $account));
    }

    public function testConcurrentSameKeyRequestsCreateOneChangeAndInvoice(): void
    {
        [$owner, $account, $source, , $newPrice] = $this->context('race-' . bin2hex(random_bytes(4)), 1000, 2000);
        $this->connection->commit();
        $start = (string) (microtime(true) + 0.5);
        $extra = implode('|', [$owner, $account, $newPrice, 'same-race-key', $this->uuid()]);
        $workers = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__ . '/race-worker.php',
                    'plan_change_request', $source, $extra, $start],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($process)) {
                    throw new \RuntimeException('Could not launch plan change race worker.');
                }
                $workers[] = [$process, $pipes];
            }
            $ids = [];
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), (string) $stderr);
                $ids[] = $stdout;
            }
            $workers = [];
            self::assertSame($ids[0], $ids[1]);
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscription_plan_changes WHERE source_subscription_id = ?', [$source]));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoices WHERE subscription_id = (SELECT target_subscription_id FROM commercial.subscription_plan_changes WHERE id = ?)', [$ids[0]]));
            (new CancelSubscriptionPlanChange($this->connection, new AccountAuthorization($this->connection)))
                ->cancel($owner, $account, (string) $ids[0], 'cleanup-race-change-' . $source, $this->uuid());
            (new CancelSubscription($this->connection, new AccountAuthorization($this->connection)))
                ->cancel($owner, $account, $source, 'cleanup-race-source-' . $source, $this->uuid());
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }

    public function testFailedUpgradeAndConflictingReplayKeepOldEntitlements(): void
    {
        [$owner, $account, $source, , $newPrice] = $this->context('failed', 1000, 2000);
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'failed-upgrade-key', $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'failed-upgrade-payment', $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'], $this->providerAccount('plan-change-failed'), 'bank_transfer', $this->uuid());
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'failed', 'card_declined', new \DateTimeImmutable('now'), $this->uuid());
        self::assertSame('active', $this->subscriptionStatus($source));
        self::assertSame('draft', $this->subscriptionStatus($change['target_subscription_id']));
        self::assertSame(['plan-change-failed.old' => true], $this->features($owner, $account));
        self::assertSame('issued', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoiceId]));
        try {
            $this->requests()->request($owner, $account, $source, $newPrice, 2, 'failed-upgrade-key', $this->uuid());
            self::fail('Changed idempotent payload was accepted.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscription_plan_changes WHERE source_subscription_id = ?', [$source]));
    }

    public function testScheduledFreeDowngradeKeepsRightsUntilBoundaryAndRunsOnce(): void
    {
        [$owner, $account, $source, , $newPrice] = $this->context('free-downgrade', 1000, 0);
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'downgrade-key', $this->uuid());
        self::assertSame('scheduled', $change['status']);
        self::assertSame('downgrade', $change['change_kind']);
        self::assertNull($change['invoice_id']);
        self::assertSame(['plan-change-free-downgrade.old' => true], $this->features($owner, $account));
        $due = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        $worker = $this->worker();
        self::assertSame(0, $worker->runBatch($due->modify('-1 second'), 10, $this->uuid()));
        self::assertSame(1, $worker->runBatch($due, 10, $this->uuid()));
        self::assertSame(0, $worker->runBatch($due, 10, $this->uuid()));
        self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        self::assertSame('active', $this->subscriptionStatus($change['target_subscription_id']));
        self::assertSame('cancelled', $this->subscriptionStatus($source));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.plan_changed' AND resource_id = ?", [$change['id']]));
    }

    public function testScheduledDowngradeCanBeCancelledAndRescheduledWithoutInvoice(): void
    {
        [$owner, $account, $source, , $newPrice] = $this->context('cancel-downgrade', 1000, 500);
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'downgrade-first', $this->uuid());
        $canceller = new CancelSubscriptionPlanChange($this->connection, new AccountAuthorization($this->connection));
        self::assertSame(['id' => $change['id'], 'status' => 'cancelled'], $canceller->cancel($owner, $account, $change['id'], 'cancel-downgrade', $this->uuid()));
        self::assertSame('cancelled', $this->subscriptionStatus($change['target_subscription_id']));
        self::assertSame('active', $this->subscriptionStatus($source));
        $again = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'downgrade-second', $this->uuid());
        self::assertNotSame($change['id'], $again['id']);
        self::assertSame('scheduled', $again['status']);
    }

    public function testPaidDowngradeIssuesNextPeriodInvoiceAndAppliesOnlyAfterSettlement(): void
    {
        [$owner, $account, $source, , $newPrice] = $this->context('paid-downgrade', 2000, 500);
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'paid-downgrade-key', $this->uuid());
        self::assertSame('scheduled', $change['status']);
        self::assertSame(['plan-change-paid-downgrade.old' => true], $this->features($owner, $account));
        $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        self::assertSame(1, $this->worker()->runBatch($boundary, 10, $this->uuid()));
        self::assertSame(0, $this->worker()->runBatch($boundary, 10, $this->uuid()));
        $invoiceId = $this->connection->fetchOne('SELECT invoice_id FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]);
        self::assertIsString($invoiceId);
        self::assertSame(500, (int) $this->connection->fetchOne('SELECT total_minor FROM commercial.invoices WHERE id = ?', [$invoiceId]));
        self::assertSame('active', $this->subscriptionStatus($source));
        self::assertSame('draft', $this->subscriptionStatus($change['target_subscription_id']));
        $paymentId = $this->connection->fetchOne('SELECT id FROM commercial.payments WHERE invoice_id = ?', [$invoiceId]);
        self::assertIsString($paymentId);
        $attempt = $this->attemptBeginner()->begin($paymentId, $this->providerAccount('plan-change-paid-downgrade'), 'bank_transfer', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'plan-change-downgrade-provider');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $boundary, $this->uuid());
        self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        self::assertSame('cancelled', $this->subscriptionStatus($source));
        self::assertSame('active', $this->subscriptionStatus($change['target_subscription_id']));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoiceId]));
    }

    public function testLatePaidUpgradeDoesNotGrantAccessAndRequiresRefund(): void
    {
        [$owner, $account, $source, , $newPrice] = $this->context('late-upgrade', 1000, 2000);
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'late-upgrade-key', $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'late-upgrade-payment', $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'], $this->providerAccount('plan-change-late-upgrade'), 'bank_transfer', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'plan-change-late-provider');
        $afterPeriod = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $afterPeriod, $this->uuid());
        self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        self::assertSame('cancelled', $this->subscriptionStatus($change['target_subscription_id']));
        self::assertSame(['plan-change-late-upgrade.old' => true], $this->features($owner, $account));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.plan_change_refund_required' AND aggregate_id = ?", [$change['id']]));
    }

    public function testLateYooKassaUpgradeReservesOneFullRefund(): void
    {
        [$owner, $account, $source, , $newPrice] = $this->context('late-yookassa', 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'late-yookassa-key', $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'late-yookassa-payment', $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'], $this->yooKassaProviderAccount('plan-change-late'), 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'plan-change-late-yookassa-provider');
        $afterPeriod = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $afterPeriod, $this->uuid());
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $afterPeriod, $this->uuid());
        self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.refunds WHERE payment_id = ?', [$payment['id']]));
        $refund = $this->connection->fetchAssociative('SELECT id, amount_minor, status FROM commercial.refunds WHERE payment_id = ?', [$payment['id']]);
        self::assertIsArray($refund);
        self::assertSame($change['amount_due_minor'], (int) $refund['amount_minor']);
        self::assertSame('pending', $refund['status']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.refund_items WHERE refund_id = ?', [$refund['id']]));
        $gateway = new FakeRefundProviderGateway();
        $gateway->createFailure = PaymentProviderException::uncertain('refund_timeout');
        $submission = $this->refundSubmission($gateway);
        try {
            $submission->submit($refund['id'], $this->uuid());
            self::fail('Uncertain provider response was not reported.');
        } catch (PaymentProviderException) {
            self::addToAssertionCount(1);
        }
        $result = (new ReconcileYooKassaRefunds($this->connection, $submission))->reconcile(100, $this->uuid());
        self::assertSame(1, $result['selected']);
        self::assertCount(2, $gateway->createCalls);
        self::assertSame($gateway->createCalls[0][1]->providerRequestKey, $gateway->createCalls[1][1]->providerRequestKey);
        $providerRefundId = $this->connection->fetchOne('SELECT provider_refund_id FROM commercial.refunds WHERE id = ?', [$refund['id']]);
        self::assertIsString($providerRefundId);
        $pending = $gateway->refunds[$providerRefundId];
        self::assertInstanceOf(ProviderRefund::class, $pending);
        $gateway->refunds[$providerRefundId] = new ProviderRefund($pending->id, $pending->providerPaymentId,
            'succeeded', $pending->amountMinor, $pending->currency, new \DateTimeImmutable('now'), $pending->localRefundId);
        (new ReconcileYooKassaRefunds($this->connection, $submission))->reconcile(100, $this->uuid());
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.refunds WHERE id = ?', [$refund['id']]));
        $finalizer = new FinalizeRefundedPlanChanges($this->connection);
        self::assertSame(1, $finalizer->runBatch(100, $this->uuid()));
        self::assertSame(0, $finalizer->runBatch(100, $this->uuid()));
        self::assertSame('refunded', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        $replacement = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'retry-after-refund', $this->uuid());
        self::assertNotSame($change['id'], $replacement['id']);
    }

    /** @return array{string, string, string, string, string, string, string} */
    private function context(string $suffix, int $oldAmount, int $newAmount, string $currency = 'USD'): array
    {
        [$owner, $account, $profile] = $this->readyAccount('plan-change-' . $suffix);
        if ($currency === 'RUB') {
            $this->connection->executeStatement('UPDATE commercial.billing_profiles SET contact_name = ?, contact_email = ? WHERE id = ?',
                ['Billing Contact', 'billing-' . $suffix . '@example.test', $profile]);
        }
        $oldFeature = $this->feature('plan-change-' . $suffix . '.old', 'boolean');
        $newFeature = $this->feature('plan-change-' . $suffix . '.new', 'boolean');
        $oldPrice = $this->pricedPlan('plan-change-' . $suffix . '-old', $oldAmount, $oldFeature, $currency);
        $newPrice = $this->pricedPlan('plan-change-' . $suffix . '-new', $newAmount, $newFeature, $currency);
        $source = $this->draftSubscription($account, $profile, [$oldPrice]);
        $this->activatePaid($account, $source, $this->activationInstant(), $this->uuid());
        return [$owner, $account, $source, $oldPrice, $newPrice, $oldFeature, $newFeature];
    }

    private function pricedPlan(string $code, int $amount, string $feature, string $currency): string
    {
        $plan = $this->connection->fetchOne('INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, ?) RETURNING id', [$code, $code]);
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, ?, ?, 'month') RETURNING id", [$plan, $currency, $amount]);
        self::assertIsString($price);
        $this->connection->executeStatement("INSERT INTO commercial.plan_features (plan_id, feature_id, value) VALUES (?, ?, 'true'::jsonb)", [$plan, $feature]);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        return $price;
    }

    private function requests(): RequestSubscriptionPlanChange
    {
        return new RequestSubscriptionPlanChange($this->connection, new AccountAuthorization($this->connection),
            new ProrationCalculator(), new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator()));
    }

    private function worker(): ProcessScheduledPlanChanges
    {
        return new ProcessScheduledPlanChanges($this->connection, new PlanChangeInvoiceIssuer(),
            new PaymentCreator($this->settlement()), new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator()));
    }

    /** @return array<string, mixed>|\stdClass */
    private function features(string $owner, string $account): array|\stdClass
    {
        return (new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection)))
            ->forAccount($owner, $account)['features'];
    }

    private function subscriptionStatus(string $subscription): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]);
    }

}
