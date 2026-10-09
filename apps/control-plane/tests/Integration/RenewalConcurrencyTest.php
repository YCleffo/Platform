<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class RenewalConcurrencyTest extends RenewalTestCase
{
    /** @var list<array{string,string,string,string}> */
    private array $committed = [];

    protected function tearDown(): void
    {
        foreach ($this->committed as [$owner, $account, $subscription, $renewal]) {
            if ($this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$subscription]) === 'active') {
                (new \ControlPlane\Subscription\Application\CancelSubscription($this->connection,
                    new \ControlPlane\Account\Authorization\AccountAuthorization($this->connection)))
                    ->cancel($owner, $account, $subscription, 'cleanup-' . $subscription, $this->uuid());
            }
            $this->connection->executeStatement("UPDATE commercial.payment_attempts a SET status='cancelled',completed_at=now() FROM commercial.payments p WHERE a.payment_id=p.id AND p.invoice_id=? AND a.status='pending'", [$renewal]);
            $this->connection->executeStatement("UPDATE commercial.receipts SET status='canceled',completed_at=now(),updated_at=now() WHERE commercial_account_id=? AND status='pending'", [$account]);
            $this->connection->executeStatement("UPDATE commercial.payment_provider_accounts SET status='disabled',updated_at=now() WHERE id IN (SELECT provider_account_id FROM commercial.saved_payment_methods WHERE commercial_account_id=?)", [$account]);
        }
        parent::tearDown();
    }

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

    public function testDunningAndWebhookSettlementConverge(): void
    {
        $fixture = $this->retryableRenewal('webhook');
        $this->race('process_dunning', $fixture[3], $this->uuid() . '|2026-02-15T10:01:00Z',
            'webhook_renewal', $fixture[4], $this->uuid() . '|2026-02-15T10:01:00Z');
        $this->assertRenewed($fixture);
    }

    public function testDunningAndReconciliationConverge(): void
    {
        $fixture = $this->retryableRenewal('reconciliation');
        $this->race('process_dunning', $fixture[3], $this->uuid() . '|2026-02-15T10:01:00Z',
            'reconcile_renewal', $fixture[4], $this->uuid() . '|2026-02-15T10:01:00Z');
        $this->assertRenewed($fixture);
    }

    public function testSettlementWinsBeforeDunningExhaustion(): void
    {
        $fixture = $this->retryableRenewal('settlement-first');
        $this->race('webhook_renewal', $fixture[4], $this->uuid() . '|2026-02-15T11:00:01Z',
            'process_dunning', $fixture[3], $this->uuid() . '|2026-02-15T11:00:01Z', true);
        $this->assertRenewed($fixture);
        $this->assertEventCount($fixture[2], 'subscription.dunning_exhausted', 0);
    }

    public function testExpirationWinsBeforeLateSettlement(): void
    {
        $fixture = $this->retryableRenewal('expiration-first');
        $this->race('process_dunning', $fixture[3], $this->uuid() . '|2026-02-15T11:00:01Z',
            'webhook_renewal', $fixture[4], $this->uuid() . '|2026-02-15T11:00:02Z', true);
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE invoice_id=?', [$fixture[3]]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$fixture[3]]));
        self::assertSame('exhausted', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$fixture[3]]));
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$fixture[2]]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.usage_periods WHERE subscription_id=? AND status='open'", [$fixture[2]]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id=?', [$fixture[2]]));
        foreach (['subscription.dunning_exhausted', 'subscription.expired', 'subscription.renewal_late_settlement'] as $event) $this->assertEventCount($fixture[2], $event, 1);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='renewal.dunning_exhausted' AND resource_id=?", [$fixture[3]]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments p JOIN commercial.invoices i ON i.id=p.invoice_id WHERE i.id=? AND p.amount_minor=i.total_minor AND p.currency=i.currency', [$fixture[3]]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$fixture[3]]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=? AND a.status='pending'", [$fixture[3]]));
        $this->assertNoDuplicateLifecycle($fixture[1], $fixture[2], $fixture[3]);
    }

    public function testCancellationWinsBeforeDunningRetry(): void
    {
        $fixture = $this->retryableRenewal('cancel-first');
        $this->race('cancel_subscription', $fixture[2], $fixture[0] . '|' . $fixture[1] . '|cancel-' . $fixture[2] . '|' . $this->uuid(),
            'process_dunning', $fixture[3], $this->uuid() . '|2026-02-15T10:01:00Z', true);
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$fixture[2]]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=?", [$fixture[3]]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$fixture[3]]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=? AND a.status='pending'", [$fixture[3]]));
        $this->finish($this->launch('webhook_renewal', $fixture[4], $this->uuid() . '|2026-02-15T10:01:01Z', (string) (microtime(true) + 0.2)));
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$fixture[2]]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$fixture[3]]));
        $this->assertEventCount($fixture[2], 'subscription.renewal_late_settlement', 1);
        $this->assertNoDuplicateLifecycle($fixture[1], $fixture[2], $fixture[3]);
    }

    public function testSettlementBeforeRetryCreatePreventsIndependentCharge(): void
    {
        [$owner, $account, $subscription, $renewal] = $this->activeWithSavedCard('renewal-race-no-new-charge-' . bin2hex(random_bytes(4)));
        $this->committed[] = [$owner, $account, $subscription, $renewal];
        $payment = $this->paymentCreator()->create($owner, $account, $renewal, 'pending-' . $renewal, $this->uuid());
        $providerId = $this->connection->fetchOne('SELECT provider_account_id FROM commercial.saved_payment_methods WHERE commercial_account_id=?', [$account]);
        self::assertIsString($providerId);
        $attempt = $this->attemptBeginner()->begin($payment['id'], $providerId, 'bank_card', $this->uuid());
        $this->connection->commit();
        $this->finish($this->launch('process_dunning', $renewal, $this->uuid() . '|2026-02-15T10:00:00Z', (string) (microtime(true) + 0.2)));
        $this->connection->executeStatement("UPDATE commercial.renewal_collection_states SET state='collecting' WHERE invoice_id=?", [$renewal]);
        $this->connection->executeStatement("UPDATE commercial.renewal_collection_states SET state='retry_wait', next_attempt_at='2026-02-15T10:00:30Z' WHERE invoice_id=?", [$renewal]);
        $outputs = $this->race('webhook_renewal', $attempt['attempt_id'], $this->uuid() . '|2026-02-15T10:01:00Z',
            'process_dunning', $renewal, $this->uuid() . '|2026-02-15T10:01:00Z', true);
        self::assertSame(['create_count' => 0], json_decode($outputs['b'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts WHERE payment_id=?', [$payment['id']]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
        $this->assertRenewed([$owner, $account, $subscription, $renewal, $attempt['attempt_id']]);
    }

    public function testCancellationBeforeTerminalRetryStartsNoNewCharge(): void
    {
        $fixture = $this->retryableRenewal('cancel-before-create');
        $this->connection->executeStatement("UPDATE commercial.payment_attempts SET status='cancelled', completed_at='2026-02-15T10:00:01Z' WHERE id=?", [$fixture[4]]);
        $outputs = $this->race('cancel_subscription', $fixture[2], $fixture[0] . '|' . $fixture[1] . '|cancel-' . $fixture[2] . '|' . $this->uuid(),
            'process_dunning', $fixture[3], $this->uuid() . '|2026-02-15T10:01:00Z', true);
        self::assertSame(['create_count' => 0], json_decode($outputs['b'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$fixture[2]]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts WHERE payment_id=(SELECT id FROM commercial.payments WHERE invoice_id=?)', [$fixture[3]]));
        $this->assertNoDuplicateLifecycle($fixture[1], $fixture[2], $fixture[3]);
    }

    public function testCancellationAndTerminalRetryConvergeWhenReleasedTogether(): void
    {
        $fixture = $this->retryableRenewal('cancel-race');
        $this->connection->executeStatement("UPDATE commercial.payment_attempts SET status='cancelled', completed_at='2026-02-15T10:00:01Z' WHERE id=?", [$fixture[4]]);
        $outputs = $this->race('cancel_subscription', $fixture[2], $fixture[0] . '|' . $fixture[1] . '|cancel-' . $fixture[2] . '|' . $this->uuid(),
            'process_dunning', $fixture[3], $this->uuid() . '|2026-02-15T10:01:00Z');
        $charge = json_decode($outputs['b'], true, 512, JSON_THROW_ON_ERROR);
        self::assertLessThanOrEqual(1, $charge['create_count']);
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$fixture[2]]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$fixture[3]]));
        self::assertLessThanOrEqual(2, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts WHERE payment_id=(SELECT id FROM commercial.payments WHERE invoice_id=?)', [$fixture[3]]));
        self::assertLessThanOrEqual(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_attempts WHERE payment_id=(SELECT id FROM commercial.payments WHERE invoice_id=?) AND status='pending'", [$fixture[3]]));
        $this->assertNoDuplicateLifecycle($fixture[1], $fixture[2], $fixture[3], 2);
    }

    public function testMethodRestorationAndDunningConverge(): void
    {
        [$owner, $account, $subscription, $renewal] = $this->activeWithSavedCard('renewal-race-restore-' . bin2hex(random_bytes(4)));
        $this->committed[] = [$owner, $account, $subscription, $renewal];
        $method = $this->connection->fetchAssociative('SELECT id, commercial_account_id FROM commercial.saved_payment_methods WHERE commercial_account_id=?', [$account]);
        self::assertIsArray($method);
        $this->connection->executeStatement("UPDATE commercial.saved_payment_methods SET status='disabled', is_default=false, disabled_at=now(), updated_at=now() WHERE id=?", [$method['id']]);
        $this->connection->commit();
        $this->finish($this->launch('collect_renewal', $renewal, $this->uuid() . '|2026-02-15T10:00:00Z', (string) (microtime(true) + 0.2)));
        self::assertSame('payment_method_required', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
        $this->race('restore_renewal_method', (string) $method['id'], '',
            'process_dunning', $renewal, $this->uuid() . '|2026-02-15T10:00:00Z');
        $this->finish($this->launch('process_dunning', $renewal, $this->uuid() . '|2026-02-15T10:00:01Z', (string) (microtime(true) + 0.2)));
        self::assertSame($account, $this->connection->fetchOne('SELECT commercial_account_id FROM commercial.saved_payment_methods WHERE provider_method_reference=? AND status=? AND is_default', ['restored-' . str_replace('-', '', (string) $method['id']), 'active']));
        self::assertSame('collecting', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=? AND a.status='pending'", [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.payment_method_restored' AND aggregate_id=?", [$renewal]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='renewal.payment_method_restored' AND resource_id=?", [$renewal]));
        $this->assertNoDuplicateLifecycle($account, $subscription, $renewal);
    }

    public function testInvalidatedMethodCannotReachProviderCreate(): void
    {
        [$owner, $account, $subscription, $renewal] = $this->activeWithSavedCard('renewal-race-invalid-' . bin2hex(random_bytes(4)));
        $this->committed[] = [$owner, $account, $subscription, $renewal];
        $method = $this->connection->fetchAssociative('SELECT id, provider_account_id FROM commercial.saved_payment_methods WHERE commercial_account_id=?', [$account]);
        self::assertIsArray($method);
        $this->connection->commit();
        $key = random_int(100000, 900000);
        $this->connection->executeStatement(sprintf("CREATE FUNCTION commercial.renewal_race_gate_attempt() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.provider_account_id='%s'::uuid THEN PERFORM pg_advisory_xact_lock(%d); END IF; RETURN NEW; END $$", $method['provider_account_id'], $key));
        $this->connection->executeStatement('CREATE TRIGGER renewal_race_gate_attempt BEFORE INSERT ON commercial.payment_attempts FOR EACH ROW EXECUTE FUNCTION commercial.renewal_race_gate_attempt()');
        $this->connection->executeQuery('SELECT pg_advisory_lock(?)', [$key]);
        $worker = null;
        try {
            $worker = $this->launch('collect_renewal', $renewal, $this->uuid() . '|2026-02-15T10:00:00Z', (string) (microtime(true) + 0.2));
            $deadline = microtime(true) + 10;
            while ((int) $this->connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype='advisory' AND NOT granted AND pid <> pg_backend_pid()") === 0 && microtime(true) < $deadline) usleep(5_000);
            self::assertGreaterThan(0, (int) $this->connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype='advisory' AND NOT granted AND pid <> pg_backend_pid()"));
            $this->connection->executeStatement("UPDATE commercial.saved_payment_methods SET status='disabled', is_default=false, disabled_at=now(), updated_at=now() WHERE id=?", [$method['id']]);
            $this->connection->executeQuery('SELECT pg_advisory_unlock(?)', [$key]);
            $output = $this->finishDetailed($worker);
            $worker = null;
            self::assertSame(['create_count' => 0], json_decode($output['stdout'], true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=? AND a.provider_payment_id IS NOT NULL", [$renewal]));
        } finally {
            $this->connection->executeQuery('SELECT pg_advisory_unlock(?)', [$key]);
            if ($worker !== null) $this->finish($worker);
            $this->connection->executeStatement('DROP TRIGGER IF EXISTS renewal_race_gate_attempt ON commercial.payment_attempts');
            $this->connection->executeStatement('DROP FUNCTION IF EXISTS commercial.renewal_race_gate_attempt()');
        }
    }

    /** @return array{string,string,string,string,string} */
    private function retryableRenewal(string $name): array
    {
        [$owner, $account, $subscription, $renewal] = $this->activeWithSavedCard('renewal-race-' . $name . '-' . bin2hex(random_bytes(4)));
        $this->committed[] = [$owner, $account, $subscription, $renewal];
        $this->connection->commit();
        $worker = $this->launch('collect_renewal', $renewal, $this->uuid() . '|2026-02-15T10:00:00Z', (string) (microtime(true) + 0.2));
        $this->finish($worker);
        $worker = $this->launch('process_dunning', $renewal, $this->uuid() . '|2026-02-15T10:00:00Z', (string) (microtime(true) + 0.2));
        $this->finish($worker);
        $attempt = $this->connection->fetchOne('SELECT a.id FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=?', [$renewal]);
        self::assertIsString($attempt);
        $this->connection->executeStatement("UPDATE commercial.renewal_collection_states SET state='retry_wait', next_attempt_at='2026-02-15T10:00:30Z' WHERE invoice_id=?", [$renewal]);
        return [$owner, $account, $subscription, $renewal, $attempt];
    }

    /** @return array{a:string,b:string} */
    private function race(string $firstMode, string $firstValue, string $firstExtra, string $secondMode, string $secondValue, string $secondExtra, bool $ordered = false): array
    {
        $barrier = sys_get_temp_dir() . '/renewal-race-' . bin2hex(random_bytes(8));
        mkdir($barrier);
        $first = null;
        $second = null;
        try {
            $first = $this->launch($firstMode, $firstValue, $firstExtra, 'barrier|' . $barrier . '|a');
            $second = $this->launch($secondMode, $secondValue, $secondExtra, 'barrier|' . $barrier . '|b');
            $deadline = microtime(true) + 10;
            while ((!is_file($barrier . '/ready-a') || !is_file($barrier . '/ready-b')) && microtime(true) < $deadline) usleep(5_000);
            self::assertFileExists($barrier . '/ready-a');
            self::assertFileExists($barrier . '/ready-b');
            if ($ordered) {
                file_put_contents($barrier . '/release-a', 'go');
                $done = $first;
                $first = null;
                $outputA = $this->finishDetailed($done)['stdout'];
                file_put_contents($barrier . '/release-b', 'go');
                $done = $second;
                $second = null;
                $outputB = $this->finishDetailed($done)['stdout'];
            } else {
                file_put_contents($barrier . '/release-all', 'go');
                $done = $first;
                $first = null;
                $outputA = $this->finishDetailed($done)['stdout'];
                $done = $second;
                $second = null;
                $outputB = $this->finishDetailed($done)['stdout'];
            }
            return ['a' => $outputA, 'b' => $outputB];
        } finally {
            file_put_contents($barrier . '/release-all', 'go');
            if ($first !== null) $this->finish($first);
            if ($second !== null) $this->finish($second);
            foreach (glob($barrier . '/*') ?: [] as $file) unlink($file);
            rmdir($barrier);
        }
    }

    /** @param array{string,string,string,string,string} $fixture */
    private function assertRenewed(array $fixture): void
    {
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE invoice_id=?', [$fixture[3]]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$fixture[3]]));
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$fixture[3]]));
        $subscription = $this->connection->fetchAssociative('SELECT status,billing_status,current_period_start,current_period_end FROM commercial.subscriptions WHERE id=?', [$fixture[2]]);
        self::assertIsArray($subscription);
        self::assertSame('active', $subscription['status']);
        self::assertSame('current', $subscription['billing_status']);
        $period = $this->connection->fetchAssociative("SELECT starts_at,ends_at FROM commercial.usage_periods WHERE subscription_id=? AND status='open'", [$fixture[2]]);
        self::assertIsArray($period);
        self::assertSame($subscription['current_period_start'], $period['starts_at']);
        self::assertSame($subscription['current_period_end'], $period['ends_at']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.usage_periods WHERE subscription_id=? AND status='open'", [$fixture[2]]));
        foreach (['subscription.billing_recovered', 'subscription.renewed'] as $event) $this->assertEventCount($fixture[2], $event, 1);
        $this->assertFinancialIdentity($fixture[3]);
        $this->assertNoDuplicateLifecycle($fixture[1], $fixture[2], $fixture[3]);
    }

    private function assertFinancialIdentity(string $invoiceId): void
    {
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id=?', [$invoiceId]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=? AND a.status='pending'", [$invoiceId]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments p JOIN commercial.invoices i ON i.id=p.invoice_id WHERE i.id=? AND p.amount_minor=i.total_minor AND p.currency=i.currency', [$invoiceId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM (SELECT a.provider_account_id,a.provider_request_key FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=? GROUP BY a.provider_account_id,a.provider_request_key HAVING count(*)>1) duplicates', [$invoiceId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM (SELECT a.provider_account_id,a.provider_payment_id FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id=? AND a.provider_payment_id IS NOT NULL GROUP BY a.provider_account_id,a.provider_payment_id HAVING count(*)>1) duplicates', [$invoiceId]));
    }

    private function assertNoDuplicateLifecycle(string $accountId, string $subscriptionId, string $invoiceId, int $allowedStarts = 1): void
    {
        $events = ['subscription.renewal_collection_started', 'subscription.renewal_collection_failed', 'subscription.payment_method_required', 'subscription.payment_method_restored',
            'subscription.past_due', 'subscription.billing_recovered', 'subscription.dunning_exhausted', 'subscription.renewed',
            'subscription.renewal_late_settlement', 'subscription.expired'];
        foreach ($events as $event) {
            self::assertLessThanOrEqual($event === 'subscription.renewal_collection_started' ? $allowedStarts : 1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type=? AND (aggregate_id=? OR payload->>'invoice_id'=?)", [$event, $subscriptionId, $invoiceId]), $event);
        }
        $actions = ['renewal.collection.started', 'renewal.collection.failed', 'renewal.payment_method_required', 'renewal.payment_method_restored',
            'subscription.past_due', 'subscription.billing_recovered', 'renewal.dunning_exhausted', 'subscription.renewed', 'subscription.expired'];
        foreach ($actions as $action) {
            self::assertLessThanOrEqual($action === 'renewal.collection.started' ? $allowedStarts : 1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.audit_events WHERE commercial_account_id=? AND action=?', [$accountId, $action]), $action);
        }
    }

    private function assertEventCount(string $subscriptionId, string $event, int $count): void
    {
        self::assertSame($count, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.outbox_events WHERE aggregate_id=? AND event_type=?', [$subscriptionId, $event]));
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

    /** @return array{resource,array<resource>,string} */
    private function launch(string $mode, string $value, string $extra, string $start): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/race-worker.php', $mode, $value, $extra, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Could not launch renewal race worker.');
        return [$process, $pipes, $mode];
    }

    /** @param array{resource,array<resource>,string} $worker */
    private function finish(array $worker): int
    {
        return $this->finishDetailed($worker)['exit'];
    }

    /** @param array{resource,array<resource>,string} $worker
     * @return array{exit:int,stdout:string}
     */
    private function finishDetailed(array $worker): array
    {
        [$process, $pipes, $mode] = $worker;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, "Renewal worker $mode failed (exit $exit). stdout: $stdout stderr: $stderr");
        return ['exit' => $exit, 'stdout' => $stdout];
    }
}
