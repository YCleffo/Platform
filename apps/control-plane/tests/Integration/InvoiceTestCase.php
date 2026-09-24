<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\GetInvoice;
use ControlPlane\Billing\Application\IssueInvoice;

abstract class InvoiceTestCase extends SubscriptionTestCase
{
    /** @return array{string, string, string, string, string, string} */
    protected function invoiceContext(string $suffix, int $quantity = 1, string $period = 'month', string $currency = 'USD'): array
    {
        [$owner, $account, $profile] = $this->readyAccount($suffix);
        [$plan, $price] = $this->planPrice($suffix . '-plan', $period, $currency);
        $subscription = $this->draftSubscription($account, $profile, [$price], $quantity);

        return [$owner, $account, $profile, $plan, $price, $subscription];
    }

    protected function issuer(): IssueInvoice
    {
        return new IssueInvoice($this->connection, new AccountAuthorization($this->connection));
    }

    protected function invoiceReader(): GetInvoice
    {
        return new GetInvoice($this->connection, new AccountAuthorization($this->connection));
    }

    protected function insertDraftInvoice(string $account, string $subscription, string $profile, int $total = 0, string $currency = 'USD'): string
    {
        $invoice = $this->connection->fetchOne(<<<'SQL'
INSERT INTO commercial.invoices (
 commercial_account_id, subscription_id, billing_profile_id, billing_reason, currency, total_minor,
 bill_to_legal_name, bill_to_country_code, bill_to_address
) VALUES (?, ?, ?, 'subscription_create', ?, ?, 'Snapshot LLC', 'US', '{}'::jsonb)
RETURNING id
SQL, [$account, $subscription, $profile, $currency, $total]);
        self::assertIsString($invoice);

        return $invoice;
    }
}
