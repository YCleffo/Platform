<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class InvoiceConstraintsTest extends InvoiceTestCase
{
    public function testInvoiceAndLineValuesAndResourceBoundariesAreGuarded(): void
    {
        [, $account, $profile, , $price, $subscription] = $this->invoiceContext('invoice-constraints');
        $item = $this->connection->fetchOne('SELECT id FROM commercial.subscription_items WHERE subscription_id = ?', [$subscription]);
        self::assertIsString($item);

        $this->expectDatabaseRejection(fn () => $this->insertDraftInvoice($account, $subscription, $profile, -1));
        $this->expectDatabaseRejection(fn () => $this->insertDraftInvoice($account, $subscription, $profile, 0, 'usd'));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement(<<<'SQL'
INSERT INTO commercial.invoices (commercial_account_id, subscription_id, billing_profile_id, billing_reason, currency, total_minor, status, bill_to_legal_name, bill_to_country_code, bill_to_address)
VALUES (?, ?, ?, 'subscription_create', 'USD', 0, 'unknown', 'Snapshot LLC', 'US', '{}'::jsonb)
SQL, [$account, $subscription, $profile]));

        $invoice = $this->insertDraftInvoice($account, $subscription, $profile);
        foreach ([[0, 1000, 0], [1, -1, 0], [1, 1000, -1]] as [$quantity, $unit, $total]) {
            $this->expectDatabaseRejection(fn () => $this->connection->executeStatement(<<<'SQL'
INSERT INTO commercial.invoice_lines (invoice_id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name, billing_period, description, quantity, unit_amount_minor, total_minor)
VALUES (?, ?, ?, 'invoice.constraints.plan', 1, 'Constraints', 'month', 'Constraints — monthly subscription', ?, ?, ?)
SQL, [$invoice, $item, $price, $quantity, $unit, $total]));
        }

        [, $otherAccount, $otherProfile, , $otherPrice, $otherSubscription] = $this->invoiceContext('invoice-constraints-other');
        $otherItem = $this->connection->fetchOne('SELECT id FROM commercial.subscription_items WHERE subscription_id = ?', [$otherSubscription]);
        self::assertIsString($otherItem);
        $this->expectDatabaseRejection(fn () => $this->insertDraftInvoice($account, $otherSubscription, $otherProfile));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement(<<<'SQL'
INSERT INTO commercial.invoice_lines (invoice_id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name, billing_period, description, quantity, unit_amount_minor, total_minor)
VALUES (?, ?, ?, 'invoice.other.plan', 1, 'Other', 'month', 'Other — monthly subscription', 1, 1000, 1000)
SQL, [$invoice, $otherItem, $otherPrice]));
        self::assertNotSame($account, $otherAccount);
    }

    public function testIssuedTotalsLifecycleAndSnapshotsAreDatabaseGuarded(): void
    {
        [, $account, $profile, , $price, $subscription] = $this->invoiceContext('invoice-issued-constraints');
        $item = $this->connection->fetchOne('SELECT id FROM commercial.subscription_items WHERE subscription_id = ?', [$subscription]);
        self::assertIsString($item);
        $invoice = $this->insertDraftInvoice($account, $subscription, $profile, 999);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'issued', issued_at = now() WHERE id = ?", [$invoice]));
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO commercial.invoice_lines (invoice_id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name, billing_period, description, quantity, unit_amount_minor, total_minor)
VALUES (?, ?, ?, 'invoice.issued.plan', 1, 'Issued', 'month', 'Issued — monthly subscription', 1, 1000, 1000)
SQL, [$invoice, $item, $price]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'issued', issued_at = now() WHERE id = ?", [$invoice]));
        $this->connection->executeStatement("UPDATE commercial.invoices SET total_minor = 1000, status = 'issued', issued_at = now() WHERE id = ?", [$invoice]);

        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'draft', issued_at = NULL WHERE id = ?", [$invoice]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET total_minor = 999 WHERE id = ?", [$invoice]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET bill_to_legal_name = 'Changed LLC' WHERE id = ?", [$invoice]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement(<<<'SQL'
INSERT INTO commercial.invoice_lines (invoice_id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name, billing_period, description, quantity, unit_amount_minor, total_minor)
VALUES (?, ?, ?, 'invoice.extra.plan', 1, 'Extra', 'month', 'Extra — monthly subscription', 1, 0, 0)
SQL, [$invoice, $item, $price]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('UPDATE commercial.invoice_lines SET quantity = 2 WHERE invoice_id = ?', [$invoice]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.invoice_lines WHERE invoice_id = ?', [$invoice]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.invoices WHERE id = ?', [$invoice]));

        $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'void', voided_at = now(), updated_at = now() WHERE id = ?", [$invoice]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'issued', voided_at = NULL WHERE id = ?", [$invoice]));

        $paid = $this->insertDraftInvoice($account, $subscription, $profile, 1000);
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO commercial.invoice_lines (invoice_id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name, billing_period, description, quantity, unit_amount_minor, total_minor)
VALUES (?, ?, ?, 'invoice.paid.plan', 1, 'Paid', 'month', 'Paid monthly subscription', 1, 1000, 1000)
SQL, [$paid, $item, $price]);
        $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'issued', issued_at = now() WHERE id = ?", [$paid]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'paid', paid_at = now(), updated_at = now() WHERE id = ?", [$paid]));
        $payment = $this->connection->fetchOne("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) VALUES (?, ?, 1000, 'USD', 'pending') RETURNING id", [$paid, $account]);
        self::assertIsString($payment);
        $this->connection->executeStatement("UPDATE commercial.payments SET status = 'succeeded', succeeded_at = now() WHERE id = ?", [$payment]);
        $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'paid', paid_at = now(), updated_at = now() WHERE id = ?", [$paid]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'void', paid_at = NULL, voided_at = now() WHERE id = ?", [$paid]));
    }
}
