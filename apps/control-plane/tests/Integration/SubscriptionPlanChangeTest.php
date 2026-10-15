<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\PlanChangeInvoiceIssuer;
use ControlPlane\Billing\Application\IssueRenewalInvoice;
use ControlPlane\Billing\Domain\ProrationCalculator;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Fiscalization\Application\ApplyAuthoritativeReceipt;
use ControlPlane\Fiscalization\Application\ReceiptSnapshot;
use ControlPlane\Payment\Application\PaymentCreator;
use ControlPlane\Payment\Application\ReceiveYooKassaWebhook;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Payment\Provider\ProviderReceipt;
use ControlPlane\Payment\Provider\YooKassa\YooKassaProviderAccountLocator;
use ControlPlane\Refund\Application\ReconcileYooKassaRefunds;
use ControlPlane\Subscription\Application\ApplySubscriptionPlanChange;
use ControlPlane\Subscription\Application\CancelSubscriptionPlanChange;
use ControlPlane\Subscription\Application\FinalizeRefundedPlanChanges;
use ControlPlane\Subscription\Application\GetSubscriptionPlanChange;
use ControlPlane\Subscription\Application\MarkFailedPlanChangeCompensations;
use ControlPlane\Subscription\Application\ProcessScheduledPlanChanges;
use ControlPlane\Subscription\Application\RequestSubscriptionPlanChange;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use ControlPlane\Subscription\Application\CancelSubscription;
use ControlPlane\Subscription\Application\ExpireSubscription;
use ControlPlane\Subscription\Application\ExternalPlanChangeCompensation;
use ControlPlane\Tests\Support\FakeRefundProviderGateway;
use ControlPlane\Tests\Support\FakePaymentProviderGateway;

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
        $extra = implode('|', [$owner, $account, $newPrice, 'same-race-key-' . $source, $this->uuid()]);
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

    public function testSettlementRacesPlanChangeCancellationAndExpiryAcrossProcesses(): void
    {
        foreach (['plan_change_cancel', 'cancel_subscription', 'expire_subscription'] as $opponent) {
            for ($iteration = 0; $iteration < 2; ++$iteration) {
                $suffix = 'settle-' . bin2hex(random_bytes(5));
                [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000);
                $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'change-' . $suffix, $this->uuid());
                $invoiceId = $change['invoice_id'];
                self::assertIsString($invoiceId);
                $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'payment-' . $suffix, $this->uuid());
                $attempt = $this->attemptBeginner()->begin($payment['id'], $this->providerAccount($suffix), 'bank_transfer', $this->uuid());
                $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-' . $suffix);
                $boundary = (string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]);
                $settlementAt = $opponent === 'expire_subscription' ? $boundary : (new \DateTimeImmutable())->format(DATE_ATOM);
                $this->connection->commit();

                $otherExtra = $opponent === 'expire_subscription'
                    ? implode('|', [$account, $this->uuid(), $boundary])
                    : implode('|', [$owner, $account, 'cancel-' . $suffix, $this->uuid()]);
                $otherValue = $opponent === 'plan_change_cancel' ? $change['id'] : $source;
                $results = $this->planRace('complete_payment_attempt', $attempt['attempt_id'],
                    implode('|', ['succeeded', '', $settlementAt, $this->uuid()]), $opponent, $otherValue, $otherExtra, $source);
                self::assertSame(0, $results[0]['exit'], $results[0]['stderr']);
                self::assertContains($results[1]['exit'], [0, 2], $results[1]['stderr']);
                self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
                self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoiceId]));
                self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$payment['id']]));
                self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='payment.succeeded' AND aggregate_id=?", [$payment['id']]));
                $status = $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]);
                self::assertContains($status, ['applied', 'paid_unapplied']);
                self::assertSame($status === 'applied' ? 'active' : 'cancelled', $this->subscriptionStatus($change['target_subscription_id']));
                self::assertSame($status === 'applied' ? 1 : 0,
                    (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.usage_periods WHERE subscription_id=? AND status='open'", [$change['target_subscription_id']]));
                self::assertEquals($status === 'applied' ? ['plan-change-' . $suffix . '.new' => true] : new \stdClass(),
                    $this->features($owner, $account));
                $this->connection->beginTransaction();
            }
        }
    }

    public function testAuthoritativeWebhookRacesPlanChangeCancellationAcrossProcesses(): void
    {
        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $suffix = 'webhook-cancel-' . bin2hex(random_bytes(5));
            [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
            $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
                'change-' . $suffix, $this->uuid());
            $invoiceId = $change['invoice_id'];
            self::assertIsString($invoiceId);
            $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
                'payment-' . $suffix, $this->uuid());
            $providerAccountId = $this->yooKassaProviderAccount($suffix);
            $attempt = $this->attemptBeginner()->begin($payment['id'], $providerAccountId, 'sbp', $this->uuid());
            $providerPaymentId = 'provider-' . $suffix;
            $this->attemptBinder()->bind($attempt['attempt_id'], $providerPaymentId);
            $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET receipt_mode='disabled', receipt_vat_code=NULL, receipt_payment_subject=NULL, receipt_payment_mode=NULL, updated_at=now() WHERE id=?", [$providerAccountId]);
            $this->connection->commit();
            try {
                $results = $this->planRace('webhook_renewal', $attempt['attempt_id'],
                    implode('|', [$this->uuid(), (new \DateTimeImmutable())->format(DATE_ATOM)]),
                    'plan_change_cancel', $change['id'],
                    implode('|', [$owner, $account, 'cancel-' . $suffix, $this->uuid()]), $source);
                self::assertSame(0, $results[0]['exit'], $results[0]['stderr']);
                self::assertSame(2, $results[1]['exit'], $results[1]['stderr']);
                self::assertSame('processed', $this->connection->fetchOne("SELECT status FROM commercial.payment_provider_events WHERE provider_account_id=? AND event_type='payment.succeeded' AND provider_object_id=?", [$providerAccountId, $providerPaymentId]));
                self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
                self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
                self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
                self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$payment['id']]));
                self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
                self::assertSame(['plan-change-' . $suffix . '.new' => true], $this->features($owner, $account));
            } finally {
                $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status='disabled', updated_at=now() WHERE id=?", [$providerAccountId]);
                $this->connection->beginTransaction();
            }
        }
    }

    public function testScheduledFreeDowngradeRacesExpiryAcrossProcesses(): void
    {
        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $suffix = 'downgrade-race-' . bin2hex(random_bytes(5));
            [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 0);
            $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'change-' . $suffix, $this->uuid());
            $boundary = (string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]);
            $this->connection->commit();
            $results = $this->planRace('process_scheduled_plan_changes', $change['id'], implode('|', [$boundary, $this->uuid()]),
                'expire_subscription', $source, implode('|', [$account, $this->uuid(), $boundary]), $source);
            self::assertSame(0, $results[0]['exit'], $results[0]['stderr']);
            self::assertContains($results[1]['exit'], [0, 2], $results[1]['stderr']);
            self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame('active', $this->subscriptionStatus($change['target_subscription_id']));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.subscriptions WHERE commercial_account_id=? AND status='active'", [$account]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.plan_changed' AND resource_id=?", [$change['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
            // Both workers used the future boundary; before that real clock instant,
            // the newly active target must not grant its next-period entitlement.
            self::assertEquals(new \stdClass(), $this->features($owner, $account));
            self::assertSame($newPrice, $this->connection->fetchOne('SELECT plan_price_id FROM commercial.subscription_items WHERE subscription_id=?', [$change['target_subscription_id']]));
            $this->connection->beginTransaction();
        }
    }

    public function testPaidDowngradeSettlementRacesExpiryAcrossProcesses(): void
    {
        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $suffix = 'paid-downgrade-race-' . bin2hex(random_bytes(5));
            [$owner, $account, $source, , $newPrice] = $this->context($suffix, 2000, 500);
            $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'change-' . $suffix, $this->uuid());
            $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame(1, $this->worker()->runBatch($boundary, 10, $this->uuid()));
            $invoiceId = $this->connection->fetchOne('SELECT invoice_id FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]);
            self::assertIsString($invoiceId);
            $paymentId = $this->connection->fetchOne('SELECT id FROM commercial.payments WHERE invoice_id=?', [$invoiceId]);
            self::assertIsString($paymentId);
            $attempt = $this->attemptBeginner()->begin($paymentId, $this->providerAccount($suffix), 'bank_transfer', $this->uuid());
            $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-' . $suffix);
            $this->connection->commit();
            $results = $this->planRace('complete_payment_attempt', $attempt['attempt_id'],
                implode('|', ['succeeded', '', $boundary->format('Y-m-d H:i:s.uP'), $this->uuid()]),
                'expire_subscription', $source, implode('|', [$account, $this->uuid(), $boundary->format('Y-m-d H:i:s.uP')]), $source);
            self::assertSame(0, $results[0]['exit'], $results[0]['stderr']);
            self::assertContains($results[1]['exit'], [0, 2], $results[1]['stderr']);
            self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
            self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$paymentId]));
            self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame('active', $this->subscriptionStatus($change['target_subscription_id']));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$paymentId]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
            self::assertEquals(new \stdClass(), $this->features($owner, $account));
            $this->connection->beginTransaction();
        }
    }

    public function testScheduledDowngradeRacesRenewalInvoiceWithoutOldPlanCharge(): void
    {
        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $suffix = 'renewal-race-' . bin2hex(random_bytes(5));
            [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 0);
            $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'change-' . $suffix, $this->uuid());
            $boundary = (string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]);
            $this->connection->commit();
            $results = $this->planRace('process_scheduled_plan_changes', $change['id'], implode('|', [$boundary, $this->uuid()]),
                'issue_renewal_invoice', $source, implode('|', [$account, $this->uuid()]), $source);
            self::assertSame(0, $results[0]['exit'], $results[0]['stderr']);
            self::assertSame(2, $results[1]['exit'], $results[1]['stderr']);
            self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices WHERE subscription_id=? AND billing_reason='subscription_renewal'", [$source]));
            self::assertSame('active', $this->subscriptionStatus($change['target_subscription_id']));
            $this->connection->beginTransaction();
        }
    }

    public function testScheduledDowngradeHasNoRenewalInvoiceForDunning(): void
    {
        $suffix = 'no-dunning-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 0);
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        try {
            (new IssueRenewalInvoice($this->connection, new SubscriptionPeriodCalculator()))
                ->issue($account, $source, $this->uuid());
            self::fail('An old-plan renewal invoice was issued despite the scheduled downgrade.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM commercial.invoices WHERE subscription_id=? AND billing_reason='subscription_renewal'", [$source]));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM commercial.renewal_collection_states WHERE subscription_id=?', [$source]));
        self::assertSame('scheduled', $this->connection->fetchOne(
            'SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
    }

    public function testDifferentConcurrentPlanChangeRequestsChooseOneReplacement(): void
    {
        $suffix = 'different-requests-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $firstPrice] = $this->context($suffix, 1000, 2000);
        $secondFeature = $this->feature('plan-change-' . $suffix . '.alternative', 'boolean');
        $secondPrice = $this->pricedPlan('plan-change-' . $suffix . '-alternative', 2500, $secondFeature, 'USD');
        $this->connection->commit();
        $results = $this->planRace('plan_change_request', $source,
            implode('|', [$owner, $account, $firstPrice, 'request-a-' . $suffix, $this->uuid()]),
            'plan_change_request', $source,
            implode('|', [$owner, $account, $secondPrice, 'request-b-' . $suffix, $this->uuid()]), $source);
        $exits = array_column($results, 'exit');
        sort($exits);
        self::assertSame([0, 2], $exits);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscription_plan_changes WHERE source_subscription_id=?', [$source]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.subscriptions WHERE commercial_account_id=? AND status='draft'", [$account]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices i JOIN commercial.subscription_plan_changes c ON c.invoice_id=i.id WHERE c.source_subscription_id=?", [$source]));
        self::assertSame(['plan-change-' . $suffix . '.old' => true], $this->features($owner, $account));
        $this->connection->beginTransaction();
    }

    public function testSameKeyPlanRequestsUnderObservedDatabaseContentionReplayOneResult(): void
    {
        $suffix = 'same-key-lock-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000);
        $this->connection->commit();
        $extra = implode('|', [$owner, $account, $newPrice, 'same-key-' . $suffix, $this->uuid()]);
        $results = $this->planRace('plan_change_request', $source, $extra,
            'plan_change_request', $source, $extra, $source);
        self::assertSame([0, 0], array_column($results, 'exit'), implode("\n", array_column($results, 'stderr')));
        self::assertSame($results[0]['stdout'], $results[1]['stdout']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscription_plan_changes WHERE source_subscription_id=?', [$source]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices i JOIN commercial.subscription_plan_changes c ON c.invoice_id=i.id WHERE c.source_subscription_id=?", [$source]));
        $this->connection->beginTransaction();
    }

    public function testHistoricalPlanChangeKeyCannotBeReusedByNewFinancialMutation(): void
    {
        $suffix = 'historic-key-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000);
        $key = 'historic-plan-key-' . $suffix;
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, $key, $this->uuid());
        $this->connection->executeStatement('DELETE FROM commercial.idempotency_keys WHERE idempotency_key=?', [$key]);
        $this->expectDatabaseRejection(function () use ($key): void {
            $this->connection->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status) VALUES ('payment.create.historic', ?, decode(repeat('00',32),'hex'), 'pending')", [$key]);
        });
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
    }

    public function testDatabaseRejectsGlobalIdempotencyKeyReuseAcrossScopes(): void
    {
        $key = 'global-key-' . bin2hex(random_bytes(12));
        $this->connection->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status) VALUES ('test.scope.one', ?, decode(repeat('01',32),'hex'), 'completed')", [$key]);
        $this->expectDatabaseRejection(function () use ($key): void {
            $this->connection->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status) VALUES ('test.scope.two', ?, decode(repeat('02',32),'hex'), 'pending')", [$key]);
        });
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.idempotency_keys WHERE idempotency_key=?', [$key]));
    }

    public function testConcurrentDifferentScopesChooseOneGlobalIdempotencyKeyOwner(): void
    {
        $key = 'global-race-' . bin2hex(random_bytes(12));
        $this->connection->commit();
        $results = $this->planRace('idempotency_scoped', $key, 'test.race.one',
            'idempotency_scoped', $key, 'test.race.two', $this->uuid(), $key);
        $exits = array_column($results, 'exit');
        sort($exits);
        self::assertSame([0, 2], $exits, implode("\n", array_column($results, 'stderr')));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.idempotency_keys WHERE idempotency_key=?', [$key]));
        $this->connection->beginTransaction();
    }

    public function testDuplicateConcurrentUpgradeSettlementHasOneFinancialAndAccessEffect(): void
    {
        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $suffix = 'duplicate-settlement-' . bin2hex(random_bytes(5));
            [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000);
            $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'change-' . $suffix, $this->uuid());
            $invoiceId = $change['invoice_id'];
            self::assertIsString($invoiceId);
            $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'payment-' . $suffix, $this->uuid());
            $attempt = $this->attemptBeginner()->begin($payment['id'], $this->providerAccount($suffix), 'bank_transfer', $this->uuid());
            $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-' . $suffix);
            $instant = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');
            $this->connection->commit();
            $results = $this->planRace('complete_payment_attempt', $attempt['attempt_id'],
                implode('|', ['succeeded', '', $instant, $this->uuid()]),
                'complete_payment_attempt', $attempt['attempt_id'],
                implode('|', ['succeeded', '', $instant, $this->uuid()]), $source);
            self::assertSame([0, 0], array_column($results, 'exit'), implode("\n", array_column($results, 'stderr')));
            self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$payment['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.plan_changed' AND resource_id=?", [$change['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.refunds WHERE payment_id=?', [$payment['id']]));
            self::assertSame(['plan-change-' . $suffix . '.new' => true], $this->features($owner, $account));
            $this->connection->beginTransaction();
        }
    }

    public function testAuthoritativePaymentAndReconcilerOverlapWithoutDuplicateUpgrade(): void
    {
        $suffix = 'reconcile-race-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
            'payment-' . $suffix, $this->uuid());
        $providerAccountId = $this->yooKassaProviderAccount($suffix);
        $attempt = $this->attemptBeginner()->begin($payment['id'],
            $providerAccountId, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-' . $suffix);
        // The race fixture is committed for independent workers. Keep its
        // provider selectable by the reconciler without leaving a second
        // globally active YooKassa account for unrelated tests.
        $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status='disabled', receipt_mode='disabled', receipt_vat_code=NULL, receipt_payment_subject=NULL, receipt_payment_mode=NULL, updated_at=now() WHERE id=?", [$providerAccountId]);
        $instant = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');
        $this->connection->commit();
        $results = $this->planRace('authoritative_plan', $attempt['attempt_id'],
            implode('|', [$this->uuid(), $instant]),
            'reconcile_renewal', $attempt['attempt_id'],
            implode('|', [$this->uuid(), $instant]), $source);
        self::assertSame([0, 0], array_column($results, 'exit'), implode("\n", array_column($results, 'stderr')));
        self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$payment['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.plan_changed' AND resource_id=?", [$change['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
        self::assertSame(['plan-change-' . $suffix . '.new' => true], $this->features($owner, $account));
        $this->connection->beginTransaction();
    }

    public function testWebhookAndReconcilerOverlapWithoutDuplicateUpgrade(): void
    {
        $suffix = 'webhook-reconcile-race-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
            'payment-' . $suffix, $this->uuid());
        $providerAccountId = $this->yooKassaProviderAccount($suffix);
        $attempt = $this->attemptBeginner()->begin($payment['id'], $providerAccountId, 'sbp', $this->uuid());
        $providerPaymentId = 'provider-' . $suffix;
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerPaymentId);
        $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET receipt_mode='disabled', receipt_vat_code=NULL, receipt_payment_subject=NULL, receipt_payment_mode=NULL, updated_at=now() WHERE id=?", [$providerAccountId]);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_provider_accounts WHERE provider_code='yookassa' AND status='active'"));
        $instant = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');
        $this->connection->commit();
        try {
            $results = $this->planRace('webhook_renewal', $attempt['attempt_id'],
                implode('|', [$this->uuid(), $instant]),
                'reconcile_renewal', $attempt['attempt_id'],
                implode('|', [$this->uuid(), $instant]), $source);
            self::assertSame([0, 0], array_column($results, 'exit'), implode("\n", array_column($results, 'stderr')));
            self::assertSame('processed', $this->connection->fetchOne("SELECT status FROM commercial.payment_provider_events WHERE provider_account_id=? AND event_type='payment.succeeded' AND provider_object_id=?", [$providerAccountId, $providerPaymentId]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_provider_events WHERE provider_account_id=? AND event_type='payment.succeeded' AND provider_object_id=?", [$providerAccountId, $providerPaymentId]));
            self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
            self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$payment['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.plan_changed' AND resource_id=?", [$change['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
            self::assertSame(['plan-change-' . $suffix . '.new' => true], $this->features($owner, $account));
        } finally {
            $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status='disabled', updated_at=now() WHERE id=?", [$providerAccountId]);
            $this->connection->beginTransaction();
        }
    }

    public function testRepeatedPlanChangeReconciliationSettlesOnce(): void
    {
        $suffix = 'plan-reconcile-replay-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
            'payment-' . $suffix, $this->uuid());
        $providerAccountId = $this->yooKassaProviderAccount($suffix);
        $attempt = $this->attemptBeginner()->begin($payment['id'], $providerAccountId, 'sbp', $this->uuid());
        $providerPaymentId = 'provider-' . $suffix;
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerPaymentId);
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerPaymentId] = new ProviderPayment($providerPaymentId,
            'succeeded', true, $change['amount_due_minor'], 'RUB',
            ['payment_id' => $payment['id'], 'attempt_id' => $attempt['attempt_id'],
                'invoice_id' => $invoiceId], null, new \DateTimeImmutable('now'));
        $reconciler = $this->yooKassaReconciliation($gateway);
        self::assertSame(['selected' => 1, 'completed' => 1, 'pending' => 0, 'failed' => 0],
            $reconciler->reconcile(100, $this->uuid()));
        self::assertSame(['selected' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0],
            $reconciler->reconcile(100, $this->uuid()));
        self::assertCount(1, $gateway->getCalls);
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
        self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$payment['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.plan_changed' AND resource_id=?", [$change['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
        self::assertSame(['plan-change-' . $suffix . '.new' => true], $this->features($owner, $account));
    }

    public function testReorderedAuthoritativeProviderStatesCannotRegressPaidUpgrade(): void
    {
        $suffix = 'provider-order-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000);
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'payment-' . $suffix, $this->uuid());
        $providerId = $this->providerAccount($suffix);
        $attempt = $this->attemptBeginner()->begin($payment['id'], $providerId, 'bank_transfer', $this->uuid());
        $providerReference = 'provider-' . $suffix;
        $config = new ProviderAccount($providerId, 'provider-' . $suffix, 'merchant-' . $suffix, 'payments/' . $suffix);
        $metadata = ['payment_id' => $payment['id'], 'attempt_id' => $attempt['attempt_id'], 'invoice_id' => $invoiceId];
        $amount = $change['amount_due_minor'];
        $instant = new \DateTimeImmutable('now');
        $processor = $this->authoritativeProcessor();
        $pending = new ProviderPayment($providerReference, 'pending', false, $amount, 'USD', $metadata, null, null);
        $success = new ProviderPayment($providerReference, 'succeeded', true, $amount, 'USD', $metadata, null, $instant);
        $oldCancellation = new ProviderPayment($providerReference, 'canceled', false, $amount, 'USD', $metadata, null, null);
        self::assertFalse($processor->process($config, $pending, $this->uuid())['changed']);
        self::assertTrue($processor->process($config, $success, $this->uuid())['changed']);
        self::assertFalse($processor->process($config, $success, $this->uuid())['changed']);
        self::assertFalse($processor->process($config, $pending, $this->uuid())['changed']);
        try {
            $processor->process($config, $oldCancellation, $this->uuid());
            self::fail('Older provider cancellation regressed the succeeded attempt.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$payment['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
        self::assertSame(['plan-change-' . $suffix . '.new' => true], $this->features($owner, $account));
    }

    public function testPlanChangeWebhookRetriesPendingClaimAndDeduplicatesConfirmedSettlement(): void
    {
        $suffix = 'plan-webhook-order-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
            'payment-' . $suffix, $this->uuid());
        $providerAccount = $this->yooKassaProviderAccount($suffix);
        $attempt = $this->attemptBeginner()->begin($payment['id'], $providerAccount, 'sbp', $this->uuid());
        $providerPaymentId = 'provider-' . $suffix;
        $metadata = ['payment_id' => $payment['id'], 'attempt_id' => $attempt['attempt_id'],
            'invoice_id' => $invoiceId];
        $amount = $change['amount_due_minor'];
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerPaymentId] = new ProviderPayment($providerPaymentId,
            'pending', false, $amount, 'RUB', $metadata, null, null);
        $receiver = new ReceiveYooKassaWebhook($this->connection,
            new YooKassaProviderAccountLocator($this->connection), $gateway, $this->authoritativeProcessor());
        $successNotice = json_encode(['type' => 'notification', 'event' => 'payment.succeeded',
            'object' => ['id' => $providerPaymentId]], JSON_THROW_ON_ERROR);
        try {
            $receiver->receive($successNotice, 'application/json', $this->uuid());
            self::fail('Pending authoritative state was treated as a successful payment.');
        } catch (PaymentProviderException $exception) {
            self::assertSame('provider_state_not_final', $exception->errorCode);
        }
        self::assertNull($this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id=?', [$attempt['attempt_id']]));
        self::assertSame('failed', $this->connection->fetchOne("SELECT status FROM commercial.payment_provider_events WHERE provider_account_id=? AND event_type='payment.succeeded' AND provider_object_id=?", [$providerAccount, $providerPaymentId]));
        self::assertSame('issued', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
        self::assertSame(['plan-change-' . $suffix . '.old' => true], $this->features($owner, $account));
        $gateway->payments[$providerPaymentId] = new ProviderPayment($providerPaymentId,
            'succeeded', true, $amount, 'RUB', $metadata, null, new \DateTimeImmutable('now'));
        self::assertSame('processed', $receiver->receive($successNotice, 'application/json', $this->uuid())['status']);
        $receiver->receive($successNotice, 'application/json', $this->uuid());
        self::assertSame($providerPaymentId, $this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id=?', [$attempt['attempt_id']]));
        self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
        self::assertSame(['plan-change-' . $suffix . '.new' => true], $this->features($owner, $account));
        $staleNotice = json_encode(['type' => 'notification', 'event' => 'payment.canceled',
            'object' => ['id' => $providerPaymentId]], JSON_THROW_ON_ERROR);
        self::assertSame('processed', $receiver->receive($staleNotice, 'application/json', $this->uuid())['status']);
        self::assertSame(3, count($gateway->getCalls));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_provider_events WHERE provider_account_id=? AND provider_object_id=?', [$providerAccount, $providerPaymentId]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$payment['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.plan_changed' AND resource_id=?", [$change['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
    }

    /** @return list<array{exit: int, stderr: string, stdout: string}> */
    private function planRace(string $firstMode, string $firstValue, string $firstExtra,
        string $secondMode, string $secondValue, string $secondExtra, string $sourceId,
        ?string $idempotencyKey = null, string $lockResource = 'subscription'): array
    {
        $barrier = sys_get_temp_dir() . '/plan-race-' . bin2hex(random_bytes(8));
        if (!mkdir($barrier, 0700)) {
            throw new \RuntimeException('Could not create plan race barrier.');
        }
        $workers = [];
        $holdingSource = false;
        try {
            $this->connection->beginTransaction();
            $holdingSource = true;
            if ($idempotencyKey === null) {
                $lockQuery = match ($lockResource) {
                    'subscription' => 'SELECT id FROM commercial.subscriptions WHERE id=? FOR UPDATE',
                    'refund' => 'SELECT id FROM commercial.refunds WHERE id=? FOR UPDATE',
                    default => throw new \InvalidArgumentException('Unsupported race lock resource.'),
                };
                $this->connection->executeQuery($lockQuery, [$sourceId]);
            } else {
                $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$idempotencyKey]);
            }
            foreach ([[$firstMode, $firstValue, $firstExtra], [$secondMode, $secondValue, $secondExtra]] as $lane => [$mode, $value, $extra]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__ . '/race-worker.php', $mode, $value, $extra,
                    'barrier|' . $barrier . '|' . $lane], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($process)) {
                    throw new \RuntimeException('Could not launch plan race worker.');
                }
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while ((!is_file($barrier . '/ready-0') || !is_file($barrier . '/ready-1')) && microtime(true) < $deadline) {
                usleep(5_000);
            }
            self::assertFileExists($barrier . '/ready-0');
            self::assertFileExists($barrier . '/ready-1');
            $pids = [(int) file_get_contents($barrier . '/ready-0'), (int) file_get_contents($barrier . '/ready-1')];
            self::assertGreaterThan(0, $pids[0]);
            self::assertGreaterThan(0, $pids[1]);
            touch($barrier . '/release-all');
            // The parent holds the source row until both independent DB sessions
            // are observably waiting on PostgreSQL locks.
            $lockDeadline = microtime(true) + 10;
            do {
                $waiting = (int) $this->connection->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE pid IN (?, ?) AND wait_event_type='Lock'", $pids);
                if ($waiting === 2) break;
                usleep(10_000);
            } while (microtime(true) < $lockDeadline);
            self::assertSame(2, $waiting, 'Both plan race workers must contend on database locks.');
            $this->connection->commit();
            $holdingSource = false;
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $results[] = ['exit' => proc_close($process), 'stderr' => $stderr, 'stdout' => $stdout];
            }
            $workers = [];
            return $results;
        } finally {
            if ($holdingSource && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) fclose($pipe);
                }
                proc_close($process);
            }
            foreach (['ready-0', 'ready-1', 'release-all'] as $file) {
                if (is_file($barrier . '/' . $file)) unlink($barrier . '/' . $file);
            }
            rmdir($barrier);
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

    public function testSourceCancellationRequiresOpenPlanChangeResolution(): void
    {
        foreach ([['scheduled', 1000, 0], ['upgrade_payment_required', 1000, 2000], ['downgrade_payment_required', 2000, 500]] as [$case, $oldAmount, $newAmount]) {
            $suffix = 'cancel-source-' . $case . '-' . bin2hex(random_bytes(4));
            [$owner, $account, $source, , $newPrice] = $this->context($suffix, $oldAmount, $newAmount);
            $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'change-' . $suffix, $this->uuid());
            if ($case === 'downgrade_payment_required') {
                $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
                self::assertGreaterThanOrEqual(1, $this->worker()->runBatch($boundary, 10, $this->uuid()));
            }
            try {
                (new CancelSubscription($this->connection, new AccountAuthorization($this->connection)))
                    ->cancel($owner, $account, $source, 'cancel-source-' . $suffix, $this->uuid());
                self::fail('Source cancellation bypassed the open plan change.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
            self::assertSame('active', $this->subscriptionStatus($source));
            self::assertSame('draft', $this->subscriptionStatus($change['target_subscription_id']));
            self::assertContains($this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]), ['scheduled', 'payment_required']);
        }
    }

    public function testFailedAttemptCanBeCancelledButPendingAndSucceededAttemptsCannot(): void
    {
        $suffix = 'cancel-attempt-' . bin2hex(random_bytes(4));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000);
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'payment-' . $suffix, $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'], $this->providerAccount($suffix), 'bank_transfer', $this->uuid());
        $canceller = new CancelSubscriptionPlanChange($this->connection, new AccountAuthorization($this->connection));
        try {
            $canceller->cancel($owner, $account, $change['id'], 'cancel-pending-' . $suffix, $this->uuid());
            self::fail('Pending provider attempt was cancellable.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'failed', 'declined', new \DateTimeImmutable('now'), $this->uuid());
        self::assertSame('cancelled', $canceller->cancel($owner, $account, $change['id'], 'cancel-failed-' . $suffix, $this->uuid())['status']);
        self::assertSame('void', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
        self::assertSame('cancelled', $this->subscriptionStatus($change['target_subscription_id']));
        self::assertSame('active', $this->subscriptionStatus($source));
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
        try {
            $canceller->cancel($owner, $account, $change['id'], 'downgrade-first', $this->uuid());
            self::fail('Plan change request key was accepted for cancellation.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(['id' => $change['id'], 'status' => 'cancelled'], $canceller->cancel($owner, $account, $change['id'], 'cancel-downgrade', $this->uuid()));
        self::assertSame('cancelled', $this->subscriptionStatus($change['target_subscription_id']));
        self::assertSame('active', $this->subscriptionStatus($source));
        try {
            $this->requests()->request($owner, $account, $source, $newPrice, 1, 'cancel-downgrade', $this->uuid());
            self::fail('Cancellation key was accepted for a plan change request.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
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

    public function testLatePaidDowngradeStartsNewPeriodAtVerifiedSettlement(): void
    {
        $suffix = 'late-downgrade-' . bin2hex(random_bytes(4));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 2000, 500);
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'late-change-' . $suffix, $this->uuid());
        $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame(1, $this->worker()->runBatch($boundary, 10, $this->uuid()));
        $invoiceId = $this->connection->fetchOne('SELECT invoice_id FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]);
        self::assertIsString($invoiceId);
        $paymentId = $this->connection->fetchOne('SELECT id FROM commercial.payments WHERE invoice_id=?', [$invoiceId]);
        self::assertIsString($paymentId);
        self::assertSame('expired', (new ExpireSubscription($this->connection))->expire($account, $source, $boundary, $this->uuid())['status']);
        $settledAt = $boundary->modify('+10 days');
        $attempt = $this->attemptBeginner()->begin($paymentId, $this->providerAccount($suffix), 'bank_transfer', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'late-downgrade-provider-' . $suffix);
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $settledAt, $this->uuid());
        self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame('active', $this->subscriptionStatus($change['target_subscription_id']));
        self::assertSame($settledAt->format('Y-m-d H:i:s.uP'),
            (new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT current_period_start FROM commercial.subscriptions WHERE id=?', [$change['target_subscription_id']])))->format('Y-m-d H:i:s.uP'));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.refunds WHERE payment_id=?', [$paymentId]));
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
        self::assertSame('recovery_required', $this->connection->fetchOne('SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        self::assertSame('unsupported_provider', $this->connection->fetchOne('SELECT compensation_reason FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
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
        self::assertSame('pending', $this->connection->fetchOne('SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
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
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id = ?', [$change['id']]));
        self::assertSame('provider', (new GetSubscriptionPlanChange($this->connection,
            new AccountAuthorization($this->connection)))->get($owner, $account, $change['id'])['compensation_origin']);
        $replacement = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'retry-after-refund', $this->uuid());
        self::assertNotSame($change['id'], $replacement['id']);
    }

    public function testRefundConvergesAfterProviderSuccessResponseIsLostAcrossProcessRestart(): void
    {
        $suffix = 'refund-restart-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
            'payment-' . $suffix, $this->uuid());
        $providerAccountId = $this->yooKassaProviderAccount($suffix);
        $attempt = $this->attemptBeginner()->begin($payment['id'],
            $providerAccountId, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-' . $suffix);
        $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne(
            'SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $boundary, $this->uuid());
        $refund = $this->connection->fetchAssociative('SELECT id, provider_request_key FROM commercial.refunds WHERE payment_id=?', [$payment['id']]);
        self::assertIsArray($refund);
        self::assertIsString($refund['id']);
        self::assertIsString($refund['provider_request_key']);
        $statePath = sys_get_temp_dir() . '/refund-restart-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($statePath, '{"calls":[]}');
        try {
            $this->connection->commit();
            $lost = $this->runRefundRestartWorker($refund['id'], $statePath, 'kill_after_success');
            self::assertTrue($lost['signaled'], $lost['stderr']);
            self::assertSame(9, $lost['termsig']);
            self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.refunds WHERE id=?', [$refund['id']]));
            self::assertNull($this->connection->fetchOne('SELECT provider_refund_id FROM commercial.refunds WHERE id=?', [$refund['id']]));
            self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            $recovered = $this->runRefundRestartWorker($refund['id'], $statePath, 'recover');
            self::assertSame(0, $recovered['exit'], $recovered['stderr']);
            self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.refunds WHERE id=?', [$refund['id']]));
            $state = json_decode((string) file_get_contents($statePath), true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($state);
            self::assertSame([$refund['provider_request_key'], $refund['provider_request_key']], $state['calls']);
            self::assertSame($state['provider_refund_id'], $this->connection->fetchOne('SELECT provider_refund_id FROM commercial.refunds WHERE id=?', [$refund['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.refunds WHERE payment_id=?', [$payment['id']]));
            $finalizer = new FinalizeRefundedPlanChanges($this->connection);
            self::assertSame(1, $finalizer->runBatch(100, $this->uuid()));
            self::assertSame(0, $finalizer->runBatch(100, $this->uuid()));
            self::assertSame('refunded', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame('succeeded', $this->connection->fetchOne('SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='refund.succeeded' AND resource_id=?", [$refund['id']]));
            $this->completeFixtureReceipts($payment['id']);
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.receipts WHERE payment_id=? AND status='pending'", [$payment['id']]));
            $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status='disabled', updated_at=now() WHERE id=?", [$providerAccountId]);
            self::assertSame(['plan-change-' . $suffix . '.old' => true], $this->features($owner, $account));
            $this->connection->beginTransaction();
        } finally {
            unlink($statePath);
        }
    }

    /** @return array{exit:int,stderr:string,signaled:bool,termsig:int} */
    private function runRefundRestartWorker(string $refundId, string $statePath, string $mode): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/refund-restart-worker.php', $refundId,
            $statePath, $mode, $this->uuid()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not launch refund restart worker.');
        }
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_get_status($process);
        return ['exit' => proc_close($process), 'stderr' => $stderr,
            'signaled' => (bool) $status['signaled'], 'termsig' => (int) $status['termsig']];
    }

    private function completeFixtureReceipts(string $paymentId): void
    {
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM commercial.receipts WHERE payment_id=? AND status='pending'", [$paymentId]);
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new \LogicException('Fixture receipt identity is unavailable.');
            }
            [$row, , $request] = (new ReceiptSnapshot())->load($this->connection, $id);
            $provider = new ProviderReceipt(is_string($row['external_receipt_id']) ? $row['external_receipt_id'] : 'fixture-receipt-' . $id,
                $request->receiptType, $request->providerPaymentId, 'succeeded', $request->amountMinor,
                $request->currency, $request->items, new \DateTimeImmutable('now'), $request->providerRefundId);
            (new ApplyAuthoritativeReceipt($this->connection))->apply($id, $provider, $request, $this->uuid());
        }
    }

    public function testCheckoutConvergesAfterProviderChargeAndHardProcessKill(): void
    {
        $suffix = 'charge-restart-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
            'payment-' . $suffix, $this->uuid());
        $providerAccountId = $this->yooKassaProviderAccount($suffix);
        $checkoutKey = 'checkout-' . $suffix;
        $statePath = sys_get_temp_dir() . '/charge-restart-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($statePath, '{"calls":[]}');
        try {
            $this->connection->commit();
            $killed = $this->runPaymentRestartWorker($owner, $account, $payment['id'],
                $checkoutKey, $statePath, 'kill_after_success');
            self::assertTrue($killed['signaled'], $killed['stderr']);
            self::assertSame(9, $killed['termsig']);
            $attempt = $this->connection->fetchAssociative('SELECT id, provider_request_key, provider_payment_id, status FROM commercial.payment_attempts WHERE payment_id=?', [$payment['id']]);
            self::assertIsArray($attempt);
            self::assertSame('pending', $attempt['status']);
            self::assertNull($attempt['provider_payment_id']);
            self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
            self::assertSame('payment_required', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame(['plan-change-' . $suffix . '.old' => true], $this->features($owner, $account));
            $recovered = $this->runPaymentRestartWorker($owner, $account, $payment['id'],
                $checkoutKey, $statePath, 'recover');
            self::assertSame(0, $recovered['exit'], $recovered['stderr']);
            $state = json_decode((string) file_get_contents($statePath), true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($state);
            self::assertSame([$attempt['provider_request_key'], $attempt['provider_request_key']], $state['calls']);
            self::assertSame($state['provider_payment_id'], $this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id=?', [$attempt['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts WHERE payment_id=?', [$payment['id']]));
            self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
            self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoiceId]));
            self::assertSame('applied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='payment.succeeded' AND resource_id=?", [$payment['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.activated' AND aggregate_id=?", [$change['target_subscription_id']]));
            self::assertSame(['plan-change-' . $suffix . '.new' => true], $this->features($owner, $account));
            $this->completeFixtureReceipts($payment['id']);
            $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status='disabled', updated_at=now() WHERE id=?", [$providerAccountId]);
            $this->connection->beginTransaction();
        } finally {
            unlink($statePath);
        }
    }

    /** @return array{exit:int,stderr:string,signaled:bool,termsig:int} */
    private function runPaymentRestartWorker(string $userId, string $accountId, string $paymentId,
        string $checkoutKey, string $statePath, string $mode): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/payment-restart-worker.php', $userId,
            $accountId, $paymentId, $checkoutKey, $statePath, $this->uuid(), $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not launch payment restart worker.');
        }
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_get_status($process);
        return ['exit' => proc_close($process), 'stderr' => $stderr,
            'signaled' => (bool) $status['signaled'], 'termsig' => (int) $status['termsig']];
    }

    public function testRefundWebhookBeforeReconcilerAppliesOnePlanChangeCompensation(): void
    {
        $suffix = 'refund-webhook-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
            'payment-' . $suffix, $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'],
            $this->yooKassaProviderAccount($suffix), 'sbp', $this->uuid());
        $providerPaymentId = 'provider-' . $suffix;
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerPaymentId);
        $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne(
            'SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $boundary, $this->uuid());
        $refund = $this->connection->fetchAssociative('SELECT id, amount_minor FROM commercial.refunds WHERE payment_id=?', [$payment['id']]);
        self::assertIsArray($refund);
        self::assertIsString($refund['id']);
        $gateway = new FakeRefundProviderGateway();
        $providerRefundId = 'yk-refund-' . str_replace('-', '', $refund['id']);
        $gateway->refunds[$providerRefundId] = new ProviderRefund($providerRefundId, $providerPaymentId,
            'succeeded', (int) $refund['amount_minor'], 'RUB', new \DateTimeImmutable('now'), $refund['id']);
        $receiver = new ReceiveYooKassaWebhook($this->connection,
            new YooKassaProviderAccountLocator($this->connection), new FakePaymentProviderGateway(),
            $this->authoritativeProcessor(), $gateway, $this->refundSubmission($gateway));
        $body = json_encode(['type' => 'notification', 'event' => 'refund.succeeded',
            'object' => ['id' => $providerRefundId]], JSON_THROW_ON_ERROR);
        self::assertSame('processed', $receiver->receive($body, 'application/json', $this->uuid())['status']);
        $receiver->receive($body, 'application/json', $this->uuid());
        self::assertCount(1, $gateway->getCalls);
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.refunds WHERE id=?', [$refund['id']]));
        self::assertSame(0, (new ReconcileYooKassaRefunds($this->connection,
            $this->refundSubmission($gateway)))->reconcile(100, $this->uuid())['selected']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='refund.succeeded' AND resource_id=?", [$refund['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='refund.succeeded' AND aggregate_id=?", [$refund['id']]));
        self::assertSame(1, (new FinalizeRefundedPlanChanges($this->connection))->runBatch(100, $this->uuid()));
        self::assertSame('refunded', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame(['plan-change-' . $suffix . '.old' => true], $this->features($owner, $account));
    }

    public function testRefundWebhookAndReconcilerOverlapWithoutDuplicateCompensation(): void
    {
        $suffix = 'refund-race-' . bin2hex(random_bytes(5));
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
            'payment-' . $suffix, $this->uuid());
        $providerAccountId = $this->yooKassaProviderAccount($suffix);
        $attempt = $this->attemptBeginner()->begin($payment['id'], $providerAccountId, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-' . $suffix);
        $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne(
            'SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $boundary, $this->uuid());
        $refund = $this->connection->fetchAssociative('SELECT id, amount_minor FROM commercial.refunds WHERE payment_id=?', [$payment['id']]);
        self::assertIsArray($refund);
        self::assertIsString($refund['id']);
        $pendingGateway = new FakeRefundProviderGateway();
        self::assertFalse($this->refundSubmission($pendingGateway)->submit($refund['id'], $this->uuid()));
        self::assertCount(1, $pendingGateway->createCalls);
        $providerRefundId = $this->connection->fetchOne('SELECT provider_refund_id FROM commercial.refunds WHERE id=?', [$refund['id']]);
        self::assertIsString($providerRefundId);
        $this->completeFixtureReceipts($payment['id']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_provider_accounts WHERE provider_code='yookassa' AND status='active'"));
        $instant = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');
        $this->connection->commit();
        try {
            $results = $this->planRace('webhook_refund', $refund['id'],
                implode('|', [$this->uuid(), $instant]),
                'reconcile_refund', $refund['id'],
                implode('|', [$this->uuid(), $instant]), $refund['id'], null, 'refund');
            self::assertSame([0, 0], array_column($results, 'exit'), implode("\n", array_column($results, 'stderr')));
            self::assertSame('processed', $this->connection->fetchOne("SELECT status FROM commercial.payment_provider_events WHERE provider_account_id=? AND event_type='refund.succeeded' AND provider_object_id=?", [$providerAccountId, $providerRefundId]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_provider_events WHERE provider_account_id=? AND event_type='refund.succeeded' AND provider_object_id=?", [$providerAccountId, $providerRefundId]));
            self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.refunds WHERE id=?', [$refund['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='refund.succeeded' AND resource_id=?", [$refund['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='refund.succeeded' AND aggregate_id=?", [$refund['id']]));
            self::assertSame(1, (new FinalizeRefundedPlanChanges($this->connection))->runBatch(100, $this->uuid()));
            self::assertSame(0, (new FinalizeRefundedPlanChanges($this->connection))->runBatch(100, $this->uuid()));
            self::assertSame('refunded', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
            self::assertSame(['plan-change-' . $suffix . '.old' => true], $this->features($owner, $account));
            $this->completeFixtureReceipts($payment['id']);
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.receipts WHERE payment_id=? AND status='pending'", [$payment['id']]));
        } finally {
            $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status='disabled', receipt_mode='disabled', receipt_vat_code=NULL, receipt_payment_subject=NULL, receipt_payment_mode=NULL, updated_at=now() WHERE id=?", [$providerAccountId]);
            $this->connection->beginTransaction();
        }
    }

    public function testUnsupportedFiscalConfigurationCreatesDurableRecoveryCase(): void
    {
        [$owner, $account, $source, , $newPrice] = $this->context('unsupported-fiscal-' . bin2hex(random_bytes(4)), 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'fiscal-change-' . $this->uuid(), $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'fiscal-payment-' . $this->uuid(), $this->uuid());
        $provider = $this->yooKassaProviderAccount('unsupported-fiscal-' . bin2hex(random_bytes(4)));
        $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET receipt_mode='disabled', receipt_vat_code=NULL, receipt_payment_subject=NULL, receipt_payment_mode=NULL WHERE id=?", [$provider]);
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'unsupported-fiscal-provider-' . $this->uuid());
        $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $boundary, $this->uuid());
        self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame('recovery_required', $this->connection->fetchOne('SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame('unsupported_fiscal_configuration', $this->connection->fetchOne('SELECT compensation_reason FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        $read = (new GetSubscriptionPlanChange($this->connection, new AccountAuthorization($this->connection)))->get($owner, $account, $change['id']);
        self::assertSame('recovery_required', $read['compensation_status']);
        self::assertSame('unsupported_fiscal_configuration', $read['compensation_reason']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.refunds WHERE payment_id=?', [$payment['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.plan_change_recovery_required' AND resource_id=?", [$change['id']]));
    }

    public function testExternalRecoveryRequiresTwoAuthorizedOperatorsAndExactDocument(): void
    {
        [$owner, $account, $source, $changeId, $paymentId] = $this->externalRecoveryFixture('external-two-person');
        $first = $this->user();
        $second = $this->user();
        $unauthorized = $this->user();
        foreach ([$first, $second] as $id) {
            $this->connection->executeStatement("INSERT INTO commercial.financial_operators(control_user_id, authorization_reference) VALUES (?, 'urn:change-ticket:finance-test')", [$id]);
        }
        $service = new ExternalPlanChangeCompensation($this->connection);
        $hash = hash('sha256', 'test external transfer document');
        try {
            $service->propose($unauthorized, $changeId, 'bank-transfer-001', 'urn:secure-doc:refund-001', $hash, $this->uuid());
            self::fail('Unprivileged account proposed an external compensation.');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        $proposal = $service->propose($first, $changeId, 'bank-transfer-001', 'urn:secure-doc:refund-001', $hash, $this->uuid());
        self::assertSame('proposed', $proposal['status']);
        $invoiceLineId = $this->connection->fetchOne('SELECT id FROM commercial.invoice_lines WHERE invoice_id=(SELECT invoice_id FROM commercial.payments WHERE id=?) AND unit_amount_minor>0 LIMIT 1', [$paymentId]);
        self::assertIsString($invoiceLineId);
        $assertProviderRefundBlocked = function () use ($owner, $account, $paymentId, $invoiceLineId): void {
            try {
                $this->refundCreator()->create($owner, $account, $paymentId,
                    [['invoice_line_id' => $invoiceLineId, 'quantity' => 1, 'unit_amount_minor' => 1]],
                    'refund-after-external-' . $this->uuid(), $this->uuid());
                self::fail('A provider refund duplicated an external compensation.');
            } catch (\DomainException $exception) {
                self::assertSame('External compensation already claims this payment.', $exception->getMessage());
            }
        };
        $assertProviderRefundBlocked();
        self::assertSame($proposal, $service->current($second, $changeId));
        self::assertSame($proposal, $service->propose($first, $changeId, 'bank-transfer-001', 'urn:secure-doc:refund-001', $hash, $this->uuid()));
        self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$changeId]));
        self::assertSame(['plan-change-external-two-person.old' => true], $this->features($owner, $account));
        foreach ([[$first, $hash], [$second, str_repeat('0', 64)]] as [$reviewer, $reviewHash]) {
            try {
                $service->review($reviewer, $proposal['id'], 'bank-transfer-001', 'urn:secure-doc:refund-001', $reviewHash, true, null, $this->uuid());
                self::fail('Invalid external compensation review was accepted.');
            } catch (\DomainException|\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$changeId]));
        $this->connection->executeStatement('UPDATE commercial.financial_operators SET revoked_at=now() WHERE control_user_id=?', [$second]);
        try {
            $service->review($second, $proposal['id'], 'bank-transfer-001', 'urn:secure-doc:refund-001', $hash, true, null, $this->uuid());
            self::fail('Revoked financial operator approved an external refund.');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        $this->connection->executeStatement('UPDATE commercial.financial_operators SET revoked_at=NULL WHERE control_user_id=?', [$second]);
        $approved = $service->review($second, $proposal['id'], 'bank-transfer-001', 'urn:secure-doc:refund-001', $hash, true, null, $this->uuid());
        self::assertSame('approved', $approved['status']);
        $assertProviderRefundBlocked();
        self::assertSame($approved, $service->propose($first, $changeId, 'bank-transfer-001', 'urn:secure-doc:refund-001', $hash, $this->uuid()));
        self::assertSame($approved, $service->review($second, $proposal['id'], 'bank-transfer-001', 'urn:secure-doc:refund-001', $hash, true, null, $this->uuid()));
        self::assertSame('refunded', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$changeId]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id=?', [$changeId]));
        self::assertSame('external', (new GetSubscriptionPlanChange($this->connection,
            new AccountAuthorization($this->connection)))->get($owner, $account, $changeId)['compensation_origin']);
        self::assertSame(['plan-change-external-two-person.old' => true], $this->features($owner, $account));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.refunds WHERE payment_id=?', [$paymentId]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.external_compensation_approved' AND resource_id=?", [$proposal['id']]));
        try {
            $this->connection->executeStatement("UPDATE commercial.external_compensation_attestations SET document_sha256=? WHERE id=?", [str_repeat('0', 64), $proposal['id']]);
            self::fail('Approved financial document was rewritten.');
        } catch (\Doctrine\DBAL\Exception) {
            self::addToAssertionCount(1);
        }
    }

    public function testExternalRecoveryRejectsPendingProviderRefundAndPermitsCorrectedProposal(): void
    {
        [, , , $changeId] = $this->externalRecoveryFixture('external-reject');
        $first = $this->user();
        $second = $this->user();
        foreach ([$first, $second] as $id) {
            $this->connection->executeStatement("INSERT INTO commercial.financial_operators(control_user_id, authorization_reference) VALUES (?, 'urn:change-ticket:finance-test')", [$id]);
        }
        $service = new ExternalPlanChangeCompensation($this->connection);
        $hash = hash('sha256', 'wrong document');
        $proposal = $service->propose($first, $changeId, 'bank-transfer-002', 'urn:secure-doc:wrong', $hash, $this->uuid());
        self::assertSame('rejected', $service->review($second, $proposal['id'], 'bank-transfer-002', 'urn:secure-doc:wrong',
            $hash, false, 'Document did not prove the transfer', $this->uuid())['status']);
        self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$changeId]));
        $correct = $service->propose($first, $changeId, 'bank-transfer-003', 'urn:secure-doc:correct',
            hash('sha256', 'correct document'), $this->uuid());
        self::assertNotSame($proposal['id'], $correct['id']);
        $this->connection->executeStatement("INSERT INTO commercial.refunds(commercial_account_id,payment_id,provider_account_id,client_request_key,request_sha256,provider_request_key,amount_minor,currency,status) SELECT p.commercial_account_id,p.id,a.provider_account_id,?,decode(repeat('0',64),'hex'),?,p.amount_minor,p.currency,'pending' FROM commercial.payments p JOIN commercial.payment_attempts a ON a.payment_id=p.id WHERE p.invoice_id=(SELECT invoice_id FROM commercial.subscription_plan_changes WHERE id=?) AND a.status='succeeded'", ['test-pending-external-' . $changeId, 'test-provider-pending-' . $changeId, $changeId]);
        try {
            $service->review($second, $correct['id'], 'bank-transfer-003', 'urn:secure-doc:correct',
                hash('sha256', 'correct document'), true, null, $this->uuid());
            self::fail('External compensation was approved with a pending provider refund.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('proposed', $this->connection->fetchOne('SELECT status FROM commercial.external_compensation_attestations WHERE id=?', [$correct['id']]));
        self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$changeId]));
    }

    public function testTwoIndependentExternalApprovalsSerializeToOneFinancialEffect(): void
    {
        $suffix = 'external-approval-race-' . bin2hex(random_bytes(4));
        [$owner, $account, $source, $changeId, $paymentId] = $this->externalRecoveryFixture($suffix);
        $first = $this->user();
        $second = $this->user();
        $third = $this->user();
        foreach ([$first, $second, $third] as $id) {
            $this->connection->executeStatement("INSERT INTO commercial.financial_operators(control_user_id, authorization_reference) VALUES (?, 'urn:change-ticket:finance-test')", [$id]);
        }
        $transaction = 'bank-transfer-race-' . str_replace('-', '', $changeId);
        $document = 'urn:secure-doc:race-' . $changeId;
        $hash = hash('sha256', $document);
        $proposal = (new ExternalPlanChangeCompensation($this->connection))->propose($first, $changeId,
            $transaction, $document, $hash, $this->uuid());
        $providerId = $this->connection->fetchOne("SELECT provider_account_id FROM commercial.payment_attempts WHERE payment_id=? AND status='succeeded'", [$paymentId]);
        self::assertIsString($providerId);
        $this->connection->commit();
        try {
            $results = $this->planRace('approve_external_compensation', $proposal['id'],
                implode('|', [$second, $transaction, $document, $hash, $this->uuid()]),
                'approve_external_compensation', $proposal['id'],
                implode('|', [$third, $transaction, $document, $hash, $this->uuid()]), $source);
            self::assertSame([0, 0], array_column($results, 'exit'), implode("\n", array_column($results, 'stderr')));
            $outcomes = array_column($results, 'stdout');
            sort($outcomes);
            self::assertSame(['approved', 'conflict'], $outcomes);
            self::assertSame('refunded', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$changeId]));
            self::assertSame('approved', $this->connection->fetchOne('SELECT status FROM commercial.external_compensation_attestations WHERE id=?', [$proposal['id']]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.external_compensation_approved' AND resource_id=?", [$proposal['id']]));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.refunds WHERE payment_id=?', [$paymentId]));
            self::assertSame(['plan-change-' . $suffix . '.old' => true], $this->features($owner, $account));
        } finally {
            $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status='disabled', updated_at=now() WHERE id=?", [$providerId]);
            $this->connection->beginTransaction();
        }
    }

    /** @return array{string, string, string, string, string} */
    private function externalRecoveryFixture(string $suffix): array
    {
        [$owner, $account, $source, , $newPrice] = $this->context($suffix, 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1,
            'change-' . $suffix, $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId,
            'payment-' . $suffix, $this->uuid());
        $provider = $this->yooKassaProviderAccount($suffix);
        $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET receipt_mode='disabled', receipt_vat_code=NULL, receipt_payment_subject=NULL, receipt_payment_mode=NULL WHERE id=?", [$provider]);
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-' . $suffix);
        $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne(
            'SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $boundary, $this->uuid());
        self::assertSame('recovery_required', $this->connection->fetchOne('SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        return [$owner, $account, $source, $change['id'], $payment['id']];
    }

    public function testDefinitiveRefundFailureBecomesOneRecoveryCaseWithoutReleasingPlan(): void
    {
        [$owner, $account, $source, , $newPrice] = $this->context('failed-compensation-' . bin2hex(random_bytes(4)), 1000, 2000, 'RUB');
        $change = $this->requests()->request($owner, $account, $source, $newPrice, 1, 'failed-refund-change-' . $this->uuid(), $this->uuid());
        $invoiceId = $change['invoice_id'];
        self::assertIsString($invoiceId);
        $payment = $this->paymentCreator()->create($owner, $account, $invoiceId, 'failed-refund-payment-' . $this->uuid(), $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'], $this->yooKassaProviderAccount('failed-compensation-' . bin2hex(random_bytes(4))), 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'failed-refund-provider-' . $this->uuid());
        $boundary = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $boundary, $this->uuid());
        $refundId = $this->connection->fetchOne('SELECT id FROM commercial.refunds WHERE payment_id=?', [$payment['id']]);
        self::assertIsString($refundId);
        $gateway = new FakeRefundProviderGateway();
        $gateway->createFailure = PaymentProviderException::definitive('invalid_provider_request');
        try {
            $this->refundSubmission($gateway)->submit($refundId, $this->uuid());
            self::fail('Definitive provider failure was not surfaced.');
        } catch (PaymentProviderException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('canceled', $this->connection->fetchOne('SELECT status FROM commercial.refunds WHERE id=?', [$refundId]));
        $marker = new MarkFailedPlanChangeCompensations($this->connection);
        self::assertSame(1, $marker->runBatch(100, $this->uuid()));
        self::assertSame(0, $marker->runBatch(100, $this->uuid()));
        self::assertSame('recovery_required', $this->connection->fetchOne('SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame('provider_refund_canceled', $this->connection->fetchOne('SELECT compensation_reason FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame('paid_unapplied', $this->connection->fetchOne('SELECT status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.plan_change_recovery_required' AND resource_id=?", [$change['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.plan_change_refund_required' AND aggregate_id=?", [$change['id']]));
        self::assertSame(0, (new FinalizeRefundedPlanChanges($this->connection))->runBatch(100, $this->uuid()));
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
