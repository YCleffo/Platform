<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ControlPlane\Identity\Application\AuthService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\IssueInvoice;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Uid\Uuid;

final class ConcurrencyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('CONTROL_DATABASE_URL');
        $parts = is_string($url) ? parse_url($url) : false;
        if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
            || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
            || ($parts['path'] ?? '') !== '/platform_commercial_test') {
            throw new \RuntimeException('Concurrency tests require the isolated Commercial test database.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[DataProvider('raceModes')]
    public function testDatabaseUniquenessWinsConcurrentRace(string $mode): void
    {
        $suffix = bin2hex(random_bytes(6));
        $value = 'race-' . $suffix;
        $extraA = '';
        $extraB = '';
        if ($mode === 'slug' || $mode === 'hostname') {
            $account = $this->account('owner-' . $suffix);
            if ($mode === 'slug') {
                $extraA = $account;
                $extraB = $account;
            } else {
                $extraA = $this->onPrem($account);
                $extraB = $this->onPrem($account);
                $value .= '.platform.example';
            }
        }

        $start = (string) (microtime(true) + 0.4);
        $worker = __DIR__ . '/race-worker.php';
        $first = $this->launch($worker, $mode, $value, $extraA, $start);
        $second = $this->launch($worker, $mode, $value, $extraB, $start);

        $results = [$this->finish($first), $this->finish($second)];
        sort($results);
        self::assertSame([0, 2], $results, 'One concurrent insert must win, one must hit a unique constraint.');
    }

    /** @return iterable<string, array{string}> */
    public static function raceModes(): iterable
    {
        foreach (['account', 'plan', 'slug', 'hostname', 'idempotency'] as $mode) {
            yield $mode => [$mode];
        }
    }

    public function testConcurrentRegistrationWithSameNormalizedEmailHasOneIdentity(): void
    {
        $email = 'race-user-' . bin2hex(random_bytes(6)) . '@example.test';
        $correlation = Uuid::v7()->toRfc4122();
        $ipSuffix = bin2hex(random_bytes(4));
        $ip = '2001:db8:' . substr($ipSuffix, 0, 4) . ':' . substr($ipSuffix, 4, 4) . '::1';
        $extra = $correlation . '|' . $ip;
        $results = $this->race('register', $email, $extra, $email, $extra);
        self::assertSame([0, 2], $results);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.control_users WHERE email_normalized = ?', [$email]));
    }

    public function testConcurrentMembershipCreationIsIdempotent(): void
    {
        $account = $this->account('membership-' . bin2hex(random_bytes(5)));
        $actor = (string) $this->connection->fetchOne("SELECT control_user_id FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner'", [$account]);
        $target = $this->user('target-' . bin2hex(random_bytes(5)) . '@example.test');
        $correlation = Uuid::v7()->toRfc4122();
        $extra = implode('|', [$actor, $account, $target, $correlation]);
        self::assertSame([0, 0], $this->race('membership', 'same', $extra, 'same', $extra));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND control_user_id = ?', [$account, $target]));
    }

    public function testConcurrentOwnerDowngradesCannotLeaveAccountOwnerless(): void
    {
        $account = $this->account('owners-' . bin2hex(random_bytes(5)));
        $second = $this->user('owner-' . bin2hex(random_bytes(5)) . '@example.test');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$second, $account]);
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner' ORDER BY id", [$account]);
        self::assertCount(2, $ids);
        self::assertSame([0, 2], $this->race('owner_change', (string) $ids[0], '', (string) $ids[1], ''));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner' AND revoked_at IS NULL", [$account]));
    }

    public function testConcurrentVerificationAndRefreshAreSingleUse(): void
    {
        $auth = $this->authService();
        $correlation = Uuid::v7()->toRfc4122();
        $registered = $auth->register('tokens-' . bin2hex(random_bytes(5)) . '@example.test', 'correct horse battery staple', '198.51.100.20', $correlation);
        self::assertSame([0, 2], $this->race('verify', $registered['verification_token'], $correlation, $registered['verification_token'], $correlation));
        $session = $auth->login('tokens-' . $this->emailSuffix($registered['user_id']), 'correct horse battery staple', '198.51.100.21', 'PHPUnit', $correlation);
        self::assertSame([0, 2], $this->race('refresh', $session['refresh_token'], $correlation, $session['refresh_token'], $correlation));
        self::assertNotNull($this->connection->fetchOne('SELECT revoked_at FROM commercial.control_sessions WHERE control_user_id = ?', [$registered['user_id']]));
    }

    public function testConcurrentSubscriptionActivationProducesOneStableResult(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $account = $this->account('activation-' . $suffix);
        $profile = $this->connection->fetchOne("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, is_default) VALUES (?, 'Race LLC', 'US', true) RETURNING id", [$account]);
        self::assertIsString($profile);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'catalog_selection_pending' WHERE id = ?", [$account]);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription' WHERE id = ?", [$account]);
        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, 'Activation Race') RETURNING id", ['activation-plan-' . $suffix]);
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 1000, 'month') RETURNING id", [$plan]);
        self::assertIsString($price);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $subscription = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$account, $profile]);
        self::assertIsString($subscription);
        $this->connection->executeStatement("INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, 1, '2026-09-01T00:00:00Z')", [$subscription, $price]);
        $correlation = Uuid::v7()->toRfc4122();
        $activationInstant = '2026-09-24T12:00:00Z';
        $owner = $this->connection->fetchOne("SELECT control_user_id FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner'", [$account]);
        self::assertIsString($owner);
        $invoice = (new IssueInvoice($this->connection, new AccountAuthorization($this->connection)))->issue($owner, $account, $subscription, 'activation-race-' . $suffix, $correlation);
        $payment = $this->connection->fetchOne("INSERT INTO commercial.payments (invoice_id, commercial_account_id, amount_minor, currency, status) SELECT id, commercial_account_id, total_minor, currency, 'pending' FROM commercial.invoices WHERE id = ? RETURNING id", [$invoice['id']]);
        self::assertIsString($payment);
        $this->connection->executeStatement("UPDATE commercial.payments SET status = 'succeeded', succeeded_at = ?::timestamptz WHERE id = ?", [$activationInstant, $payment]);
        $this->connection->executeStatement("UPDATE commercial.invoices SET status = 'paid', paid_at = ?::timestamptz WHERE id = ?", [$activationInstant, $invoice['id']]);
        $extra = implode('|', [$account, $correlation, $activationInstant]);

        self::assertSame([0, 0], $this->race('activate_subscription', $subscription, $extra, $subscription, $extra));
        $state = $this->connection->fetchAssociative('SELECT status, current_period_start, current_period_end FROM commercial.subscriptions WHERE id = ?', [$subscription]);
        self::assertIsArray($state);
        self::assertSame('active', $state['status']);
        self::assertNotNull($state['current_period_start']);
        self::assertNotNull($state['current_period_end']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.activated' AND resource_id = ?", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.activated' AND aggregate_id = ?", [$subscription]));
    }

    private function onPrem(string $account): string
    {
        $deployment = $this->connection->fetchOne("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode) VALUES (?, uuidv7(), 'on_prem') RETURNING id", [$account]);
        self::assertIsString($deployment);

        return $deployment;
    }

    private function account(string $code): string
    {
        return $this->connection->transactional(function (Connection $connection) use ($code): string {
            $email = $code . '-' . bin2hex(random_bytes(4)) . '@example.test';
            $user = $connection->fetchOne("INSERT INTO commercial.control_users (email, email_normalized, password_hash, status, email_verified_at, password_changed_at) VALUES (?, ?, '!race-hash!', 'active', now(), now()) RETURNING id", [$email, $email]);
            $account = $connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Race Account') RETURNING id", [$code]);
            if (!is_string($user) || !is_string($account)) {
                throw new \LogicException('Race fixture IDs unavailable.');
            }
            $connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$user, $account]);

            return $account;
        });
    }

    private function user(string $email): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement("INSERT INTO commercial.control_users (id, email, email_normalized, password_hash, status, email_verified_at, password_changed_at) VALUES (?, ?, ?, '!race-hash!', 'active', now(), now())", [$id, $email, strtolower($email)]);

        return $id;
    }

    private function authService(): AuthService
    {
        $tokens = new TokenCodec('test-only-control-token-secret-at-least-32-chars');
        $factory = new PasswordHasherFactory([ControlUserPrincipal::class => ['algorithm' => 'auto']]);

        return new AuthService($this->connection, new UserPasswordHasher($factory), $tokens, new DatabaseRateLimiter($this->connection, $tokens), 3600, 900, 2592000);
    }

    /** @return list<int> */
    private function race(string $modeA, string $valueA, string $extraA, string $valueB, string $extraB): array
    {
        $start = (string) (microtime(true) + 0.4);
        $worker = __DIR__ . '/race-worker.php';
        $first = $this->launch($worker, $modeA, $valueA, $extraA, $start);
        $second = $this->launch($worker, $modeA, $valueB, $extraB, $start);
        $results = [$this->finish($first), $this->finish($second)];
        sort($results);

        return $results;
    }

    private function emailSuffix(string $userId): string
    {
        $email = $this->connection->fetchOne('SELECT email_normalized FROM commercial.control_users WHERE id = ?', [$userId]);
        if (!is_string($email)) {
            throw new \LogicException('Email unavailable.');
        }

        return substr($email, strlen('tokens-'));
    }

    /** @return array{resource, array<resource>} */
    private function launch(string $worker, string $mode, string $value, string $extra, string $start): array
    {
        $command = [PHP_BINARY, $worker, $mode, $value, $extra, $start];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not launch race worker.');
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
