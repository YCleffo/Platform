<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SubscriptionLifecycleConcurrencyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('CONTROL_DATABASE_URL');
        $parts = is_string($url) ? parse_url($url) : false;
        if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
            || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
            || ($parts['path'] ?? '') !== '/platform_commercial_test') {
            throw new \RuntimeException('Lifecycle concurrency tests require the isolated Commercial test database.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testConcurrentCancellationWritesOneLifecycleEvent(): void
    {
        [$user, $account, $subscription] = $this->activeFixture('cancel-race', '2026-09-01T00:00:00Z');
        $correlation = Uuid::v7()->toRfc4122();
        $extra = implode('|', [$user, $account, 'cancel-race-' . $account, $correlation]);

        self::assertSame([0, 0], $this->race('cancel_subscription', $subscription, $extra, 'cancel_subscription', $subscription, $extra));
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        $this->assertSingleTerminalEvent($subscription);
    }

    public function testConcurrentExpirationWritesOneLifecycleEvent(): void
    {
        [, $account, $subscription] = $this->activeFixture('expire-race', '2026-01-01T00:00:00Z');
        $extra = implode('|', [$account, Uuid::v7()->toRfc4122(), '2026-02-01T00:00:00Z']);

        self::assertSame([0, 0], $this->race('expire_subscription', $subscription, $extra, 'expire_subscription', $subscription, $extra));
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        $this->assertSingleTerminalEvent($subscription);
    }

    public function testConcurrentCancellationAndExpirationChooseOneTerminalState(): void
    {
        [$user, $account, $subscription] = $this->activeFixture('terminal-race', '2026-01-01T00:00:00Z');
        $cancel = implode('|', [$user, $account, 'terminal-cancel-' . $account, Uuid::v7()->toRfc4122()]);
        $expire = implode('|', [$account, Uuid::v7()->toRfc4122(), '2026-02-01T00:00:00Z']);

        self::assertSame([0, 2], $this->race('cancel_subscription', $subscription, $cancel, 'expire_subscription', $subscription, $expire));
        self::assertContains($this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]), ['cancelled', 'expired']);
        $this->assertSingleTerminalEvent($subscription);
    }

    public function testConcurrentReplacementActivationKeepsOneActiveSubscription(): void
    {
        [$user, $account, $old, $profile, $price] = $this->activeFixture('replacement-race', '2026-09-01T00:00:00Z');
        $cancel = implode('|', [$user, $account, 'replacement-cancel-' . $account, Uuid::v7()->toRfc4122()]);
        self::assertSame([0], $this->raceOne('cancel_subscription', $old, $cancel));
        $replacement = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$account, $profile]);
        self::assertIsString($replacement);
        $this->connection->executeStatement("INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, 1, now() - interval '1 day')", [$replacement, $price]);
        $extra = implode('|', [$account, Uuid::v7()->toRfc4122(), '2026-09-24T12:00:00Z']);

        self::assertSame([0, 0], $this->race('activate_subscription', $replacement, $extra, 'activate_subscription', $replacement, $extra));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.subscriptions WHERE commercial_account_id = ? AND status = 'active'", [$account]));
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$old]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.activated' AND resource_id = ?", [$replacement]));
    }

    /** @return array{string, string, string, string, string} */
    private function activeFixture(string $prefix, string $activationInstant): array
    {
        $suffix = bin2hex(random_bytes(6));
        $email = $prefix . '-' . $suffix . '@example.test';
        $this->connection->beginTransaction();
        $user = $this->connection->fetchOne("INSERT INTO commercial.control_users (email, email_normalized, password_hash, status, email_verified_at, password_changed_at) VALUES (?, ?, '!race-hash!', 'active', now(), now()) RETURNING id", [$email, $email]);
        $account = $this->connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Lifecycle Race') RETURNING id", [$prefix . '-' . $suffix]);
        self::assertIsString($user);
        self::assertIsString($account);
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$user, $account]);
        $profile = $this->connection->fetchOne("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, is_default) VALUES (?, 'Race LLC', 'US', true) RETURNING id", [$account]);
        self::assertIsString($profile);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'catalog_selection_pending' WHERE id = ?", [$account]);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription' WHERE id = ?", [$account]);
        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, 'Lifecycle Race') RETURNING id", [$prefix . '-plan-' . $suffix]);
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 1000, 'month') RETURNING id", [$plan]);
        self::assertIsString($price);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $subscription = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$account, $profile]);
        self::assertIsString($subscription);
        $this->connection->executeStatement("INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, 1, now() - interval '1 day')", [$subscription, $price]);
        (new ActivateSubscription($this->connection, new SubscriptionPeriodCalculator()))->activate($account, $subscription, new \DateTimeImmutable($activationInstant), Uuid::v7()->toRfc4122());
        $this->connection->commit();

        return [$user, $account, $subscription, $profile, $price];
    }

    private function assertSingleTerminalEvent(string $subscription): void
    {
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action IN ('subscription.cancelled', 'subscription.expired') AND resource_id = ?", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type IN ('subscription.cancelled', 'subscription.expired') AND aggregate_id = ?", [$subscription]));
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

    /** @return list<int> */
    private function raceOne(string $mode, string $value, string $extra): array
    {
        $worker = $this->launch($mode, $value, $extra, (string) (microtime(true) + 0.1));

        return [$this->finish($worker)];
    }

    /** @return array{resource, array<resource>} */
    private function launch(string $mode, string $value, string $extra, string $start): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/race-worker.php', $mode, $value, $extra, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not launch lifecycle race worker.');
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
