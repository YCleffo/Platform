<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class FiscalReceiptConstraintsTest extends PaymentTestCase
{
    public function testProviderFiscalConfigurationIsAllOrNothing(): void
    {
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref, receipt_mode, status) VALUES ('yookassa', 'bad-shop', 'env:SECRET', 'yookassa', 'active')"));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref, receipt_mode, receipt_vat_code, receipt_payment_subject, receipt_payment_mode, status) VALUES ('yookassa', 'bad-vat-shop', 'env:SECRET', 'yookassa', 13, 'service', 'full_payment', 'active')"));
    }

    public function testReceiptIdentityItemsAndLifecycleAreDatabaseGuarded(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('receipt-constraints');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'receipt-constraints-payment', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-constraints');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());
        $receipt = $this->connection->fetchAssociative('SELECT id FROM commercial.receipts WHERE payment_id = ?', [$payment['id']]);
        self::assertIsArray($receipt);
        $item = $this->connection->fetchOne('SELECT id FROM commercial.receipt_items WHERE receipt_id = ?', [$receipt['id']]);
        self::assertIsString($item);

        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('UPDATE commercial.receipts SET amount_minor = amount_minor + 1 WHERE id = ?', [$receipt['id']]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.receipts SET status = 'succeeded' WHERE id = ?", [$receipt['id']]));
        $this->connection->executeStatement("UPDATE commercial.receipts SET external_receipt_id = 'provider-receipt-one', last_attempted_at = now(), updated_at = now() WHERE id = ?", [$receipt['id']]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.receipts SET external_receipt_id = 'provider-receipt-two' WHERE id = ?", [$receipt['id']]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('UPDATE commercial.receipt_items SET description = ? WHERE id = ?', ['changed', $item]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.receipt_items WHERE id = ?', [$item]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.receipts WHERE id = ?', [$receipt['id']]));
    }
}
