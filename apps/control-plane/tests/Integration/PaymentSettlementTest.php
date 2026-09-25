<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Subscription\Application\CancelSubscription;

final class PaymentSettlementTest extends PaymentTestCase
{
    public function testProviderSuccessAtomicallyPaysAndActivatesWithStableRetry(): void
    {
        [$owner, $account, , , , $subscription, $invoice] = $this->issuedInvoiceContext('payment-settle');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'payment-settle-create', $this->uuid());
        $provider = $this->providerAccount('settle');
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'card', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-settlement-1');
        $instant = new \DateTimeImmutable('2026-09-25T12:34:56.123456Z');
        $correlation = $this->uuid();

        $first = $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, $instant, $correlation);
        $second = $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('2027-01-01T00:00:00Z'), $this->uuid());

        self::assertSame($first, $second);
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]));
        $timestamps = $this->connection->fetchAssociative('SELECT p.succeeded_at, i.paid_at, s.current_period_start FROM commercial.payments p JOIN commercial.invoices i ON i.id = p.invoice_id JOIN commercial.subscriptions s ON s.id = i.subscription_id WHERE p.id = ?', [$payment['id']]);
        self::assertIsArray($timestamps);
        self::assertSame($timestamps['succeeded_at'], $timestamps['paid_at']);
        self::assertSame($timestamps['paid_at'], $timestamps['current_period_start']);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ? AND status = 'open'", [$subscription]));
        foreach (['payment.succeeded' => $payment['id'], 'invoice.paid' => $invoice['id'], 'subscription.activated' => $subscription] as $event => $resource) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.audit_events WHERE action = ? AND resource_id = ?', [$event, $resource]));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.outbox_events WHERE event_type = ? AND aggregate_id = ?', [$event, $resource]));
        }
    }

    public function testDirectActivationRequiresPaidInvoice(): void
    {
        [, $account, , , , $subscription] = $this->invoiceContext('payment-unpaid-activation');
        try {
            $this->activator()->activate($account, $subscription, $this->activationInstant(), $this->uuid());
            self::fail('Direct activation without a paid invoice must fail.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
    }

    public function testZeroInvoiceSettlesWithoutAttempt(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('payment-zero');
        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES ('payment-zero-plan', 1, 'Zero') RETURNING id");
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 0, 'month') RETURNING id", [$plan]);
        self::assertIsString($price);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $invoice = $this->issuer()->issue($owner, $account, $subscription, 'payment-zero-invoice', $this->uuid());

        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'payment-zero-create', $this->uuid());

        self::assertSame('succeeded', $payment['status']);
        self::assertSame(0, $payment['amount_minor']);
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice['id']]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts WHERE payment_id = ?', [$payment['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
    }

    public function testDraftCancellationCancelsPendingPaymentButRejectsProcessingAttempt(): void
    {
        [$owner, $account, , , , $subscription, $invoice] = $this->issuedInvoiceContext('payment-cancel-pending');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'payment-cancel-create', $this->uuid());
        $canceller = new CancelSubscription($this->connection, new AccountAuthorization($this->connection));
        $canceller->cancel($owner, $account, $subscription, 'payment-cancel-subscription', $this->uuid());
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('void', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice['id']]));

        [$processingOwner, $processingAccount, , , , $processingSubscription, $processingInvoice] = $this->issuedInvoiceContext('payment-cancel-processing');
        $processingPayment = $this->paymentCreator()->create($processingOwner, $processingAccount, $processingInvoice['id'], 'payment-processing-create', $this->uuid());
        $provider = $this->providerAccount('cancel-processing');
        $attempt = $this->attemptBeginner()->begin($processingPayment['id'], $provider, 'card', $this->uuid());
        try {
            $canceller->cancel($processingOwner, $processingAccount, $processingSubscription, 'payment-processing-cancel', $this->uuid());
            self::fail('Draft cancellation during a pending provider attempt must fail.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$processingSubscription]));
        self::assertSame('issued', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$processingInvoice['id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$processingPayment['id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]));
    }

    public function testCancelledPaidSubscriptionCanBeReplacedByZeroAmountSettlement(): void
    {
        [$owner, $account, $profile, , , $oldSubscription, $oldInvoice] = $this->issuedInvoiceContext('payment-replacement-old');
        $oldPayment = $this->paymentCreator()->create($owner, $account, $oldInvoice['id'], 'replacement-old-payment', $this->uuid());
        $provider = $this->providerAccount('replacement-old');
        $attempt = $this->attemptBeginner()->begin($oldPayment['id'], $provider, 'card', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'replacement-old-provider-payment');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('2026-09-25T09:00:00Z'), $this->uuid());
        (new CancelSubscription($this->connection, new AccountAuthorization($this->connection)))
            ->cancel($owner, $account, $oldSubscription, 'replacement-old-cancel', $this->uuid());

        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES ('payment-replacement-zero', 1, 'Replacement Zero') RETURNING id");
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 0, 'month') RETURNING id", [$plan]);
        self::assertIsString($price);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $replacement = $this->draftSubscription($account, $profile, [$price]);
        $newInvoice = $this->issuer()->issue($owner, $account, $replacement, 'replacement-zero-invoice', $this->uuid());
        $newPayment = $this->paymentCreator()->create($owner, $account, $newInvoice['id'], 'replacement-zero-payment', $this->uuid());

        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$oldSubscription]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$oldPayment['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$oldInvoice['id']]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$replacement]));
        self::assertSame('succeeded', $newPayment['status']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts WHERE payment_id = ?', [$newPayment['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ?', [$replacement]));
    }
}
