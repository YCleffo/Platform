<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class PaymentConstraintsTest extends PaymentTestCase
{
    public function testPaymentMoneyIdentityLifecycleAndDeletionAreGuarded(): void
    {
        [, $account, , , , , $invoice] = $this->issuedInvoiceContext('payment-constraints');
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'paid', paid_at = now() WHERE id = ?", [$invoice['id']]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) VALUES (?, ?, -1, 'USD', 'pending')", [$invoice['id'], $account]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) VALUES (?, ?, 1000, 'usd', 'pending')", [$invoice['id'], $account]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) VALUES (?, ?, 1000, 'USD', 'unknown')", [$invoice['id'], $account]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) VALUES (?, ?, 999, 'USD', 'pending')", [$invoice['id'], $account]));
        [, $foreignAccount] = $this->readyAccount('payment-constraints-foreign');
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) VALUES (?, ?, 1000, 'USD', 'pending')", [$invoice['id'], $foreignAccount]));

        $payment = $this->connection->fetchOne("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) VALUES (?, ?, 1000, 'USD', 'pending') RETURNING id", [$invoice['id'], $account]);
        self::assertIsString($payment);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) VALUES (?, ?, 1000, 'USD', 'pending')", [$invoice['id'], $account]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('UPDATE commercial.payments SET amount_minor = 999 WHERE id = ?', [$payment]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.payments SET status = 'cancelled' WHERE id = ?", [$payment]));
        $this->connection->executeStatement("UPDATE commercial.payments SET status = 'cancelled', cancelled_at = now(), updated_at = now() WHERE id = ?", [$payment]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.payments SET status = 'pending', cancelled_at = NULL WHERE id = ?", [$payment]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.payments WHERE id = ?', [$payment]));
    }

    public function testAttemptUniquenessBindingTerminalStateAndDeletionAreGuarded(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedInvoiceContext('attempt-constraints');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'attempt-constraints-payment', $this->uuid());
        $provider = $this->providerAccount('constraints');
        $attempt = $this->connection->fetchOne("INSERT INTO commercial.payment_attempts (payment_id, provider_account_id, method_type, provider_request_key, status) VALUES (?, ?, 'card', 'request-one', 'pending') RETURNING id", [$payment['id'], $provider]);
        self::assertIsString($attempt);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payment_attempts (payment_id, provider_account_id, method_type, provider_request_key, status) VALUES (?, ?, 'card', 'request-two', 'pending')", [$payment['id'], $provider]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payment_attempts (payment_id, provider_account_id, method_type, provider_request_key, status, completed_at) VALUES (?, ?, 'card', 'request-one', 'failed', now())", [$payment['id'], $provider]));
        $this->connection->executeStatement("UPDATE commercial.payment_attempts SET provider_payment_id = 'provider-one' WHERE id = ?", [$attempt]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.payment_attempts SET provider_payment_id = 'provider-two' WHERE id = ?", [$attempt]));
        $this->connection->executeStatement("UPDATE commercial.payment_attempts SET status = 'failed', failure_code = 'declined', completed_at = now() WHERE id = ?", [$attempt]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.payment_attempts SET status = 'succeeded', failure_code = NULL WHERE id = ?", [$attempt]));
        $second = $this->connection->fetchOne("INSERT INTO commercial.payment_attempts (payment_id, provider_account_id, method_type, provider_request_key, status) VALUES (?, ?, 'card', 'request-three', 'pending') RETURNING id", [$payment['id'], $provider]);
        self::assertIsString($second);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.payment_attempts SET provider_payment_id = 'provider-one' WHERE id = ?", [$second]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.payment_attempts WHERE id = ?', [$attempt]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET provider_code = 'changed-provider' WHERE id = ?", [$provider]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.payment_provider_accounts WHERE id = ?', [$provider]));
    }
}
