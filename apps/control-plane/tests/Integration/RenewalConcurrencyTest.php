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

    public function testTwoInitialCollectionWorkersConvergeOnOnePaymentAndAttempt(): void
    {
        [$owner, $account, $subscription, $renewal] = $this->activeWithSavedCard('renewal-collection-race-' . bin2hex(random_bytes(4)));
        $this->connection->commit();
        $extra = $this->uuid() . '|2026-02-15T10:00:00Z';
        $start = (string) (microtime(true) + 0.4);
        $workers = [
            $this->launch('collect_renewal', $renewal, $extra, $start),
            $this->launch('collect_renewal', $renewal, $extra, $start),
        ];
        $results = [$this->finish($workers[0]), $this->finish($workers[1])];
        sort($results);

        self::assertSame([0, 0], $results);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=?', [$renewal]));
        self::assertSame('collecting', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.renewal_collection_started' AND payload->>'invoice_id'=?", [$renewal]));

        (new \ControlPlane\Subscription\Application\CancelSubscription(
            $this->connection,
            new \ControlPlane\Account\Authorization\AccountAuthorization($this->connection),
        ))->cancel($owner, $account, $subscription, 'cleanup-' . $subscription, $this->uuid());
        $this->connection->executeStatement("UPDATE commercial.payment_attempts a SET status='cancelled',completed_at=now() FROM commercial.payments p WHERE a.payment_id=p.id AND p.invoice_id=? AND a.status='pending'", [$renewal]);
        $this->connection->executeStatement("UPDATE commercial.receipts SET status='canceled',completed_at=now(),updated_at=now() WHERE commercial_account_id=(SELECT commercial_account_id FROM commercial.invoices WHERE id=?) AND status='pending'", [$renewal]);
        $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status='disabled',updated_at=now() WHERE id IN (SELECT provider_account_id FROM commercial.saved_payment_methods WHERE commercial_account_id=(SELECT commercial_account_id FROM commercial.invoices WHERE id=?))", [$renewal]);
    }

    /** @return array{string,string,string,string} */
    private function activeWithSavedCard(string $suffix): array
    {
        [$owner, $account, $profile, , , $subscription] = $this->invoiceContext($suffix, 1, 'month', 'RUB');
        $this->connection->executeStatement('UPDATE commercial.billing_profiles SET contact_name=?, contact_email=? WHERE id=?', ['Billing', $suffix . '@example.test', $profile]);
        $invoice = $this->issuer()->issue($owner, $account, $subscription, $suffix . '-initial', $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], $suffix . '-payment', $this->uuid());
        $providerId = $this->yooKassaProviderAccount($suffix);
        $provider = new \ControlPlane\Payment\Provider\ProviderAccount($providerId, 'yookassa', 'shop-' . $suffix, 'env:YOOKASSA_SECRET_KEY', 'yookassa', 1, 'service', 'full_payment');
        $attempt = $this->attemptBeginner()->begin($payment['id'], $providerId, 'bank_card', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'initial-' . $suffix);
        $this->authoritativeProcessor()->process($provider, new \ControlPlane\Payment\Provider\ProviderPayment(
            'initial-' . $suffix, 'succeeded', true, 1000, 'RUB',
            ['payment_id' => $payment['id'], 'attempt_id' => $attempt['attempt_id'], 'invoice_id' => $invoice['id']], null,
            new \DateTimeImmutable('2026-01-15T10:00:00Z'),
            new \ControlPlane\Payment\Provider\ProviderPaymentMethod('card-' . $suffix, 'bank_card', true),
        ), $this->uuid());
        $renewal = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());

        return [$owner, $account, $subscription, $renewal['id']];
    }

    /** @return array{resource,array<resource>} */
    private function launch(string $mode, string $value, string $extra, string $start): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/race-worker.php', $mode, $value, $extra, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Could not launch renewal race worker.');
        return [$process, $pipes];
    }

    /** @param array{resource,array<resource>} $worker */
    private function finish(array $worker): int
    {
        [$process, $pipes] = $worker;
        foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
        return proc_close($process);
    }
}
