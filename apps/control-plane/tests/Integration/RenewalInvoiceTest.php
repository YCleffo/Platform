<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class RenewalInvoiceTest extends RenewalTestCase
{
    public function testRenewalInvoiceFreezesTheNextContiguousPeriodAndIsIdempotent(): void
    {
        [, $account, $subscription] = $this->activeRenewable('renewal-invoice');
        $first = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        $second = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());

        self::assertSame($first['id'], $second['id']);
        self::assertSame('subscription_renewal', $first['billing_reason']);
        self::assertSame('2026-02-15', (new \DateTimeImmutable($first['service_period_start']))->format('Y-m-d'));
        self::assertSame('2026-03-15', (new \DateTimeImmutable($first['service_period_end']))->format('Y-m-d'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices WHERE subscription_id = ? AND billing_reason = 'subscription_renewal' AND status IN ('issued','paid')", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.renewal_invoice_issued' AND aggregate_id = ?", [$subscription]));
    }
}
