<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\IssueInvoice;
use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;

abstract class SubscriptionTestCase extends DatabaseTestCase
{
    /** @return array{string, string, string} */
    protected function readyAccount(string $suffix): array
    {
        $owner = $this->user($suffix . '-owner@example.test');
        $account = $this->connection->fetchOne('INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, ?) RETURNING id', [$suffix, ucfirst($suffix)]);
        self::assertIsString($account);
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$owner, $account]);
        $profile = $this->connection->fetchOne("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, is_default) VALUES (?, 'Example LLC', 'US', true) RETURNING id", [$account]);
        self::assertIsString($profile);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'catalog_selection_pending' WHERE id = ?", [$account]);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription' WHERE id = ?", [$account]);

        return [$owner, $account, $profile];
    }

    protected function feature(string $code, string $type, string $usageMode = 'none'): string
    {
        $id = $this->connection->fetchOne('INSERT INTO commercial.features (code, value_type, usage_mode) VALUES (?, ?, ?) RETURNING id', [$code, $type, $usageMode]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $featureValues feature ID to value
     * @return array{string, string}
     */
    protected function planPrice(string $code, string $period = 'month', string $currency = 'USD', string $status = 'published', array $featureValues = []): array
    {
        $plan = $this->connection->fetchOne('INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, ?) RETURNING id', [$code, ucfirst($code)]);
        self::assertIsString($plan);
        $price = $this->connection->fetchOne('INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, ?, 1000, ?) RETURNING id', [$plan, $currency, $period]);
        self::assertIsString($price);
        foreach ($featureValues as $featureId => $value) {
            $this->connection->executeStatement('INSERT INTO commercial.plan_features (plan_id, feature_id, value) VALUES (?, ?, ?::jsonb)', [$plan, $featureId, json_encode($value, JSON_THROW_ON_ERROR)]);
        }
        if ($status !== 'draft') {
            $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        }
        if ($status === 'retired') {
            $this->connection->executeStatement("UPDATE commercial.plans SET status = 'retired', retired_at = now() WHERE id = ?", [$plan]);
        }

        return [$plan, $price];
    }

    /** @param list<string> $priceIds */
    protected function draftSubscription(string $accountId, string $profileId, array $priceIds, int $quantity = 1): string
    {
        $subscription = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$accountId, $profileId]);
        self::assertIsString($subscription);
        foreach ($priceIds as $priceId) {
            $this->connection->executeStatement("INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, ?, now() - interval '1 day')", [$subscription, $priceId, $quantity]);
        }

        return $subscription;
    }

    protected function activator(): ActivateSubscription
    {
        return new ActivateSubscription($this->connection, new SubscriptionPeriodCalculator());
    }

    /** @return array{id: string, status: 'active', current_period_start: string, current_period_end: string} */
    protected function activatePaid(string $accountId, string $subscriptionId, \DateTimeImmutable $activationInstant, string $correlationId): array
    {
        $this->markInitialInvoicePaid($accountId, $subscriptionId, $activationInstant);

        return $this->activator()->activate($accountId, $subscriptionId, $activationInstant, $correlationId);
    }

    protected function markInitialInvoicePaid(string $accountId, string $subscriptionId, \DateTimeImmutable $settlementInstant): string
    {
        $invoice = $this->connection->fetchAssociative("SELECT id, status FROM commercial.invoices WHERE subscription_id = ? AND billing_reason = 'subscription_create' ORDER BY created_at DESC LIMIT 1", [$subscriptionId]);
        if ($invoice === false) {
            $owner = $this->connection->fetchOne("SELECT control_user_id FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner' AND revoked_at IS NULL", [$accountId]);
            if (!is_string($owner)) {
                throw new \LogicException('Test account owner is unavailable.');
            }
            $issued = (new IssueInvoice($this->connection, new AccountAuthorization($this->connection)))->issue($owner, $accountId, $subscriptionId, 'test-paid-invoice-' . $this->uuid(), $this->uuid());
            $invoice = ['id' => $issued['id'], 'status' => 'issued'];
        }
        if (!is_string($invoice['id'])) {
            throw new \LogicException('Test invoice identity is unavailable.');
        }
        if ($invoice['status'] === 'paid') {
            return $invoice['id'];
        }
        if ($invoice['status'] !== 'issued') {
            throw new \LogicException('Test invoice is not payable.');
        }
        $payment = $this->connection->fetchOne("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) SELECT id, commercial_account_id, total_minor, currency, 'pending' FROM commercial.invoices WHERE id = ? RETURNING id", [$invoice['id']]);
        if (!is_string($payment)) {
            throw new \LogicException('Test payment identity is unavailable.');
        }
        $instant = $settlementInstant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        $this->connection->executeStatement("UPDATE commercial.payments SET status = 'succeeded', succeeded_at = ?::timestamptz, updated_at = now() WHERE id = ?", [$instant, $payment]);
        $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'paid', paid_at = ?::timestamptz, updated_at = now() WHERE id = ?", [$instant, $invoice['id']]);

        return $invoice['id'];
    }

    protected function activationInstant(): \DateTimeImmutable
    {
        $now = $this->connection->fetchOne('SELECT now()');
        if (!is_string($now)) {
            throw new \LogicException('Database time is unavailable.');
        }

        return new \DateTimeImmutable($now);
    }
}
