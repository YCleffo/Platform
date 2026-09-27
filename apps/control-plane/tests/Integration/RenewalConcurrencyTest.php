<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class RenewalConcurrencyTest extends RenewalTestCase
{
    public function testRepeatedPreparationAndAdvancementCannotDuplicateLifecycleRows(): void
    {
        [$owner, $account, $subscription] = $this->activeRenewable('renewal-concurrency');
        self::assertSame(1, $this->renewalIssuer()->prepareDue(new \DateTimeImmutable('2026-02-14T10:00:00Z'), 2, 100, $this->uuid()));
        self::assertSame(0, $this->renewalIssuer()->prepareDue(new \DateTimeImmutable('2026-02-14T10:00:00Z'), 2, 100, $this->uuid()));
        $invoice = $this->connection->fetchOne("SELECT id FROM commercial.invoices WHERE subscription_id = ? AND billing_reason = 'subscription_renewal' AND status = 'issued'", [$subscription]);
        self::assertIsString($invoice);
        $payment = $this->paymentCreator()->create($owner, $account, $invoice, 'renewal-race-payment', $this->uuid());
        $provider = $this->providerAccount('renewal-race');
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'bank_transfer', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'renewal-race-provider');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('2026-02-15T10:00:00Z'), $this->uuid());
        self::assertSame(0, $this->advancement()->advanceDue(new \DateTimeImmutable('2026-02-15T10:00:01Z'), 100, $this->uuid()));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ? AND status = 'open'", [$subscription]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
    }
}
