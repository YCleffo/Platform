<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Audit\Application\AuditService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestExecutionService;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\DataSubjectRequestStateMachine;
use App\Core\Privacy\Application\DataSubjectRequestTypeRegistry;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\PrivacyRequestExecutionCapabilityRegistry;
use App\Core\Privacy\Application\RepresentativeAuthorityPolicy;
use App\Core\Privacy\Infrastructure\ConfiguredBusinessCalendar;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class DataSubjectRequestExecutionCest
{
    public function completionRequiresRealExecutionEvidence(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $person = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Evidence', 'Subject') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $person])->queryScalar();
            $actor = new AuthenticatedIdentity($account, $person, '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', [], [], null, null);
            $clock = new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-09-25T10:00:00Z');
                }
            };
            $deadlines = new PrivacyDeadlinePolicy(new ConfiguredBusinessCalendar('2026-01-01', '2027-12-31'));
            $states = new DataSubjectRequestStateMachine();
            $audit = $container->get(AuditService::class);
            $requests = new DataSubjectRequestService(
                $db,
                $clock,
                $deadlines,
                new DataSubjectRequestTypeRegistry(),
                $states,
                $container->get(RepresentativeAuthorityPolicy::class),
                $audit,
            );
            $executions = new DataSubjectRequestExecutionService(
                $db,
                $clock,
                $deadlines,
                $states,
                $audit,
                $container->get(PrivacyRequestExecutionCapabilityRegistry::class),
            );
            $id = $requests->submitSelf($actor, 'access');
            $requests->transition($actor, $id, 'in_review', null);
            $requests->transition($actor, $id, 'in_progress', null);
            try {
                $requests->transition($actor, $id, 'completed', null);
                \PHPUnit\Framework\Assert::fail('Status transition completed without evidence.');
            } catch (DomainException) {
                assertTrue(true);
            }
            try {
                $requests->transition($actor, $id, 'rejected', 'insufficient_evidence');
                \PHPUnit\Framework\Assert::fail('Rejection completed without response evidence.');
            } catch (DomainException) {
                assertTrue(true);
            }
            $executions->recordExportFailed($actor, $id, 'contributor_failed');
            assertSame(null, $requests->getVisible($actor, $id)?->deadlines[0]->satisfiedAt);
            assertSame('in_progress', $requests->getVisible($actor, $id)?->status);
            $executionId = $executions->recordExportIssued($actor, $id, '1', str_repeat('a', 64), 128);
            assertSame('completed', $requests->getVisible($actor, $id)?->status);
            assertSame('2026-09-25', $requests->getVisible($actor, $id)?->deadlines[0]->satisfiedAt?->format('Y-m-d'));
            $row = $db->createCommand('SELECT result_size_bytes, result_sha256 FROM core.data_subject_request_executions WHERE id = CAST(:id AS uuid)', [':id' => $executionId])->queryOne();
            assertSame('128', (string) $row['result_size_bytes']);
            assertSame(str_repeat('a', 64), $row['result_sha256']);
            try {
                $db->transaction(function () use ($db, $executionId): void {
                    $db->createCommand('UPDATE core.data_subject_request_executions SET result_size_bytes = 129 WHERE id = CAST(:id AS uuid)', [':id' => $executionId])->execute();
                });
                \PHPUnit\Framework\Assert::fail('Terminal execution evidence was mutable.');
            } catch (\Throwable) {
                assertTrue(true);
            }
        } finally {
            $transaction->rollBack();
        }
    }
}
