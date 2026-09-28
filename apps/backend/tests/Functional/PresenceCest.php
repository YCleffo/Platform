<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Presence\Application\PresenceService;
use App\Core\Presence\Application\PresenceVisibilityPolicy;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Predis\Client;
use Predis\ClientInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PresenceCest
{
    public function redisTtlPrivacyAndUnavailableFallback(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $redis = $container->get(ClientInterface::class);
        $transaction = $db->beginTransaction();
        $account = $db->createCommand("INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'presence test') RETURNING id::text")->queryScalar();
        assertTrue(is_string($account));
        try {
            $presence = $container->get(PresenceService::class);
            $policy = $container->get(PresenceVisibilityPolicy::class);
            assertSame('offline', $policy->visibleState($account));
            assertTrue($presence->heartbeat($account, 'test-client'));
            assertSame('online', $policy->visibleState($account));
            $db->createCommand(<<<'SQL'
                INSERT INTO core.account_settings (account_id, settings, schema_version)
                VALUES (CAST(:id AS uuid), '{"privacy":{"show_online_status":false}}'::jsonb, 2)
                SQL, [':id' => $account])->execute();
            assertSame('hidden', $policy->visibleState($account));
            $redis->expire('presence:' . $account, 1);
            usleep(1_100_000);
            assertSame('offline', $presence->state($account));

            $unavailable = new PresenceService(new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.1]));
            assertSame('unknown', $unavailable->state($account));
            assertSame(false, $unavailable->heartbeat($account, 'test-client'));
        } finally {
            $redis->del(['presence:' . $account]);
            $transaction->rollBack();
        }
    }
}
