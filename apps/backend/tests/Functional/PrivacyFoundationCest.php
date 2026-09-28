<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Consent\Application\ConsentService;
use App\Core\Privacy\Application\LegalRepresentationService;
use App\Core\Privacy\Application\RepresentativeAuthorityPolicy;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DomainException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PrivacyFoundationCest
{
    public function scopedAuthorityDeniesUnlistedActionsAndExpiredVerification(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $representations = $container->get(LegalRepresentationService::class);
        $policy = $container->get(RepresentativeAuthorityPolicy::class);
        $consents = $container->get(ConsentService::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Subject', 'Scoped') RETURNING id::text")->queryScalar();
            $actor = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Actor', 'Scoped') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:id AS uuid), 'human', 'active') RETURNING id::text",
                [':id' => $actor],
            )->queryScalar();
            $identity = new AuthenticatedIdentity($account, $actor, '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', ['core.superadmin'], [], null, null);
            $declare = static fn (string $from, ?string $until, array $scopes): string => $representations->declare(
                $identity, $subject, $actor, 'legal_guardian', $from, $until, 'test_proof', 'fixture:evidence', null, $scopes,
            );
            $grantOnly = $declare('2020-01-01T00:00:00Z', null, ['privacy.consent.grant']);
            assertTrue($representations->verify($identity, $grantOnly));
            $policy->assertActiveForAction($grantOnly, $subject, $actor, 'privacy.consent.grant');
            $consents->grantForSubject($account, $subject, 'test.processing', 'en-US', '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', $grantOnly);
            try {
                $consents->withdrawForSubject($account, $subject, 'test.processing', $grantOnly);
                \PHPUnit\Framework\Assert::fail('Grant-only authority allowed withdrawal.');
            } catch (DomainException) {
                assertTrue(true);
            }
            try {
                $policy->assertActiveForAction($grantOnly, $subject, $actor, 'privacy.request.submit');
                \PHPUnit\Framework\Assert::fail('Verified relationship allowed an unlisted action.');
            } catch (DomainException) {
                assertTrue(true);
            }
            $db->createCommand('SAVEPOINT scope_mutation')->execute();
            $scopeMutationRejected = false;
            try {
                $db->createCommand(
                    "INSERT INTO core.legal_representation_scopes (representation_id, scope_code) VALUES (CAST(:id AS uuid), 'privacy.consent.withdraw')",
                    [':id' => $grantOnly],
                )->execute();
            } catch (Throwable) {
                $scopeMutationRejected = true;
            }
            $db->createCommand('ROLLBACK TO SAVEPOINT scope_mutation')->execute();
            assertTrue($scopeMutationRejected);
            $withdrawOnly = $declare('2020-01-01T00:00:00Z', null, ['privacy.consent.withdraw']);
            assertTrue($representations->verify($identity, $withdrawOnly));
            assertTrue($consents->withdrawForSubject($account, $subject, 'test.processing', $withdrawOnly));
            try {
                $consents->grantForSubject($account, $subject, 'test.processing', 'en-US', '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', $withdrawOnly);
                \PHPUnit\Framework\Assert::fail('Withdraw-only authority allowed grant.');
            } catch (DomainException) {
                assertTrue(true);
            }
            $expired = $declare('2020-01-01T00:00:00Z', '2020-01-02T00:00:00Z', ['privacy.consent.grant']);
            try {
                $representations->verify($identity, $expired);
                \PHPUnit\Framework\Assert::fail('Expired authority was verified.');
            } catch (DomainException) {
                assertTrue(true);
            }
            $future = $declare('2099-01-01T00:00:00Z', null, ['privacy.consent.grant']);
            try {
                $representations->verify($identity, $future);
                \PHPUnit\Framework\Assert::fail('Future authority was verified.');
            } catch (DomainException) {
                assertTrue(true);
            }
            assertTrue($representations->revoke($identity, $grantOnly, 'test_revocation'));
            try {
                $policy->assertActiveForAction($grantOnly, $subject, $actor, 'privacy.consent.grant');
                \PHPUnit\Framework\Assert::fail('Revoked authority remained active.');
            } catch (DomainException) {
                assertTrue(true);
            }
        } finally {
            $transaction->rollBack();
        }
    }

    public function verifiedAuthorityControlsRepresentativeConsent(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $representations = $container->get(LegalRepresentationService::class);
        $consents = $container->get(ConsentService::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Subject', 'Fixture') RETURNING id::text")->queryScalar();
            $actor = $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Actor', 'Fixture') RETURNING id::text")->queryScalar();
            $account = $db->createCommand(
                "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:id AS uuid), 'human', 'active') RETURNING id::text",
                [':id' => $actor],
            )->queryScalar();
            $identity = new AuthenticatedIdentity((string) $account, (string) $actor, '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', ['core.superadmin'], [], null, null);
            $id = $representations->declare($identity, (string) $subject, (string) $actor, 'legal_guardian', '2020-01-01T00:00:00Z', null, 'test_proof', 'fixture:evidence', null, ['privacy.consent.grant', 'privacy.consent.withdraw']);
            $rejected = false;
            try {
                $consents->grantForSubject((string) $account, (string) $subject, 'test.processing', 'en-US', '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', $id);
            } catch (DomainException) {
                $rejected = true;
            }
            assertTrue($rejected);
            assertTrue($representations->verify($identity, $id));
            assertFalse($representations->verify($identity, $id));
            $consent = $consents->grantForSubject((string) $account, (string) $subject, 'test.processing', 'en-US', '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', $id);
            $row = $db->createCommand(
                'SELECT subject_person_id::text AS subject, granted_by_person_id::text AS actor, legal_representation_id::text AS authority FROM core.privacy_consents WHERE id = CAST(:id AS uuid)',
                [':id' => $consent['id']],
            )->queryOne();
            assertSame((string) $subject, $row['subject']);
            assertSame((string) $actor, $row['actor']);
            assertSame($id, $row['authority']);
            assertTrue($representations->revoke($identity, $id, 'test_revocation'));
            assertFalse($representations->revoke($identity, $id, 'test_revocation'));
            $rejected = false;
            try {
                $consents->grantForSubject((string) $account, (string) $subject, 'test.processing', 'en-US', '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', $id);
            } catch (DomainException) {
                $rejected = true;
            }
            assertTrue($rejected);
        } finally {
            $transaction->rollBack();
        }
    }
}
