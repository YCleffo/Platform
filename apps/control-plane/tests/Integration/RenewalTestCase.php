<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Billing\Application\IssueRenewalInvoice;
use ControlPlane\Subscription\Application\AdvanceSubscriptionPeriod;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;

abstract class RenewalTestCase extends PaymentTestCase
{
    /** @return array{string, string, string} owner, account, subscription */
    protected function activeRenewable(string $suffix, string $currency = 'USD'): array
    {
        [$owner, $account, $profile, , $price, $subscription] = $this->invoiceContext($suffix, 1, 'month', $currency);
        unset($profile, $price);
        $this->activatePaid($account, $subscription, new \DateTimeImmutable('2026-01-15T10:00:00Z'), $this->uuid());

        return [$owner, $account, $subscription];
    }

    protected function renewalIssuer(): IssueRenewalInvoice
    {
        return new IssueRenewalInvoice($this->connection, new SubscriptionPeriodCalculator());
    }

    protected function advancement(): AdvanceSubscriptionPeriod
    {
        return new AdvanceSubscriptionPeriod($this->connection);
    }
}
