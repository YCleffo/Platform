<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Billing\Application\CollectRenewalInvoice;
use ControlPlane\Billing\Application\ProcessRenewalDunning;
use ControlPlane\Payment\Application\PaymentCreator;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Payment\Provider\ProviderPaymentFailure;
use ControlPlane\Payment\Provider\ProviderPaymentMethod;
use ControlPlane\Subscription\Application\ExpireSubscription;
use ControlPlane\Tests\Support\FakePaymentProviderGateway;
use PHPUnit\Framework\Attributes\DataProvider;

final class RenewalCollectionTest extends RenewalTestCase
{
    public function testInitialAutomaticCollectionKeepsOneFinancialIdentity(): void
    {
        [, $account, , $renewal] = $this->activeWithSavedCard('renewal-initial');
        $gateway = new FakePaymentProviderGateway();
        $result = $this->collectionIdentity($this->collector($gateway)->collect(
            $renewal,
            $this->uuid(),
            new \DateTimeImmutable('2026-02-15T10:00:00Z'),
        ));

        self::assertCount(1, $gateway->createCalls);
        [$providerAccount, $request] = $gateway->createCalls[0];
        self::assertSame($account, $this->connection->fetchOne('SELECT commercial_account_id FROM commercial.payments WHERE id=?', [$result['payment_id']]));
        self::assertSame('card-renewal-initial', $request->providerPaymentMethodId);
        self::assertFalse($request->savePaymentMethod);
        self::assertSame($providerAccount->id, $this->connection->fetchOne('SELECT provider_account_id FROM commercial.payment_attempts WHERE id=?', [$result['attempt_id']]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts WHERE payment_id=?', [$result['payment_id']]));
    }

    public function testNoMethodTransitionIsDurableAndDoesNotSpam(): void
    {
        [, $account, $subscription] = $this->activeRenewable('renewal-no-method', 'RUB');
        $renewal = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        $gateway = new FakePaymentProviderGateway();
        $collector = $this->collector($gateway);

        $collector->collect($renewal['id'], $this->uuid());
        $collector->collect($renewal['id'], $this->uuid());
        self::assertSame('payment_method_required', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal['id']]));
        self::assertSame(0, count($gateway->createCalls));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.payment_method_required' AND aggregate_id=?", [$renewal['id']]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$renewal['id']]));
    }

