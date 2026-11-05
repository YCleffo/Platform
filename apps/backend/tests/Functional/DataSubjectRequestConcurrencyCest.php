<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\TestReset;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class DataSubjectRequestConcurrencyCest
{
    public function legalHoldCreateAndReleaseAreRaceSafe(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        try {
            $person = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Concurrent', 'Hold') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
                [':person' => $person],
            )->queryScalar();
            $key = implode(':', [$person, 'subject', '', '', '', 'legal_review', '', '']);
            $created = $this->compete(
                $db,
                'hold_create',
                $account,
                $person,
                $person,
                'SELECT pg_advisory_xact_lock(hashtextextended(:id, 0))',
                $key,
            );
            assertTrue($created[0]['ok']);
            assertTrue($created[1]['ok']);
            assertSame($created[0]['result'], $created[1]['result']);
            $holdId = (string) $created[0]['result'];
            $released = $this->compete(
                $db,
                'hold_release',
                $account,
                $person,
                $holdId,
                'SELECT id FROM core.privacy_legal_holds WHERE id = CAST(:id AS uuid) FOR UPDATE',
                $holdId,
            );
            assertTrue($released[0]['ok']);
            assertTrue($released[1]['ok']);
            assertCount(1, array_filter($released, static fn(array $row): bool => $row['result'] === true));
        } finally {
            TestReset::reset($db);
        }
    }

    public function parallelRetentionEvaluationStoresOneDecision(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        try {
            $person = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Concurrent', 'Retention') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
                [':person' => $person],
            )->queryScalar();
            $key = implode(':', [$person, 'core.person', $person]);
            $results = $this->compete(
                $db,
                'retention_evaluate',
                $account,
                $person,
                $person,
                'SELECT pg_advisory_xact_lock(hashtextextended(:id, 0))',
                $key,
            );
            assertTrue($results[0]['ok']);
            assertTrue($results[1]['ok']);
            assertSame('retain', $results[0]['result']);
            assertSame(1, (int) $db->createCommand(
                'SELECT count(*) FROM core.privacy_retention_decisions WHERE subject_person_id = CAST(:id AS uuid)',
                [':id' => $person],
            )->queryScalar());
        } finally {
            TestReset::reset($db);
        }
    }

    public function doubleProcessingTerminationStoresOneExecution(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        try {
            $person = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Concurrent', 'Termination') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
                [':person' => $person],
            )->queryScalar();
            $actor = new AuthenticatedIdentity(
                $account,
                $person,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $requests = $container->get(DataSubjectRequestService::class);
            $requestId = $requests->submitSelf($actor, 'processing_termination');
            $requests->transition($actor, $requestId, 'in_review', null);
            $requests->transition($actor, $requestId, 'in_progress', null);
            $results = $this->compete(
                $db,
                'terminate',
                $account,
                $person,
                $requestId,
                'SELECT pg_advisory_xact_lock(hashtextextended(:id, 0))',
                'privacy.termination:' . $requestId,
            );
            assertTrue($results[0]['ok']);
            assertTrue($results[1]['ok']);
            assertSame($results[0]['result']['executionId'], $results[1]['result']['executionId']);
            assertSame(1, (int) $db->createCommand(
                "SELECT count(*) FROM core.data_subject_request_executions WHERE request_id = CAST(:id AS uuid) AND execution_code = 'processing_termination'",
                [':id' => $requestId],
            )->queryScalar());
            assertSame(1, (int) $db->createCommand(
                'SELECT count(*) FROM core.privacy_processing_blocks WHERE source_request_id = CAST(:id AS uuid)',
                [':id' => $requestId],
            )->queryScalar());
        } finally {
            TestReset::reset($db);
        }
    }

    public function competingMutationsHaveOneOutcome(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        try {
            $person = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Concurrent', 'DSAR') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
                [':person' => $person],
            )->queryScalar();
            $actor = new AuthenticatedIdentity(
                $account,
                $person,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $requestId = $container->get(DataSubjectRequestService::class)->submitSelf($actor, 'access');

            $transition = $this->compete(
                $db,
                'transition',
                $account,
                $person,
                $requestId,
                'SELECT id FROM core.data_subject_requests WHERE id = CAST(:id AS uuid) FOR UPDATE',
                $requestId,
            );
            assertSame(1, count(array_filter($transition, static fn(array $row): bool => $row['ok'] === true)));

            $extension = $this->compete(
                $db,
                'extend',
                $account,
                $person,
                $requestId,
                'SELECT id FROM core.data_subject_request_deadlines WHERE request_id = CAST(:id AS uuid) FOR UPDATE',
                $requestId,
            );
            assertSame(1, count(array_filter($extension, static fn(array $row): bool => $row['ok'] === true)));

            $key = implode(':', [$person, 'subject', '', '', '', $requestId, 'accuracy_review']);
            $created = $this->compete(
                $db,
                'create',
                $account,
                $person,
                $requestId,
                'SELECT pg_advisory_xact_lock(hashtextextended(:id, 0))',
                $key,
            );
            assertTrue($created[0]['ok']);
            assertTrue($created[1]['ok']);
            assertSame($created[0]['result'], $created[1]['result']);
            $blockId = (string) $created[0]['result'];
            $released = $this->compete(
                $db,
                'release',
                $account,
                $person,
                $blockId,
                'SELECT id FROM core.privacy_processing_blocks WHERE id = CAST(:id AS uuid) FOR UPDATE',
                $blockId,
            );
            assertTrue($released[0]['ok']);
            assertTrue($released[1]['ok']);
            assertCount(1, array_filter($released, static fn(array $row): bool => $row['result'] === true));
        } finally {
            TestReset::reset($db);
        }
    }

    /** @return list<array{ok:bool,result?:mixed,error?:string}> */
    private function compete(
        ConnectionInterface $db,
        string $operation,
        string $account,
        string $person,
        string $target,
        string $lockSql,
        string $lockValue,
    ): array {
        $transaction = $db->beginTransaction();
        $db->createCommand($lockSql, [':id' => $lockValue])->queryScalar();
        $workers = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/privacy-race-worker.php',
                    $operation, $account, $person, $target], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException('Could not start privacy race worker.');
                }
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            usleep(300000);
            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0 || !is_string($output)) {
                throw new RuntimeException('Privacy race worker failed: ' . $error);
            }
            $result = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($result)) {
                throw new RuntimeException('Privacy race worker returned invalid data.');
            }
            $results[] = $result;
        }
        return $results;
    }
}
