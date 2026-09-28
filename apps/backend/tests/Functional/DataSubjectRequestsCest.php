<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Audit\Application\AuditService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\DataSubjectRequestStateMachine;
use App\Core\Privacy\Application\DataSubjectRequestTypeRegistry;
use App\Core\Privacy\Application\LegalRepresentationService;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\ProcessingBlockService;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
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
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class DataSubjectRequestsCest
{
    public function requestAndBlockLifecycle(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Subject', 'DSAR') RETURNING id::text")->queryScalar();
            $representative = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Representative', 'DSAR') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $subject])->queryScalar();
            $repAccount = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $representative])->queryScalar();
            $self = new AuthenticatedIdentity($account, $subject, '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', [], ['core.privacy.representations.manage'], null, null);
            $rep = new AuthenticatedIdentity($repAccount, $representative, '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', [], [], null, null);
            $clock = new class implements ClockInterface {
                public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-09-25T10:00:00Z'); }
            };
            $calendar = new ConfiguredBusinessCalendar('2026-01-01', '2027-12-31', [6, 7], ['2026-09-28']);
            $audit = $container->get(AuditService::class);
            $service = new DataSubjectRequestService($db, $clock, new PrivacyDeadlinePolicy($calendar),
                new DataSubjectRequestTypeRegistry(), new DataSubjectRequestStateMachine(),
                $container->get(RepresentativeAuthorityPolicy::class), $audit);
            $blocks = new ProcessingBlockService($db, $clock, $container->get(ProcessingPurposeRegistry::class), $audit);
            $id = $service->submitSelf($self, 'access');
            assertSame('submitted', $service->getVisible($self, $id)['status']);
            assertSame('2026-10-12', (new DateTimeImmutable((string) $service->getVisible($self, $id)['due_at']))->format('Y-m-d'));
            assertSame(null, $service->getVisible($rep, $id));
            $extended = $service->extendDeadline($self, $id, 'access_response', 'complex_review');
            assertSame('2026-10-19', $extended->format('Y-m-d'));
            try {
                $service->extendDeadline($self, $id, 'access_response', 'complex_review');
                \PHPUnit\Framework\Assert::fail('Second extension was accepted.');
            } catch (DomainException) { assertTrue(true); }
            $service->transition($self, $id, 'in_review', null);
            $service->transition($self, $id, 'in_progress', null);
            try {
                $service->transition($self, $id, 'completed', null);
                \PHPUnit\Framework\Assert::fail('Unsupported completion was accepted.');
            } catch (DomainException) { assertTrue(true); }
            $representation = $container->get(LegalRepresentationService::class)->declare($self, $subject,
                $representative, 'legal_guardian', '2020-01-01T00:00:00Z', null, 'test_proof',
                'fixture:dsar', null, ['privacy.request.submit', 'privacy.request.view']);
            $container->get(LegalRepresentationService::class)->verify($self, $representation);
            $repId = $service->submitForSubject($rep, $subject, $representation, 'processing_information');
            assertSame($repId, $service->getVisible($rep, $repId)['id']);
            $blockId = $blocks->createBlock($self, $subject, 'subject', null, null, null, 'accuracy_review', $id);
            assertSame($blockId, $blocks->createBlock($self, $subject, 'subject', null, null, null, 'accuracy_review', $id));
            assertTrue($blocks->isBlocked($subject));
            assertTrue($blocks->releaseBlock($self, $blockId, 'review_complete'));
            assertFalse($blocks->releaseBlock($self, $blockId, 'review_complete'));
            assertFalse($blocks->isBlocked($subject));
            $purposeBlock = $blocks->createBlock($self, $subject, 'purpose', 'test.processing', null, null,
                'lawfulness_review', $id);
            assertTrue($blocks->isBlocked($subject, 'test.processing'));
            assertFalse($blocks->isBlocked($subject, 'test.required'));
            $resourceBlock = $blocks->createBlock($self, $subject, 'resource', null, 'core.person', $subject,
                'accuracy_review', $id);
            assertTrue($blocks->isBlocked($subject, null, 'core.person', $subject));
            assertFalse($blocks->isBlocked($subject, null, 'core.person', $representative));
            foreach ([
                static fn() => $blocks->createBlock($self, $subject, 'purpose', 'unknown.purpose', null, null, 'review', $id),
                static fn() => $blocks->createBlock($self, $subject, 'subject', 'test.processing', null, null, 'review', $id),
                static fn() => $blocks->createBlock($self, $representative, 'subject', null, null, null, 'review', $id),
            ] as $invalid) {
                try {
                    $invalid();
                    \PHPUnit\Framework\Assert::fail('Invalid processing block was accepted.');
                } catch (DomainException) { assertTrue(true); }
            }
            $db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_processing_blocks
                    (subject_person_id, scope_type, reason_code, started_at, expires_at)
                VALUES (CAST(:subject AS uuid), 'subject', 'historical_review',
                        '2026-09-23T10:00:00Z', '2026-09-24T10:00:00Z')
                SQL, [':subject' => $representative])->execute();
            assertFalse($blocks->isBlocked($representative));
            assertTrue($blocks->releaseBlock($self, $purposeBlock, 'review_complete'));
            assertTrue($blocks->releaseBlock($self, $resourceBlock, 'review_complete'));
            $container->get(LegalRepresentationService::class)->revoke($self, $representation, 'authority_revoked');
            assertSame(null, $service->getVisible($rep, $repId));
        } finally {
            $transaction->rollBack();
        }
    }
}
