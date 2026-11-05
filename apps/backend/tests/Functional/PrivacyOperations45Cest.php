<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\PersonalDataExportService;
use App\Core\Privacy\Application\PrivacyCollectionNoticeService;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PrivacyOperations45Cest
{
    public function offlineRequestUsesExistingDeadlineAndStateMachine(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Offline', 'Subject') RETURNING id::text")->queryScalar();
            $operatorPerson = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Privacy', 'Operator') RETURNING id::text")->queryScalar();
            $operatorAccount = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:id AS uuid), 'human', 'active') RETURNING id::text", [':id' => $operatorPerson])->queryScalar();
            $actor = new AuthenticatedIdentity(
                $operatorAccount,
                $operatorPerson,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $service = $container->get(DataSubjectRequestService::class);
            $received = new DateTimeImmutable('2026-09-28T10:00:00Z');
            $id = $service->intake(
                $actor,
                $subject,
                'access',
                $received,
                'identity_verified',
                'document://privacy-case-45',
                null,
                'paper',
            );
            assertSame('submitted', $service->getVisible($actor, $id, true)?->status);
            $row = $db->createCommand('SELECT submitted_by_person_id, intake_channel, identity_evidence_reference FROM core.data_subject_requests WHERE id = CAST(:id AS uuid)', [':id' => $id])->queryOne();
            assertSame(null, $row['submitted_by_person_id']);
            assertSame('paper', $row['intake_channel']);
            assertSame('document://privacy-case-45', $row['identity_evidence_reference']);
            assertSame(1, (int) $db->createCommand('SELECT count(*) FROM core.data_subject_request_deadlines WHERE request_id = CAST(:id AS uuid)', [':id' => $id])->queryScalar());
            $service->transition($actor, $id, 'in_review', null);
            assertSame('in_review', $service->getVisible($actor, $id, true)?->status);
            $service->transition($actor, $id, 'in_progress', null);
            $export = $container->get(PersonalDataExportService::class)->exportOperator($actor, $id);
            assertSame($subject, $export->subjectPersonId);
            assertSame('completed', $service->getVisible($actor, $id, true)?->status);
        } finally {
            $transaction->rollBack();
        }
    }

    public function nonSubjectCollectionRequiresNoticeOrExemption(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Imported', 'Subject') RETURNING id::text")->queryScalar();
            $operatorPerson = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Privacy', 'Operator') RETURNING id::text")->queryScalar();
            $operatorAccount = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:id AS uuid), 'human', 'active') RETURNING id::text", [':id' => $operatorPerson])->queryScalar();
            $actor = new AuthenticatedIdentity(
                $operatorAccount,
                $operatorPerson,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $notices = new PrivacyCollectionNoticeService(
                $db,
                $container->get(ClockInterface::class),
                $container->get(ProcessingPurposeRegistry::class),
                $container->get(DataCategoryRegistry::class),
            );
            $collected = new DateTimeImmutable('2026-09-28T10:00:00Z');
            $required = $notices->record(
                $actor,
                $subject,
                'test.processing',
                ['identity'],
                'external_import',
                $collected,
                true,
                null,
            );
            assertFalse($notices->complete($required));
            $notices->issue($required, 'urn:notice:delivery-45');
            assertTrue($notices->complete($required));
            $exempt = $notices->record(
                $actor,
                $subject,
                'test.processing',
                ['identity'],
                'external_import',
                $collected,
                false,
                'statutory_exemption',
            );
            assertTrue($notices->complete($exempt));
        } finally {
            $transaction->rollBack();
        }
    }
}
