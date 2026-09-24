<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use ControlPlane\Usage\Application\ApplyUsageDelta;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UsageConcurrencyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('CONTROL_DATABASE_URL');
        $parts = is_string($url) ? parse_url($url) : false;
        if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
            || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
            || ($parts['path'] ?? '') !== '/platform_commercial_test') {
            throw new \RuntimeException('Usage concurrency tests require the isolated Commercial test database.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testConcurrentConsumersCannotExceedQuota(): void
    {
        [, $account, $subscription, $featureCode] = $this->activeFixture('usage-quota-race', 1);
        $first = implode('|', [$account, $featureCode, '1', 'usage-race-a-' . $account]);
        $second = implode('|', [$account, $featureCode, '1', 'usage-race-b-' . $account]);

        self::assertSame([0, 2], $this->race('apply_usage', $subscription, $first, 'apply_usage', $subscription, $second));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT quantity FROM commercial.usage_counters uc JOIN commercial.usage_periods up ON up.id = uc.usage_period_id WHERE up.subscription_id = ?', [$subscription]));
    }

    public function testUsageAndCancellationCannotMutateClosedHistory(): void
    {
        [$user, $account, $subscription, $featureCode] = $this->activeFixture('usage-cancel-race', 5);
        $usage = implode('|', [$account, $featureCode, '1', 'usage-terminal-race-' . $account]);
        $cancel = implode('|', [$user, $account, 'usage-cancel-race-' . $account, Uuid::v7()->toRfc4122()]);
        $results = $this->race('apply_usage', $subscription, $usage, 'cancel_subscription', $subscription, $cancel);
        self::assertTrue($results === [0, 0] || $results === [0, 2]);
        self::assertSame('closed', $this->connection->fetchOne('SELECT status FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
        $before = (int) $this->connection->fetchOne('SELECT COALESCE((SELECT uc.quantity FROM commercial.usage_counters uc JOIN commercial.usage_periods up ON up.id = uc.usage_period_id WHERE up.subscription_id = ?), 0)', [$subscription]);
        self::assertContains($before, [0, 1]);
        try {
            (new ApplyUsageDelta($this->connection))->apply($account, $featureCode, 1, 'usage-after-close-' . $account);
            self::fail('Usage after terminal lifecycle must be rejected.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        $after = (int) $this->connection->fetchOne('SELECT COALESCE((SELECT uc.quantity FROM commercial.usage_counters uc JOIN commercial.usage_periods up ON up.id = uc.usage_period_id WHERE up.subscription_id = ?), 0)', [$subscription]);
        self::assertSame($before, $after);
    }

    /** @return array{string, string, string, string} */
    private function activeFixture(string $prefix, int $limit): array
    {
        $suffix = bin2hex(random_bytes(6));
        $featureCode = $prefix . '.max.' . $suffix;
        $email = $prefix . '-' . $suffix . '@example.test';
        $this->connection->beginTransaction();
        $user = $this->connection->fetchOne("INSERT INTO commercial.control_users (email, email_normalized, password_hash, status, email_verified_at, password_changed_at) VALUES (?, ?, '!race-hash!', 'active', now(), now()) RETURNING id", [$email, $email]);
        $account = $this->connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Usage Race') RETURNING id", [$prefix . '-' . $suffix]);
        self::assertIsString($user);
        self::assertIsString($account);
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$user, $account]);
        $profile = $this->connection->fetchOne("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, is_default) VALUES (?, 'Race LLC', 'US', true) RETURNING id", [$account]);
        self::assertIsString($profile);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'catalog_selection_pending' WHERE id = ?", [$account]);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription' WHERE id = ?", [$account]);
        $feature = $this->connection->fetchOne("INSERT INTO commercial.features (code, value_type, usage_mode) VALUES (?, 'integer', 'gauge') RETURNING id", [$featureCode]);
        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, 'Usage Race') RETURNING id", [$prefix . '-plan-' . $suffix]);
        self::assertIsString($feature);
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 1000, 'month') RETURNING id", [$plan]);
        self::assertIsString($price);
        $this->connection->executeStatement('INSERT INTO commercial.plan_features (plan_id, feature_id, value) VALUES (?, ?, ?::jsonb)', [$plan, $feature, (string) $limit]);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $subscription = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$account, $profile]);
        self::assertIsString($subscription);
        $this->connection->executeStatement("INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, 1, now() - interval '1 day')", [$subscription, $price]);
        (new ActivateSubscription($this->connection, new SubscriptionPeriodCalculator()))->activate($account, $subscription, new \DateTimeImmutable('now'), Uuid::v7()->toRfc4122());
        $this->connection->commit();

        return [$user, $account, $subscription, $featureCode];
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
            throw new \RuntimeException('Could not launch usage race worker.');
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
