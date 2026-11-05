<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Environment;
use App\PlatformRuntime\Scheduler\ScheduledJob;
use App\PlatformRuntime\Scheduler\SchedulerLoop;
use App\Tests\Support\FunctionalTester;
use PDO;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertSame;

final class SchedulerLoopCest
{
    public function dueAndFailureIsolation(FunctionalTester $tester): void
    {
        $db = (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        ))->getContainer()->get(ConnectionInterface::class);
        $calls = [];
        $logs = [];
        $loop = new SchedulerLoop($db, [
            new ScheduledJob('first', 10, true, 3, function (int $limit) use (&$calls): int {
                $calls[] = ['first', $limit];
                throw new \RuntimeException('sensitive payload must not be logged');
            }),
            new ScheduledJob('second', 10, true, 4, function (int $limit) use (&$calls): int {
                $calls[] = ['second', $limit];
                return 2;
            }),
            new ScheduledJob('disabled', 10, false, 1, function () use (&$calls): int {
                $calls[] = ['disabled'];
                return 1;
            }),
        ], static function (string $line) use (&$logs): void {
            $logs[] = $line;
        });
        $loop->tick(1000.0);
        $loop->tick(1005.0);
        assertSame([['first', 3], ['second', 4]], $calls);
        $loop->tick(1010.0);
        assertSame(4, count($calls));
        assertSame(4, count($logs));
        assertSame(false, str_contains(implode('', $logs), 'sensitive payload'));
        assertSame('failed', json_decode($logs[0], true)['status']);
        assertSame('ok', json_decode($logs[1], true)['status']);
    }

    public function advisoryLockSkipsOtherSessionAndReleases(FunctionalTester $tester): void
    {
        $db = (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        ))->getContainer()->get(ConnectionInterface::class);
        $pdo = new PDO(sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_HOST'),
            getenv('DB_PORT'),
            getenv('DB_NAME'),
        ), getenv('DB_USER'), getenv('DB_PASSWORD'));
        $key = 'scheduler:core:lock_test';
        $statement = $pdo->prepare('SELECT pg_try_advisory_lock(hashtextextended(?, 0))');
        $statement->execute([$key]);
        assertSame(true, $statement->fetchColumn());
        $calls = 0;
        $logs = [];
        $loop = new SchedulerLoop(
            $db,
            [new ScheduledJob(
                'lock_test',
                1,
                true,
                1,
                function () use (&$calls): int {
                    ++$calls;
                    return 1;
                },
            )],
            static function (string $line) use (&$logs): void {
                $logs[] = $line;
            },
        );
        try {
            $loop->tick(1000.0);
            assertSame(0, $calls);
            assertSame('skipped_lock', json_decode($logs[0], true)['status']);
        } finally {
            $statement = $pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(?, 0))');
            $statement->execute([$key]);
        }
        $loop->tick(1001.0);
        assertSame(1, $calls);
    }
}
