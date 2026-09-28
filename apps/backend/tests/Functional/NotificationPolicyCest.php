<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AccountSecurityNotifier;
use App\Core\Notification\Application\NotificationPreferenceResolver;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class NotificationPolicyCest
{
    public function securityEmailOverridesPreferenceButProductChannelsRespectIt(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $account = $db->createCommand("INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'notification policy test') RETURNING id::text")->queryScalar();
            assertTrue(is_string($account));
            $db->createCommand(<<<'SQL'
                INSERT INTO core.account_settings (account_id, settings, schema_version)
                VALUES (CAST(:id AS uuid), '{"notifications":{"email":false,"browser":true,"sound":false}}'::jsonb, 2)
                SQL, [':id' => $account])->execute();
            $resolver = $container->get(NotificationPreferenceResolver::class);
            assertTrue($resolver->allows($account, 'security', 'email'));
            assertFalse($resolver->allows($account, 'product', 'email'));
            assertTrue($resolver->allows($account, 'product', 'browser'));
            assertFalse($resolver->allows($account, 'product', 'sound'));
            $db->createCommand(<<<'SQL'
                INSERT INTO core.auth_identifiers
                    (account_id, identifier_type, value, normalized_value, is_primary, can_login, can_recover, verified_at)
                VALUES (CAST(:id AS uuid), 'email', 'notification-policy@example.test', 'notification-policy@example.test', true, true, true, now())
                SQL, [':id' => $account])->execute();
            $container->get(AccountSecurityNotifier::class)->notify($account, 'auth.passkey.registered');
            $queued = $db->createCommand("SELECT count(*) FROM core.outbox_events WHERE aggregate_id = :id AND event_type = 'communication.email.requested'", [':id' => $account])->queryScalar();
            assertSame(1, (int) $queued);
        } finally {
            $transaction->rollBack();
        }
    }
}
