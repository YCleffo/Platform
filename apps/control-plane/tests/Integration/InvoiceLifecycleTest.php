<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Subscription\Application\CancelSubscription;

final class InvoiceLifecycleTest extends InvoiceTestCase
{
    public function testCancellingDraftSubscriptionVoidsItsIssuedInvoice(): void
    {
        [$owner, $account, , , , $subscription] = $this->invoiceContext('invoice-draft-cancel');
        $invoice = $this->issuer()->issue($owner, $account, $subscription, 'invoice-draft-cancel-issue', $this->uuid());

        $result = $this->canceller()->cancel($owner, $account, $subscription, 'invoice-draft-cancel', $this->uuid());
        $this->canceller()->cancel($owner, $account, $subscription, 'invoice-draft-cancel', $this->uuid());
        $this->canceller()->cancel($owner, $account, $subscription, 'invoice-draft-cancel-retry', $this->uuid());

        self::assertSame('cancelled', $result['status']);
        $stored = $this->connection->fetchAssociative('SELECT status, voided_at FROM commercial.invoices WHERE id = ?', [$invoice['id']]);
        self::assertIsArray($stored);
        self::assertSame('void', $stored['status']);
        self::assertIsString($stored['voided_at']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'invoice.voided' AND resource_id = ?", [$invoice['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'invoice.voided' AND aggregate_id = ?", [$invoice['id']]));
    }

    public function testCancellingActiveSubscriptionPreservesHistoricalInvoice(): void
    {
        [$owner, $account, , , , $subscription] = $this->invoiceContext('invoice-active-cancel');
        $invoice = $this->issuer()->issue($owner, $account, $subscription, 'invoice-active-cancel-issue', $this->uuid());
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());

        $this->canceller()->cancel($owner, $account, $subscription, 'invoice-active-cancel', $this->uuid());

        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice['id']]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'invoice.voided' AND resource_id = ?", [$invoice['id']]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'invoice.voided' AND aggregate_id = ?", [$invoice['id']]));
    }

    private function canceller(): CancelSubscription
    {
        return new CancelSubscription($this->connection, new AccountAuthorization($this->connection));
    }
}
