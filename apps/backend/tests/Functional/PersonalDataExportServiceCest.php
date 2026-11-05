<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\LegalRepresentationService;
use App\Core\Privacy\Application\PersonalDataExportService;
use App\Core\Privacy\Application\PersonalDataExporter;
use App\Core\Privacy\Application\PersonalDataContributorRegistry;
use App\Core\Privacy\Application\PersonalDataExportLimitException;
use App\Core\Privacy\Application\DataSubjectRequestExecutionService;
use App\Core\Privacy\Application\RepresentativeAuthorityPolicy;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PersonalDataExportServiceCest
{
    public function exportAuthorizationAndEvidence(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Subject', 'Export') RETURNING id::text")->queryScalar();
            $other = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Other', 'Export') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $subject])->queryScalar();
            $otherAccount = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $other])->queryScalar();
            $self = new AuthenticatedIdentity($account, $subject, '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', [], [], null, null);
            $outsider = new AuthenticatedIdentity($otherAccount, $other, '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', [], [], null, null);
            $requests = $container->get(DataSubjectRequestService::class);
            $service = $container->get(PersonalDataExportService::class);
            $id = $requests->submitSelf($self, 'access');
            $requests->transition($self, $id, 'in_review', null);
            $requests->transition($self, $id, 'in_progress', null);
            try {
                $service->exportSelf($outsider, $id);
                \PHPUnit\Framework\Assert::fail('Other account exported data.');
            } catch (DomainException) {
                assertTrue(true);
            }
            $result = $service->exportSelf($self, $id);
            assertSame(hash('sha256', $result->json), $result->sha256);
            assertSame('completed', $requests->getVisible($self, $id)?->status);
            assertTrue($requests->getVisible($self, $id)?->deadlines[0]->satisfiedAt !== null);
            assertTrue(!str_contains($result->json, 'Other'));

            $oversizeRequest = $requests->submitSelf($self, 'access');
            $requests->transition($self, $oversizeRequest, 'in_review', null);
            $requests->transition($self, $oversizeRequest, 'in_progress', null);
            $smallService = new PersonalDataExportService(
                $db,
                $container->get(ClockInterface::class),
                $container->get(RepresentativeAuthorityPolicy::class),
                new PersonalDataExporter($container->get(PersonalDataContributorRegistry::class), maxTotalBytes: 10),
                $container->get(DataSubjectRequestExecutionService::class),
            );
            try {
                $smallService->exportSelf($self, $oversizeRequest);
                \PHPUnit\Framework\Assert::fail('Oversized export was issued.');
            } catch (PersonalDataExportLimitException) {
                assertTrue(true);
            }
            assertSame('in_progress', $requests->getVisible($self, $oversizeRequest)?->status);
            assertSame(null, $requests->getVisible($self, $oversizeRequest)?->deadlines[0]->satisfiedAt);
            assertSame('export_too_large', $db->createCommand(<<<'SQL'
                SELECT error_code FROM core.data_subject_request_executions
                WHERE request_id = CAST(:id AS uuid) AND status = 'failed'
                SQL, [':id' => $oversizeRequest])->queryScalar());

            $repId = $requests->submitSelf($self, 'access');
            $requests->transition($self, $repId, 'in_review', null);
            $requests->transition($self, $repId, 'in_progress', null);
            $admin = new AuthenticatedIdentity(
                $account,
                $subject,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $representation = $container->get(LegalRepresentationService::class)->declare(
                $admin,
                $subject,
                $other,
                'legal_guardian',
                '2020-01-01T00:00:00Z',
                null,
                'test_proof',
                'fixture:export',
                null,
                ['privacy.request.submit', 'privacy.request.view'],
            );
            $container->get(LegalRepresentationService::class)->verify($admin, $representation);
            $repRequest = $requests->submitForSubject($outsider, $subject, $representation, 'access');
            $requests->transition($admin, $repRequest, 'in_review', null);
            $requests->transition($admin, $repRequest, 'in_progress', null);
            try {
                $service->exportRepresentative($outsider, $repRequest, $representation);
                \PHPUnit\Framework\Assert::fail('Workflow view scope exported data.');
            } catch (DomainException) {
                assertTrue(true);
            }
            assertSame('in_progress', $requests->getVisible($self, $repRequest)?->status);
            $dataAuthority = $container->get(LegalRepresentationService::class)->declare(
                $admin,
                $subject,
                $other,
                'legal_guardian',
                '2020-01-01T00:00:00Z',
                null,
                'test_proof',
                'fixture:data-export',
                null,
                ['privacy.request.submit', 'privacy.request.view', 'privacy.data.access'],
            );
            $container->get(LegalRepresentationService::class)->verify($admin, $dataAuthority);
            try {
                $service->exportRepresentative($outsider, $repRequest, $dataAuthority);
                \PHPUnit\Framework\Assert::fail('Wrong authority exported an existing request.');
            } catch (DomainException) {
                assertTrue(true);
            }
            $dataRequest = $requests->submitForSubject($outsider, $subject, $dataAuthority, 'access');
            $requests->transition($admin, $dataRequest, 'in_review', null);
            $requests->transition($admin, $dataRequest, 'in_progress', null);
            assertSame($subject, $service->exportRepresentative(
                $outsider,
                $dataRequest,
                $dataAuthority,
            )->subjectPersonId);
            $revokedRequest = $requests->submitForSubject($outsider, $subject, $dataAuthority, 'access');
            $requests->transition($admin, $revokedRequest, 'in_review', null);
            $requests->transition($admin, $revokedRequest, 'in_progress', null);
            $container->get(LegalRepresentationService::class)->revoke($admin, $dataAuthority, 'authority_revoked');
            try {
                $service->exportRepresentative($outsider, $revokedRequest, $dataAuthority);
                \PHPUnit\Framework\Assert::fail('Revoked authority exported data.');
            } catch (DomainException) {
                assertTrue(true);
            }
            assertSame('in_progress', $requests->getVisible($self, $revokedRequest)?->status);
        } finally {
            $transaction->rollBack();
        }
    }
}
