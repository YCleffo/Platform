<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class RenewalLifecycleTest extends RenewalTestCase
{
    public function testExpirationBatchReportsRenewedSubscriptionAsNotExpired(): void
    {
        [$owner, $account, $subscription] = $this->activeRenewable('renewal-expiration-batch');
        $renewal = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $renewal['id'], 'renewal-expiration-payment', $this->uuid());
        $provider = $this->providerAccount('renewal-expiration-batch');
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'bank_transfer', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'renewal-expiration-provider-payment');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('2026-02-01T00:00:00Z'), $this->uuid());

        $expired = (new \ControlPlane\Subscription\Application\ExpireSubscription($this->connection))
            ->expireDue(new \DateTimeImmutable('2026-02-15T10:00:00Z'), 10, $this->uuid());

        self::assertSame(0, $expired);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame('2026-02-15', (new \DateTimeImmutable((string) $this->connection->fetchOne(
            'SELECT current_period_start FROM commercial.subscriptions WHERE id = ?', [$subscription])))->format('Y-m-d'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.expired' AND aggregate_id = ?", [$subscription]));
    }

    public function testEarlyPaymentFundsRenewalWithoutAdvancingUntilBoundary(): void
    {
        [$owner, $account, $subscription] = $this->activeRenewable('renewal-life');
        $renewal = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $renewal['id'], 'renewal-payment', $this->uuid());
        $provider = $this->providerAccount('renewal-life');
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'bank_transfer', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'renewal-provider-payment');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('2026-02-01T00:00:00Z'), $this->uuid());

        self::assertSame('2026-01-15', (new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT current_period_start FROM commercial.subscriptions WHERE id = ?', [$subscription])))->format('Y-m-d'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ? AND status = 'open'", [$subscription]));
        $advanced = $this->advancement()->advance($account, $subscription, new \DateTimeImmutable('2026-02-15T10:00:00Z'), $this->uuid());
        self::assertSame('2026-02-15', (new \DateTimeImmutable($advanced['current_period_start']))->format('Y-m-d'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.renewed' AND aggregate_id = ?", [$subscription]));
    }
}
