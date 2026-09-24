<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class InvoiceConcurrencyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('CONTROL_DATABASE_URL');
        $parts = is_string($url) ? parse_url($url) : false;
        if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
            || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
            || ($parts['path'] ?? '') !== '/platform_commercial_test') {
            throw new \RuntimeException('Invoice concurrency tests require the isolated Commercial test database.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testConcurrentIssuanceCreatesOneInvoiceLifecycle(): void
    {
        [$owner, $account, $subscription] = $this->draftFixture('invoice-issue-race');
        $first = implode('|', [$owner, $account, 'invoice-race-a-' . $account, Uuid::v7()->toRfc4122()]);
        $second = implode('|', [$owner, $account, 'invoice-race-b-' . $account, Uuid::v7()->toRfc4122()]);

        self::assertSame([0, 0], $this->race('issue_invoice', $subscription, $first, 'issue_invoice', $subscription, $second));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices WHERE subscription_id = ? AND status = 'issued'", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoice_lines il JOIN commercial.invoices i ON i.id = il.invoice_id WHERE i.subscription_id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events ae JOIN commercial.invoices i ON i.id::text = ae.resource_id WHERE ae.action = 'invoice.issued' AND i.subscription_id = ?", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events oe JOIN commercial.invoices i ON i.id = oe.aggregate_id WHERE oe.event_type = 'invoice.issued' AND i.subscription_id = ?", [$subscription]));
    }

    public function testIssueAndDraftCancellationCannotLeaveIssuedInvoiceOnCancelledSubscription(): void
    {
        [$owner, $account, $subscription] = $this->draftFixture('invoice-cancel-race');
        $issue = implode('|', [$owner, $account, 'invoice-issue-cancel-race-' . $account, Uuid::v7()->toRfc4122()]);
        $cancel = implode('|', [$owner, $account, 'invoice-cancel-issue-race-' . $account, Uuid::v7()->toRfc4122()]);

        $results = $this->race('issue_invoice', $subscription, $issue, 'cancel_subscription', $subscription, $cancel);

        self::assertTrue($results === [0, 0] || $results === [0, 2]);
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices WHERE subscription_id = ? AND status = 'issued'", [$subscription]));
        self::assertLessThanOrEqual(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoices WHERE subscription_id = ?', [$subscription]));
        self::assertSame(
            (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events ae JOIN commercial.invoices i ON i.id::text = ae.resource_id WHERE ae.action = 'invoice.issued' AND i.subscription_id = ?", [$subscription]),
            (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events ae JOIN commercial.invoices i ON i.id::text = ae.resource_id WHERE ae.action = 'invoice.voided' AND i.subscription_id = ?", [$subscription]),
        );
    }

    /** @return array{string, string, string} */
    private function draftFixture(string $prefix): array
    {
        $suffix = bin2hex(random_bytes(6));
        $email = $prefix . '-' . $suffix . '@example.test';
        $this->connection->beginTransaction();
        $owner = $this->connection->fetchOne("INSERT INTO commercial.control_users (email, email_normalized, password_hash, status, email_verified_at, password_changed_at) VALUES (?, ?, '!race-hash!', 'active', now(), now()) RETURNING id", [$email, $email]);
        $account = $this->connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Invoice Race') RETURNING id", [$prefix . '-' . $suffix]);
        self::assertIsString($owner);
        self::assertIsString($account);
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$owner, $account]);
        $profile = $this->connection->fetchOne("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, is_default, address) VALUES (?, 'Race LLC', 'US', true, '{\"city\":\"Test\"}'::jsonb) RETURNING id", [$account]);
        self::assertIsString($profile);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'catalog_selection_pending' WHERE id = ?", [$account]);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription' WHERE id = ?", [$account]);
        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, 'Invoice Race') RETURNING id", [$prefix . '-plan-' . $suffix]);
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 1000, 'month') RETURNING id", [$plan]);
        self::assertIsString($price);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $subscription = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$account, $profile]);
        self::assertIsString($subscription);
        $this->connection->executeStatement("INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, 1, now() - interval '1 day')", [$subscription, $price]);
        $this->connection->commit();

        return [$owner, $account, $subscription];
    }

    /** @return list<int> */
    private function race(string $modeA, string $valueA, string $extraA, string $modeB, string $valueB, string $extraB): array
    {
        $start = (string) (microtime(true) + 0.4);
        $first = $this->launch($modeA, $valueA, $extraA, $start);
        $second = $this->launch($modeB, $valueB, $extraB, $start);
        $results = [$this->finish($first), $this->finish($second)];
        sort($results);

        return $results;
    }

    /** @return array{resource, array<resource>} */
    private function launch(string $mode, string $value, string $extra, string $start): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/race-worker.php', $mode, $value, $extra, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not launch invoice race worker.');
        }

        return [$process, $pipes];
    }

    /** @param array{resource, array<resource>} $worker */
    private function finish(array $worker): int
    {
        [$process, $pipes] = $worker;
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        return proc_close($process);
    }
}
