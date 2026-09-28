<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Consent\Application\ConsentDefinition;
use App\Core\Consent\Application\ConsentDefinitionRegistry;
use App\Core\Consent\Application\ConsentService;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class ConsentCest
{
    public function grantWithdrawRegrantAndImmutableEvidence(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $registry = new ConsentDefinitionRegistry([new ConsentDefinition('test.processing', 'v1', str_repeat('a', 64), true)]);
        $service = new ConsentService($db, $registry);
        $transaction = $db->beginTransaction();
        try {
            $accountId = $db->createCommand("INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'consent fixture') RETURNING id::text")->queryScalar();
            assertTrue(is_string($accountId));
            $requestId = '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee';
            $first = $service->grant($accountId, 'test.processing', 'en-US', $requestId);
            assertSame($first['id'], $service->grant($accountId, 'test.processing', 'en-US', $requestId)['id']);
            assertTrue($first['current']);
            assertCount(1, $service->list($accountId)['consents']);
            assertTrue($service->withdraw($accountId, 'test.processing'));
            assertFalse($service->withdraw($accountId, 'test.processing'));
            $second = $service->grant($accountId, 'test.processing', 'ru-RU', $requestId);
            assertTrue($first['id'] !== $second['id']);
            assertSame(2, (int) $db->createCommand('SELECT count(*) FROM core.account_consents WHERE account_id = CAST(:id AS uuid)', [':id' => $accountId])->queryScalar());

            $savepoint = $db->beginTransaction();
            try {
                $db->createCommand("UPDATE core.account_consents SET document_version = 'tampered' WHERE id = CAST(:id AS uuid)", [':id' => $first['id']])->execute();
                throw new \RuntimeException('Immutable evidence was changed.');
            } catch (\Yiisoft\Db\Exception\Exception $exception) {
                assertTrue(str_contains($exception->getMessage(), 'Consent evidence is immutable'));
                $savepoint->rollBack();
            }
        } finally {
            $transaction->rollBack();
        }
    }

    public function activeUniquenessAndHashLengthAreDatabaseInvariants(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $accountId = $db->createCommand("INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'consent invariant fixture') RETURNING id::text")->queryScalar();
            assertTrue(is_string($accountId));
            $sql = <<<'SQL'
                INSERT INTO core.account_consents
                    (account_id, consent_code, document_version, document_sha256, locale, source)
                VALUES (CAST(:id AS uuid), 'test.processing', 'v1', decode(:hash, 'hex'), 'en-US', 'test')
                SQL;
            $db->createCommand($sql, [':id' => $accountId, ':hash' => str_repeat('a', 64)])->execute();
            $otherAccountId = $db->createCommand("INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'hash invariant fixture') RETURNING id::text")->queryScalar();
            assertTrue(is_string($otherAccountId));

            foreach ([[$accountId, str_repeat('b', 64)], [$otherAccountId, 'abcd']] as [$targetId, $hash]) {
                $savepoint = $db->beginTransaction();
                try {
                    $db->createCommand($sql, [':id' => $targetId, ':hash' => $hash])->execute();
                    throw new \RuntimeException('Invalid active consent was accepted.');
                } catch (\Yiisoft\Db\Exception\Exception) {
                    $savepoint->rollBack();
                }
            }
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.account_consents WHERE account_id = CAST(:id AS uuid)', [':id' => $accountId])->queryScalar());
        } finally {
            $transaction->rollBack();
        }
    }
}
