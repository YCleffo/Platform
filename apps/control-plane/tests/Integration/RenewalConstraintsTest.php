<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class RenewalConstraintsTest extends RenewalTestCase
{
    public function testIssuedRenewalPeriodAndReasonAreImmutable(): void
    {
        [, $account, $subscription] = $this->activeRenewable('renewal-constraints');
        $renewal = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET service_period_end = service_period_end + interval '1 day' WHERE id = ?", [$renewal['id']]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.invoices SET billing_reason = 'subscription_create' WHERE id = ?", [$renewal['id']]));
    }
}
