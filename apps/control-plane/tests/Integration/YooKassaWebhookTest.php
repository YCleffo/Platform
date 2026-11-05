<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Payment\Provider\ProviderPaymentMethod;
use ControlPlane\Tests\Support\FakePaymentProviderGateway;

final class YooKassaWebhookTest extends PaymentTestCase
{
    public function testOutOfOrderCanceledNoticeUsesAuthoritativeSucceededStateOnce(): void
    {
        [$owner, $account, , , , $subscription, $invoice] = $this->issuedRubInvoiceContext('yk-webhook-reordered');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-reordered-create', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $providerId = 'yk-reordered-provider-payment';
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerId);
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerId] = $this->providerPayment($providerId, 'succeeded', $payment['id'], $attempt['attempt_id'], $invoice['id'], 1000);
        $webhook = $this->yooKassaWebhook($gateway);

        self::assertSame(['status' => 'processed'], $webhook->receive($this->webhookBody('payment.canceled', $providerId), 'application/json', $this->uuid()));
        self::assertSame(['status' => 'processed'], $webhook->receive($this->webhookBody('payment.succeeded', $providerId), 'application/json', $this->uuid()));

        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice['id']]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'payment.succeeded' AND resource_id = ?", [$payment['id']]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_provider_events WHERE provider_account_id = ?', [$provider]));
    }

    public function testAuthoritativeSuccessSettlesOnceAndDeduplicatesWebhook(): void
    {
        [$owner, $account, , , , $subscription, $invoice] = $this->issuedRubInvoiceContext('yk-webhook-success');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-webhook-create', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $providerId = 'yk-webhook-payment';
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerId);
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerId] = $this->providerPayment($providerId, 'succeeded', $payment['id'], $attempt['attempt_id'], $invoice['id'], 1000);
        $webhook = $this->yooKassaWebhook($gateway);
        $raw = $this->webhookBody('payment.succeeded', $providerId);

        $webhook->receive($raw, 'application/json', $this->uuid());
        $webhook->receive($raw, 'application/json', $this->uuid());

        self::assertCount(1, $gateway->getCalls);
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice['id']]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_provider_events WHERE provider_account_id = ?', [$provider]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'payment.succeeded' AND resource_id = ?", [$payment['id']]));
    }

    public function testWebhookClaimCannotSettleWhenAuthoritativeStateIsPending(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('yk-webhook-forged');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-forged-create', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $providerId = 'yk-forged-provider';
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerId);
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerId] = $this->providerPayment($providerId, 'pending', $payment['id'], $attempt['attempt_id'], $invoice['id'], 1000);

        try {
            $this->yooKassaWebhook($gateway)->receive($this->webhookBody('payment.succeeded', $providerId), 'application/json', $this->uuid());
            self::fail('Non-final authoritative state must not settle.');
        } catch (PaymentProviderException $exception) {
            self::assertSame('provider_state_not_final', $exception->errorCode);
        }
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('failed', $this->connection->fetchOne('SELECT status FROM commercial.payment_provider_events WHERE provider_account_id = ?', [$provider]));
    }

    public function testAuthoritativeSavedCardCreatesOneSafeAccountScopedMethod(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('yk-saved-card');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-saved-card-create', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'bank_card', $this->uuid());
        $providerId = 'yk-saved-card-payment';
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerId);
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerId] = new ProviderPayment(
            $providerId, 'succeeded', true, 1000, 'RUB',
            ['payment_id' => $payment['id'], 'attempt_id' => $attempt['attempt_id'], 'invoice_id' => $invoice['id']],
            null, new \DateTimeImmutable('2026-09-26T12:00:00Z'),
            new ProviderPaymentMethod('saved-card-1', 'bank_card', true, 'Bank card *1234', 'MasterCard', '1234', 12, 2030, 'active'),
        );
        $webhook = $this->yooKassaWebhook($gateway);
        $raw = $this->webhookBody('payment.succeeded', $providerId);
        $webhook->receive($raw, 'application/json', $this->uuid());
        $webhook->receive($raw, 'application/json', $this->uuid());

        $method = $this->connection->fetchAssociative('SELECT method_type, display_title, card_brand, last4, expiry_month, expiry_year, is_default FROM commercial.saved_payment_methods WHERE commercial_account_id = ?', [$account]);
        self::assertIsArray($method);
        self::assertSame('bank_card', $method['method_type']);
        self::assertSame('1234', $method['last4']);
        self::assertSame(12, (int) $method['expiry_month']);
        self::assertSame(2030, (int) $method['expiry_year']);
        self::assertTrue((bool) $method['is_default']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.saved_payment_methods WHERE commercial_account_id = ?', [$account]));
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM commercial.saved_payment_methods WHERE provider_method_reference LIKE '%saved-card-1%'") === false);
        // A subsequent authoritative saved-method snapshot must persist false as a real
        // PostgreSQL boolean and preserve the customer's existing default.
        $recorder = new \ControlPlane\Payment\Application\RecordSavedPaymentMethod($this->connection);
        $providerAccount = (new \ControlPlane\Payment\Provider\YooKassa\YooKassaProviderAccountLocator($this->connection))->active();
        $another = new ProviderPayment($providerId, 'succeeded', true, 1000, 'RUB', [], null,
            new \DateTimeImmutable('2026-09-26T12:00:00Z'), new ProviderPaymentMethod('saved-card-2', 'bank_card', true));
        $recorder->record($providerAccount, $another, $attempt['attempt_id']);
        $recorder->record($providerAccount, $another, $attempt['attempt_id']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.saved_payment_methods WHERE commercial_account_id=?', [$account]));
        self::assertFalse((bool) $this->connection->fetchOne("SELECT is_default FROM commercial.saved_payment_methods WHERE provider_method_reference='saved-card-2'"));
        self::assertSame('saved-card-1', $this->connection->fetchOne('SELECT provider_method_reference FROM commercial.saved_payment_methods WHERE commercial_account_id=? AND is_default', [$account]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.saved_payment_methods WHERE commercial_account_id = ?', [$account]));
    }

    public function testMoneyMismatchFailsClosedWithoutSettlement(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('yk-money-mismatch');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-money-create', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $providerId = 'yk-money-provider';
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerId);
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerId] = $this->providerPayment($providerId, 'succeeded', $payment['id'], $attempt['attempt_id'], $invoice['id'], 999);

        try {
            $this->yooKassaWebhook($gateway)->receive($this->webhookBody('payment.succeeded', $providerId), 'application/json', $this->uuid());
            self::fail('Money mismatch must fail closed.');
        } catch (PaymentProviderException $exception) {
            self::assertSame('provider_state_mismatch', $exception->errorCode);
        }
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('issued', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice['id']]));
    }

    public function testCrashWindowBindsFromAuthoritativeMetadataAndSettles(): void
    {
        [$owner, $account, , , , $subscription, $invoice] = $this->issuedRubInvoiceContext('yk-crash-recovery');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-crash-create', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $providerId = 'yk-crash-provider';
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerId] = $this->providerPayment($providerId, 'succeeded', $payment['id'], $attempt['attempt_id'], $invoice['id'], 1000);

        $this->yooKassaWebhook($gateway)->receive($this->webhookBody('payment.succeeded', $providerId), 'application/json', $this->uuid());

        self::assertSame($providerId, $this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
    }

    public function testCanceledProviderClosesAttemptButLeavesPaymentRetryable(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('yk-canceled');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-canceled-create', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $providerId = 'yk-canceled-provider';
        $this->attemptBinder()->bind($attempt['attempt_id'], $providerId);
        $gateway = new FakePaymentProviderGateway();
        $gateway->payments[$providerId] = $this->providerPayment($providerId, 'canceled', $payment['id'], $attempt['attempt_id'], $invoice['id'], 1000);

        $this->yooKassaWebhook($gateway)->receive($this->webhookBody('payment.canceled', $providerId), 'application/json', $this->uuid());

        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        $retry = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        self::assertNotSame($attempt['attempt_id'], $retry['attempt_id']);
    }

    public function testIgnoredEventStoresHashButNotRawPayloadAndHistoryCannotBeDeleted(): void
    {
        $provider = $this->yooKassaProviderAccount();
        $raw = $this->webhookBody('payment.waiting_for_capture', 'yk-ignored-secret-marker');
        $gateway = new FakePaymentProviderGateway();

        self::assertSame(['status' => 'ignored'], $this->yooKassaWebhook($gateway)->receive($raw, 'application/json; charset=utf-8', $this->uuid()));
        $event = $this->connection->fetchAssociative("SELECT status, octet_length(payload_sha256) AS hash_length FROM commercial.payment_provider_events WHERE provider_account_id = ?", [$provider]);
        self::assertIsArray($event);
        self::assertSame('ignored', $event['status']);
        self::assertSame(32, (int) $event['hash_length']);
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM commercial.payment_provider_events WHERE payload_sha256::text LIKE '%secret-marker%'") !== false);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.payment_provider_events WHERE provider_account_id = ?', [$provider]));
    }

    private function providerPayment(string $providerId, string $status, string $paymentId, string $attemptId, string $invoiceId, int $amount): ProviderPayment
    {
        return new ProviderPayment(
            $providerId,
            $status,
            $status === 'succeeded',
            $amount,
            'RUB',
            ['payment_id' => $paymentId, 'attempt_id' => $attemptId, 'invoice_id' => $invoiceId],
            $status === 'pending' ? 'https://yookassa.test/confirmation/' . $attemptId : null,
            $status === 'succeeded' ? new \DateTimeImmutable('2026-09-26T12:00:00Z') : null,
        );
    }

    private function webhookBody(string $event, string $providerId): string
    {
        return json_encode(['type' => 'notification', 'event' => $event, 'object' => ['id' => $providerId]], JSON_THROW_ON_ERROR);
    }
}
