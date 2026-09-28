<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
        $service = $container->get(ConsentService::class);
        $transaction = $db->beginTransaction();
        try {
            $personId = $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Consent', 'Fixture') RETURNING id::text")->queryScalar();
            $accountId = $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
                [':person' => $personId],
            )->queryScalar();
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
            assertSame(2, (int) $db->createCommand('SELECT count(*) FROM core.privacy_consents WHERE subject_person_id = CAST(:id AS uuid)', [':id' => $personId])->queryScalar());

            $savepoint = $db->beginTransaction();
            try {
                $db->createCommand("UPDATE core.privacy_consents SET document_version = 'tampered' WHERE id = CAST(:id AS uuid)", [':id' => $first['id']])->execute();
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
            $personId = $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Consent', 'Invariant') RETURNING id::text")->queryScalar();
            $accountId = $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
                [':person' => $personId],
            )->queryScalar();
            $sql = <<<'SQL'
                INSERT INTO core.privacy_consents
                    (granted_by_account_id, subject_person_id, granted_by_person_id, consent_code,
                     purpose_code, document_version, document_sha256, locale, source)
                VALUES (CAST(:account AS uuid), CAST(:person AS uuid), CAST(:person AS uuid),
                        'test.processing', 'test.processing', 'v1', decode(:hash, 'hex'), 'en-US', 'test')
                SQL;
            $db->createCommand($sql, [':account' => $accountId, ':person' => $personId, ':hash' => str_repeat('a', 64)])->execute();
            foreach ([str_repeat('b', 64), 'abcd'] as $hash) {
                $savepoint = $db->beginTransaction();
                try {
                    $db->createCommand($sql, [':account' => $accountId, ':person' => $personId, ':hash' => $hash])->execute();
                    throw new \RuntimeException('Invalid consent row was accepted.');
                } catch (\Yiisoft\Db\Exception\Exception) {
                    $savepoint->rollBack();
                }
            }
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.privacy_consents WHERE subject_person_id = CAST(:id AS uuid)', [':id' => $personId])->queryScalar());
        } finally {
            $transaction->rollBack();
        }
    }
}
