<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Consent\Application\ConsentService;
use App\Core\Privacy\Application\LegalRepresentationService;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PrivacyFoundationCest
{
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
            $id = $representations->declare($identity, (string) $subject, (string) $actor, 'legal_guardian', '2020-01-01T00:00:00Z', null, 'test_proof', 'fixture:evidence', null);
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
