<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\IssueInvoice;
use ControlPlane\Payment\Application\BeginPaymentAttempt;
use ControlPlane\Payment\Application\BindPaymentAttemptProviderReference;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\SettlePayment;
use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PaymentConcurrencyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('CONTROL_DATABASE_URL');
        $parts = is_string($url) ? parse_url($url) : false;
        if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1' || !is_array($parts)
            || ($parts['host'] ?? '') !== 'control-postgres-test' || ($parts['path'] ?? '') !== '/platform_commercial_test') {
            throw new \RuntimeException('Payment concurrency tests require the isolated Commercial test database.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testConcurrentCreationProducesOnePayment(): void
    {
        [$owner, $account, , $invoice] = $this->issuedFixture('payment-create-race');
        $a = implode('|', [$owner, $account, 'create-race-a-' . $account, Uuid::v7()->toRfc4122()]);
        $b = implode('|', [$owner, $account, 'create-race-b-' . $account, Uuid::v7()->toRfc4122()]);
        self::assertSame([0, 0], $this->race('create_payment', $invoice, $a, 'create_payment', $invoice, $b));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id = ?', [$invoice]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events ae JOIN commercial.payments p ON p.id::text = ae.resource_id WHERE ae.action = 'payment.created' AND p.invoice_id = ?", [$invoice]));
    }

    public function testConcurrentSuccessSettlesOnce(): void
    {
        [$owner, $account, $subscription, $invoice] = $this->issuedFixture('payment-success-race');
        $payment = $this->creator()->create($owner, $account, $invoice, 'success-race-payment-' . $account, Uuid::v7()->toRfc4122());
        $provider = $this->provider('success-race');
        $attempt = (new BeginPaymentAttempt($this->connection))->begin($payment['id'], $provider, 'card', Uuid::v7()->toRfc4122());
        (new BindPaymentAttemptProviderReference($this->connection))->bind($attempt['attempt_id'], 'provider-' . $attempt['attempt_id']);
        $extra = implode('|', ['succeeded', '', '2026-09-25T15:00:00Z', Uuid::v7()->toRfc4122()]);

        self::assertSame([0, 0], $this->race('complete_payment_attempt', $attempt['attempt_id'], $extra, 'complete_payment_attempt', $attempt['attempt_id'], $extra));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'payment.succeeded' AND resource_id = ?", [$payment['id']]));
    }

    public function testConcurrentYooKassaSettlementCreatesOneReceiptSnapshot(): void
    {
        [$owner, $account, , $invoice] = $this->issuedFixture('receipt-success-race', 'RUB');
        $payment = $this->creator()->create($owner, $account, $invoice, 'receipt-race-payment-' . $account, Uuid::v7()->toRfc4122());
        $provider = $this->yooKassaProvider('receipt-race');
        $attempt = (new BeginPaymentAttempt($this->connection))->begin($payment['id'], $provider, 'sbp', Uuid::v7()->toRfc4122());
        (new BindPaymentAttemptProviderReference($this->connection))->bind($attempt['attempt_id'], 'provider-' . $attempt['attempt_id']);
        $extra = implode('|', ['succeeded', '', '2026-09-26T11:00:00Z', Uuid::v7()->toRfc4122()]);

        self::assertSame([0, 0], $this->race('complete_payment_attempt', $attempt['attempt_id'], $extra, 'complete_payment_attempt', $attempt['attempt_id'], $extra));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.receipts WHERE payment_id = ?', [$payment['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.receipt_items ri JOIN commercial.receipts r ON r.id = ri.receipt_id WHERE r.payment_id = ?', [$payment['id']]));
        $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status = 'disabled', updated_at = now() WHERE id = ?", [$provider]);
    }

    public function testConcurrentAttemptStartCreatesOnePendingAttempt(): void
    {
        [$owner, $account, , $invoice] = $this->issuedFixture('payment-attempt-race');
        $payment = $this->creator()->create($owner, $account, $invoice, 'attempt-race-payment-' . $account, Uuid::v7()->toRfc4122());
        $provider = $this->provider('attempt-race');
        $extra = implode('|', [$provider, 'card', Uuid::v7()->toRfc4122()]);

        self::assertSame([0, 2], $this->race('begin_payment_attempt', $payment['id'], $extra, 'begin_payment_attempt', $payment['id'], $extra));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_attempts WHERE payment_id = ? AND status = 'pending'", [$payment['id']]));
    }

    public function testSuccessAndCancellationNeverVoidSucceededMoney(): void
    {
        [$owner, $account, $subscription, $invoice] = $this->issuedFixture('payment-cancel-race');
        $payment = $this->creator()->create($owner, $account, $invoice, 'cancel-race-payment-' . $account, Uuid::v7()->toRfc4122());
        $provider = $this->provider('cancel-race');
        $attempt = (new BeginPaymentAttempt($this->connection))->begin($payment['id'], $provider, 'card', Uuid::v7()->toRfc4122());
        (new BindPaymentAttemptProviderReference($this->connection))->bind($attempt['attempt_id'], 'provider-' . $attempt['attempt_id']);
        $complete = implode('|', ['succeeded', '', '2026-09-25T16:00:00Z', Uuid::v7()->toRfc4122()]);
        $cancel = implode('|', [$owner, $account, 'success-cancel-race-' . $account, Uuid::v7()->toRfc4122()]);

        $results = $this->race('complete_payment_attempt', $attempt['attempt_id'], $complete, 'cancel_subscription', $subscription, $cancel);

        self::assertTrue($results === [0, 2] || $results === [0, 0]);
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice]));
        self::assertNotSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'invoice.voided' AND resource_id = ?", [$invoice]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'payment.succeeded' AND resource_id = ?", [$payment['id']]));
    }

    public function testWebhookAndReconciliationStyleCompletionSettlesOnce(): void
    {
        [$owner, $account, $subscription, $invoice] = $this->issuedFixture('provider-complete-race', 'RUB');
        $payment = $this->creator()->create($owner, $account, $invoice, 'provider-race-payment-' . $account, Uuid::v7()->toRfc4122());
        $provider = $this->provider('provider-complete');
        $attempt = (new BeginPaymentAttempt($this->connection))->begin($payment['id'], $provider, 'sbp', Uuid::v7()->toRfc4122());
        $providerPaymentId = 'provider-race-' . $attempt['attempt_id'];
        (new BindPaymentAttemptProviderReference($this->connection))->bind($attempt['attempt_id'], $providerPaymentId);
        $extra = implode('|', [$providerPaymentId, 'succeeded', '2026-09-26T14:00:00Z', Uuid::v7()->toRfc4122()]);

        self::assertSame([0, 0], $this->race('process_provider_payment', $attempt['attempt_id'], $extra, 'process_provider_payment', $attempt['attempt_id'], $extra));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'payment.succeeded' AND resource_id = ?", [$payment['id']]));
    }

    public function testCheckoutBindingAndWebhookRecoveryCanRaceIdempotently(): void
    {
        [$owner, $account, $subscription, $invoice] = $this->issuedFixture('provider-bind-race', 'RUB');
        $payment = $this->creator()->create($owner, $account, $invoice, 'provider-bind-payment-' . $account, Uuid::v7()->toRfc4122());
        $provider = $this->provider('provider-bind');
        $attempt = (new BeginPaymentAttempt($this->connection))->begin($payment['id'], $provider, 'sbp', Uuid::v7()->toRfc4122());
        $providerPaymentId = 'provider-bind-' . $attempt['attempt_id'];
        $process = implode('|', [$providerPaymentId, 'succeeded', '2026-09-26T15:00:00Z', Uuid::v7()->toRfc4122()]);

        self::assertSame([0, 0], $this->race('bind_payment_attempt', $attempt['attempt_id'], $providerPaymentId, 'process_provider_payment', $attempt['attempt_id'], $process));
        self::assertSame($providerPaymentId, $this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
    }

    /** @return array{string, string, string, string} */
    private function issuedFixture(string $prefix, string $currency = 'USD'): array
    {
        $suffix = bin2hex(random_bytes(6));
        $email = $prefix . '-' . $suffix . '@example.test';
        $this->connection->beginTransaction();
        $owner = $this->connection->fetchOne("INSERT INTO commercial.control_users (email, email_normalized, password_hash, status, email_verified_at, password_changed_at) VALUES (?, ?, '!race!', 'active', now(), now()) RETURNING id", [$email, $email]);
        $account = $this->connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Payment Race') RETURNING id", [$prefix . '-' . $suffix]);
        self::assertIsString($owner);
        self::assertIsString($account);
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$owner, $account]);
        $profile = $this->connection->fetchOne("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, contact_name, contact_email, is_default) VALUES (?, 'Race LLC', 'US', 'Race Billing', ?, true) RETURNING id", [$account, $email]);
        self::assertIsString($profile);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'catalog_selection_pending' WHERE id = ?", [$account]);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription' WHERE id = ?", [$account]);
        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, 'Payment Race') RETURNING id", [$prefix . '-plan-' . $suffix]);
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, ?, 1000, 'month') RETURNING id", [$plan, $currency]);
        self::assertIsString($price);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $subscription = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$account, $profile]);
        self::assertIsString($subscription);
        $this->connection->executeStatement("INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, 1, now())", [$subscription, $price]);
        $this->connection->commit();
        $invoice = (new IssueInvoice($this->connection, new AccountAuthorization($this->connection)))->issue($owner, $account, $subscription, 'race-invoice-' . $account, Uuid::v7()->toRfc4122());

        return [$owner, $account, $subscription, $invoice['id']];
    }

    private function provider(string $suffix): string
    {
        $id = $this->connection->fetchOne("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref) VALUES (?, ?, ?) RETURNING id", ['race-' . $suffix . '-' . bin2hex(random_bytes(4)), 'merchant-' . $suffix, 'payments/race/' . $suffix]);
        self::assertIsString($id);

        return $id;
    }

    private function yooKassaProvider(string $suffix): string
    {
        $id = $this->connection->fetchOne("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref, receipt_mode, receipt_vat_code, receipt_payment_subject, receipt_payment_mode) VALUES ('yookassa', ?, 'env:YOOKASSA_SECRET_KEY', 'yookassa', 1, 'service', 'full_payment') RETURNING id", ['race-shop-' . $suffix . '-' . bin2hex(random_bytes(4))]);
        self::assertIsString($id);

        return $id;
    }

    private function creator(): CreatePayment
    {
        $activation = new ActivateSubscription($this->connection, new SubscriptionPeriodCalculator());

        return new CreatePayment($this->connection, new AccountAuthorization($this->connection), new SettlePayment($activation));
    }

    /** @return list<int> */
    private function race(string $modeA, string $valueA, string $extraA, string $modeB, string $valueB, string $extraB): array
    {
        $start = (string) (microtime(true) + 0.4);
        $workers = [$this->launch($modeA, $valueA, $extraA, $start), $this->launch($modeB, $valueB, $extraB, $start)];
        $results = [$this->finish($workers[0]), $this->finish($workers[1])];
        sort($results);

        return $results;
    }

    /** @return array{resource, array<resource>} */
    private function launch(string $mode, string $value, string $extra, string $start): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/race-worker.php', $mode, $value, $extra, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not launch payment race worker.');
        }

        return [$process, $pipes];
    }

    /** @param array{resource, array<resource>} $worker */
    private function finish(array $worker): int
    {
        [$process, $pipes] = $worker;
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        return proc_close($process);
    }
}
