<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
            $account = $this->connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Race Account') RETURNING id", ['owner-' . $suffix]);
            self::assertIsString($account);
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

    private function onPrem(string $account): string
    {
        $deployment = $this->connection->fetchOne("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode) VALUES (?, uuidv7(), 'on_prem') RETURNING id", [$account]);
        self::assertIsString($deployment);

        return $deployment;
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
