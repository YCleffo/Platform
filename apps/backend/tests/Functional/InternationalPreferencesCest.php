<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Account\Application\AccountPreferencesRepository;
use App\Core\Account\Application\UpdateAccountPreferences;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class InternationalPreferencesCest
{
    public function savesCanonicalAccountPreferences(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();

        try {
            $accountId = $db->createCommand(
                <<<'SQL'
                INSERT INTO core.accounts (account_type, label)
                VALUES ('service', 'international preferences integration')
                RETURNING id::text
                SQL,
            )->queryScalar();
            assertTrue(is_string($accountId));

            $saved = $container->get(UpdateAccountPreferences::class)->update(
                $accountId,
                'en',
                'America/New_York',
            );
            assertNotNull($saved);
            assertSame('en-US', $saved->locale);
            assertSame('America/New_York', $saved->timezone);

            $found = $container->get(AccountPreferencesRepository::class)->find($accountId);
            assertNotNull($found);
            assertSame('en-US', $found->locale);
            assertSame('America/New_York', $found->timezone);

            // Omitted fields cannot restore a stale value from another request.
            $service = $container->get(UpdateAccountPreferences::class);
            $service->update($accountId, 'ru', null, true, false);
            $partial = $service->update($accountId, 'en', 'Asia/Yekaterinburg', false, true);
            assertNotNull($partial);
            assertSame('ru-RU', $partial->locale);
            assertSame('Asia/Yekaterinburg', $partial->timezone);
            $reset = $service->update($accountId, null, null, true, false);
            assertNotNull($reset);
            assertSame(null, $reset->locale);
            assertSame('Asia/Yekaterinburg', $reset->timezone);
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }
}