    public function testUncertainCreateReusesAttemptAndProviderRequestKey(): void
    {
        [, $account, , $renewal] = $this->activeWithSavedCard('renewal-uncertain');
        $gateway = new FakePaymentProviderGateway();
        $gateway->createFailure = \ControlPlane\Payment\Provider\PaymentProviderException::uncertain('transport_timeout');
        $collector = $this->collector($gateway);
        $first = $this->collectionIdentity($collector->collect($renewal, $this->uuid(), new \DateTimeImmutable('2026-02-15T10:00:00Z')));
        $key = $this->connection->fetchOne('SELECT provider_request_key FROM commercial.payment_attempts WHERE id=?', [$first['attempt_id']]);

        self::assertSame(0, $collector->collectDue(new \DateTimeImmutable('2026-02-15T10:00:29Z'), 10, $this->uuid()));
        self::assertSame(1, $collector->collectDue(new \DateTimeImmutable('2026-02-15T10:00:31Z'), 10, $this->uuid()));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=?', [$renewal]));
        self::assertSame($key, $gateway->createCalls[1][1]->providerRequestKey);
        self::assertSame($first['attempt_id'], $gateway->createCalls[1][1]->attemptId);
        self::assertNotFalse($this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id=?', [$first['attempt_id']]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT attempt_count FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT network_retry_count FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
    }

    public function testPaymentMethodRequiredRestoresOnceWhenNewDefaultCardAppears(): void
    {
        [, , , $renewal] = $this->activeWithSavedCard('renewal-restored');
        $saved = $this->connection->fetchAssociative("SELECT id,commercial_account_id,provider_account_id,created_from_payment_attempt_id FROM commercial.saved_payment_methods WHERE provider_method_reference='card-renewal-restored'");
        self::assertIsArray($saved);
        $this->connection->executeStatement("UPDATE commercial.saved_payment_methods SET status='disabled',is_default=false,disabled_at=now(),updated_at=now() WHERE id=?", [$saved['id']]);
        $gateway = new FakePaymentProviderGateway();
        $collector = $this->collector($gateway);
        self::assertSame('no_payment_method', $collector->collect($renewal, $this->uuid())['state']);

        $this->connection->executeStatement("INSERT INTO commercial.saved_payment_methods (commercial_account_id,provider_account_id,provider_method_reference,method_type,status,is_default,created_from_payment_attempt_id) VALUES (?, ?, 'card-renewal-restored-new', 'bank_card', 'active', true, ?)", [$saved['commercial_account_id'], $saved['provider_account_id'], $saved['created_from_payment_attempt_id']]);
        $this->dunning($collector)->process(new \DateTimeImmutable('2026-02-15T10:00:00Z'), 10, $this->uuid());
        $this->dunning($collector)->process(new \DateTimeImmutable('2026-02-15T10:00:01Z'), 10, $this->uuid());

        self::assertCount(1, $gateway->createCalls);
        self::assertSame('card-renewal-restored-new', $gateway->createCalls[0][1]->providerPaymentMethodId);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.payment_method_restored' AND aggregate_id=?", [$renewal]));
        self::assertSame('collecting', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
    }

    public function testCollectionStateRejectsCrossSubscriptionIdentity(): void
    {
        [, $accountA, $subscriptionA] = $this->activeRenewable('renewal-integrity-a', 'RUB');
        [, $accountB, $subscriptionB] = $this->activeRenewable('renewal-integrity-b', 'RUB');
        $invoiceA = $this->renewalIssuer()->issue($accountA, $subscriptionA, $this->uuid());
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement(
            "INSERT INTO commercial.renewal_collection_states (invoice_id,commercial_account_id,subscription_id,state) VALUES (?, ?, ?, 'ready')",
            [$invoiceA['id'], $accountB, $subscriptionB],
        ));
    }

    public function testGraceExpiryExhaustsCollectionAndUsesExistingExpirationLifecycle(): void
    {
        [, $account, $subscription] = $this->activeRenewable('renewal-exhaustion', 'RUB');
        $renewal = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        $gateway = new FakePaymentProviderGateway();
        $collector = $this->collector($gateway);
        $collector->collect($renewal['id'], $this->uuid(), new \DateTimeImmutable('2026-02-15T10:00:00Z'));
        $dunning = $this->dunning($collector);
        $dunning->process(new \DateTimeImmutable('2026-02-15T10:00:00Z'), 10, $this->uuid());
        $report = $dunning->process(new \DateTimeImmutable('2026-02-15T11:00:01Z'), 10, $this->uuid());

        self::assertSame(1, $report['exhausted']);
        self::assertSame('exhausted', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal['id']]));
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.dunning_exhausted' AND aggregate_id=?", [$subscription]));
    }

    public function testTerminalRetryUsesSamePaymentAndNewAttemptIdentity(): void
    {
        [, , , $renewal] = $this->activeWithSavedCard('renewal-terminal');
        $gateway = new FakePaymentProviderGateway();
        $gateway->createCancellation = new ProviderPaymentFailure(ProviderPaymentFailure::RETRYABLE, 'insufficient_funds');
        $collector = $this->collector($gateway);
        $first = $this->collectionIdentity($collector->collect($renewal, $this->uuid(), new \DateTimeImmutable('2026-02-15T10:00:00Z')));
        $firstKey = $this->connection->fetchOne('SELECT provider_request_key FROM commercial.payment_attempts WHERE id=?', [$first['attempt_id']]);
        self::assertSame('retry_wait', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT attempt_count FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));

        $report = $this->dunning($collector)->process(new \DateTimeImmutable('2026-02-15T10:01:01Z'), 10, $this->uuid());
        $attempts = $this->connection->fetchAllAssociative('SELECT id, provider_request_key, payment_id FROM commercial.payment_attempts WHERE payment_id=? ORDER BY created_at,id', [$first['payment_id']]);
        self::assertSame(1, $report['started']);
        self::assertCount(2, $attempts);
        self::assertSame($attempts[0]['payment_id'], $attempts[1]['payment_id']);
        self::assertNotSame($first['attempt_id'], $attempts[1]['id']);
        self::assertNotSame($firstKey, $attempts[1]['provider_request_key']);
    }

    public function testPastDueAndAuthoritativeSettlementRecoverBilling(): void
    {
        [, $account, $subscription, $renewal] = $this->activeWithSavedCard('renewal-recovery');
        $gateway = new FakePaymentProviderGateway();
        $collector = $this->collector($gateway);
        $started = $this->collectionIdentity($collector->collect($renewal, $this->uuid(), new \DateTimeImmutable('2026-02-15T10:00:00Z')));
        $this->dunning($collector)->process(new \DateTimeImmutable('2026-02-15T10:00:01Z'), 10, $this->uuid());
        $this->dunning($collector)->process(new \DateTimeImmutable('2026-02-15T10:00:02Z'), 10, $this->uuid());
        self::assertSame('past_due', $this->connection->fetchOne('SELECT billing_status FROM commercial.subscriptions WHERE id=?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.past_due' AND aggregate_id=?", [$subscription]));

        $providerId = (string) $this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id=?', [$started['attempt_id']]);
        $gateway->payments[$providerId] = new ProviderPayment($providerId, 'succeeded', true, 1000, 'RUB',
            ['payment_id' => $started['payment_id'], 'attempt_id' => $started['attempt_id'], 'invoice_id' => $renewal], null,
            new \DateTimeImmutable('2026-02-15T10:00:02Z'));
        $this->yooKassaReconciliation($gateway)->reconcile(10, $this->uuid());

        $subscriptionRow = $this->connection->fetchAssociative('SELECT status,billing_status,past_due_since,grace_until,current_period_start FROM commercial.subscriptions WHERE id=?', [$subscription]);
        self::assertIsArray($subscriptionRow);
        self::assertSame('active', $subscriptionRow['status']);
        self::assertSame('current', $subscriptionRow['billing_status']);
        self::assertNull($subscriptionRow['past_due_since']);
        self::assertNull($subscriptionRow['grace_until']);
        self::assertSame('2026-02-15', (new \DateTimeImmutable($subscriptionRow['current_period_start']))->format('Y-m-d'));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.billing_recovered' AND aggregate_id=?", [$subscription]));
        self::assertSame($account, $this->connection->fetchOne('SELECT commercial_account_id FROM commercial.payments WHERE id=?', [$started['payment_id']]));
    }

    public function testLateSettlementAfterExpirationDoesNotResurrectSubscription(): void
    {
        [, , $subscription, $renewal] = $this->activeWithSavedCard('renewal-late');
        $gateway = new FakePaymentProviderGateway();
        $collector = $this->collector($gateway);
        $started = $this->collectionIdentity($collector->collect($renewal, $this->uuid(), new \DateTimeImmutable('2026-02-15T10:00:00Z')));
        $dunning = $this->dunning($collector);
        $dunning->process(new \DateTimeImmutable('2026-02-15T10:00:00Z'), 10, $this->uuid());
        $dunning->process(new \DateTimeImmutable('2026-02-15T11:00:01Z'), 10, $this->uuid());
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$subscription]));

        $providerId = (string) $this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id=?', [$started['attempt_id']]);
        $gateway->payments[$providerId] = new ProviderPayment($providerId, 'succeeded', true, 1000, 'RUB',
            ['payment_id' => $started['payment_id'], 'attempt_id' => $started['attempt_id'], 'invoice_id' => $renewal], null,
            new \DateTimeImmutable('2026-02-15T11:00:02Z'));
        $this->yooKassaReconciliation($gateway)->reconcile(10, $this->uuid());

        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$renewal]));
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$subscription]));
        self::assertSame('exhausted', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.renewal_late_settlement' AND aggregate_id=?", [$subscription]));
    }

    public function testWebhookSettlementUsesTheSameBillingRecoveryPath(): void
    {
        [, , $subscription, $renewal] = $this->activeWithSavedCard('renewal-webhook-recovery');
        $gateway = new FakePaymentProviderGateway();
        $collector = $this->collector($gateway);
        $started = $this->collectionIdentity($collector->collect($renewal, $this->uuid(), new \DateTimeImmutable('2026-02-15T10:00:00Z')));
        $this->dunning($collector)->process(new \DateTimeImmutable('2026-02-15T10:00:01Z'), 10, $this->uuid());
        $providerId = (string) $this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id=?', [$started['attempt_id']]);
        $gateway->payments[$providerId] = new ProviderPayment($providerId, 'succeeded', true, 1000, 'RUB',
            ['payment_id' => $started['payment_id'], 'attempt_id' => $started['attempt_id'], 'invoice_id' => $renewal], null,
            new \DateTimeImmutable('2026-02-15T10:00:02Z'));
        $body = json_encode(['type' => 'notification', 'event' => 'payment.succeeded', 'object' => ['id' => $providerId]], JSON_THROW_ON_ERROR);
        $this->yooKassaWebhook($gateway)->receive($body, 'application/json', $this->uuid());

        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$renewal]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
        self::assertSame('current', $this->connection->fetchOne('SELECT billing_status FROM commercial.subscriptions WHERE id=?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.billing_recovered' AND aggregate_id=?", [$subscription]));
    }

    #[DataProvider('savedMethodFailureCases')]
    public function testOnlyAuthoritativeMethodInvalidityDisablesSavedCard(string $suffix, ProviderPaymentFailure $failure, string $expectedStatus): void
    {
        [, , , $renewal] = $this->activeWithSavedCard('renewal-method-' . $suffix);
        $gateway = new FakePaymentProviderGateway();
        $started = $this->collectionIdentity($this->collector($gateway)->collect($renewal, $this->uuid(), new \DateTimeImmutable('2026-02-15T10:00:00Z')));
        $providerId = (string) $this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id=?', [$started['attempt_id']]);
        $gateway->payments[$providerId] = new ProviderPayment($providerId, 'canceled', false, 1000, 'RUB',
            ['payment_id' => $started['payment_id'], 'attempt_id' => $started['attempt_id'], 'invoice_id' => $renewal], null, null,
            new ProviderPaymentMethod('card-renewal-method-' . $suffix, 'bank_card', true), $failure);
        $this->yooKassaReconciliation($gateway)->reconcile(10, $this->uuid());
        self::assertSame($expectedStatus, $this->connection->fetchOne('SELECT status FROM commercial.saved_payment_methods WHERE provider_method_reference=?', ['card-renewal-method-' . $suffix]));
    }

    /** @return iterable<string,array{string,ProviderPaymentFailure,string}> */
    public static function savedMethodFailureCases(): iterable
    {
        yield 'normal decline remains usable' => ['decline', new ProviderPaymentFailure(ProviderPaymentFailure::RETRYABLE, 'insufficient_funds'), 'active'];
        yield 'expired card becomes unusable' => ['expired', new ProviderPaymentFailure(ProviderPaymentFailure::PAYMENT_METHOD_INVALID, 'expired_card', true), 'provider_unusable'];
    }

    /** @return array{string,string,string,string} */
    private function activeWithSavedCard(string $suffix): array
    {
        [$owner, $account, $profile, , , $subscription] = $this->invoiceContext($suffix, 1, 'month', 'RUB');
        $this->connection->executeStatement('UPDATE commercial.billing_profiles SET contact_name=?, contact_email=? WHERE id=?', ['Billing', $suffix . '@example.test', $profile]);
        $invoice = $this->issuer()->issue($owner, $account, $subscription, $suffix . '-initial', $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], $suffix . '-payment', $this->uuid());
        $providerId = $this->yooKassaProviderAccount($suffix);
        $accountConfig = new ProviderAccount($providerId, 'yookassa', 'shop-' . $suffix, 'env:YOOKASSA_SECRET_KEY', 'yookassa', 1, 'service', 'full_payment');
        $attempt = $this->attemptBeginner()->begin($payment['id'], $providerId, 'bank_card', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'initial-' . $suffix);
        $this->authoritativeProcessor()->process($accountConfig, new ProviderPayment('initial-' . $suffix, 'succeeded', true, 1000, 'RUB',
            ['payment_id' => $payment['id'], 'attempt_id' => $attempt['attempt_id'], 'invoice_id' => $invoice['id']], null,
            new \DateTimeImmutable('2026-01-15T10:00:00Z'), new ProviderPaymentMethod('card-' . $suffix, 'bank_card', true, 'Visa ** 1111', 'visa', '1111', 12, 2030, 'active')), $this->uuid());
        $renewal = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        return [$owner, $account, $subscription, $renewal['id']];
    }

    private function collector(FakePaymentProviderGateway $gateway): CollectRenewalInvoice
    {
        return new CollectRenewalInvoice($this->connection, $this->attemptBeginner(), $this->attemptBinder(), $this->attemptCompleter(),
            $this->authoritativeProcessor(), $gateway, new PaymentCreator($this->settlement()), $this->renewalLifecycle());
    }

    private function dunning(CollectRenewalInvoice $collector): ProcessRenewalDunning
    {
        return new ProcessRenewalDunning($this->connection, $this->dunningPolicy(), $collector,
            new ExpireSubscription($this->connection, $this->advancement(), 0));
    }

    /** @param array{state:string,invoice_id:string,payment_id?:string,attempt_id?:string} $result
     * @return array{payment_id:string,attempt_id:string}
     */
    private function collectionIdentity(array $result): array
    {
        if (!is_string($result['payment_id'] ?? null) || !is_string($result['attempt_id'] ?? null)) {
            self::fail('Collection did not return payment and attempt identity.');
        }
        return ['payment_id' => $result['payment_id'], 'attempt_id' => $result['attempt_id']];
    }
}
