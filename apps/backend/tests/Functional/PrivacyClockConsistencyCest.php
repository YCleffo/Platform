<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Audit\Application\AuditService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Consent\Application\ConsentDefinitionRegistry;
use App\Core\Consent\Application\ConsentService;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\DataSubjectRequestStateMachine;
use App\Core\Privacy\Application\DataSubjectRequestTypeRegistry;
use App\Core\Privacy\Application\LegalRepresentationService;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Application\ProcessingBlockService;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RepresentationScopeRegistry;
use App\Core\Privacy\Infrastructure\ConfiguredBusinessCalendar;
use App\Core\Privacy\Infrastructure\PostgresRepresentativeAuthorityPolicy;
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

final readonly class PrivacyClockConsistencyCest
{
    public function policyUsesInjectedTimeRatherThanDatabaseClock(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Clock', 'Subject') RETURNING id::text")->queryScalar();
            $repPerson = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Clock', 'Representative') RETURNING id::text")->queryScalar();
            $repAccount = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
                [':person' => $repPerson],
            )->queryScalar();
            $actor = new AuthenticatedIdentity(
                $repAccount,
                $repPerson,
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                ['core.superadmin'],
                [],
                null,
                null,
            );
            $clock2030 = new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2030-09-25T10:00:00Z');
                }
            };
            $clock2032 = new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2032-09-25T10:00:00Z');
                }
            };
            $audit = $container->get(AuditService::class);
            $scopes = $container->get(RepresentationScopeRegistry::class);
            $representations = new LegalRepresentationService($db, $audit, $scopes, $clock2030);
            $authority = $representations->declare(
                $actor,
                $subject,
                $repPerson,
                'legal_guardian',
                '2029-01-01T00:00:00Z',
                '2031-01-01T00:00:00Z',
                'test_proof',
                'fixture:clock',
                null,
                ['privacy.request.submit', 'privacy.request.view', 'privacy.consent.grant'],
            );
            assertTrue($representations->verify($actor, $authority));
            $policy2030 = new PostgresRepresentativeAuthorityPolicy($db, $clock2030);
            $policy2032 = new PostgresRepresentativeAuthorityPolicy($db, $clock2032);
            $policy2030->assertActiveForAction($authority, $subject, $repPerson, 'privacy.request.submit');
            $calendar = new ConfiguredBusinessCalendar('2029-01-01', '2031-12-31');
            $requests2030 = new DataSubjectRequestService(
                $db,
                $clock2030,
                new PrivacyDeadlinePolicy($calendar),
                new DataSubjectRequestTypeRegistry(),
                new DataSubjectRequestStateMachine(),
                $policy2030,
                $audit,
            );
            $id = $requests2030->submitForSubject($actor, $subject, $authority, 'access');
            assertSame($id, $requests2030->getVisible($actor, $id)?->id);
            $requests2032 = new DataSubjectRequestService(
                $db,
                $clock2032,
                new PrivacyDeadlinePolicy($calendar),
                new DataSubjectRequestTypeRegistry(),
                new DataSubjectRequestStateMachine(),
                $policy2032,
                $audit,
            );
            assertSame(null, $requests2032->getVisible($actor, $id));
            $purposes = $container->get(ProcessingPurposeRegistry::class);
            $blocks2030 = new ProcessingBlockService($db, $clock2030, $purposes, $audit);
            $blocks2032 = new ProcessingBlockService($db, $clock2032, $purposes, $audit);
            $blocks2030->createBlock(
                $actor,
                $subject,
                'subject',
                null,
                null,
                null,
                'accuracy_review',
                $id,
                new DateTimeImmutable('2030-09-26T10:00:00Z'),
            );
            assertTrue($blocks2030->isBlocked($subject));
            assertFalse($blocks2032->isBlocked($subject));
            $consents = new ConsentService(
                $db,
                $container->get(ConsentDefinitionRegistry::class),
                $policy2030,
                $purposes,
                $audit,
                $clock2030,
            );
            $grant = $consents->grantForSubject(
                $repAccount,
                $subject,
                'test.processing',
                'en-US',
                '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
                $authority,
            );
            assertSame('2030-09-25', (new DateTimeImmutable((string) $grant['granted_at']))->format('Y-m-d'));
        } finally {
            $transaction->rollBack();
        }
    }
}
