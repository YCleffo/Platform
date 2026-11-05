<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\CorrectionCommand;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\PersonalDataCorrectionService;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class PersonalDataCorrectionCest
{
    public function correctionUsesPersonDomainAndEvidence(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Before', 'Correction') RETURNING id::text")->queryScalar();
            $other = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Other', 'Correction') RETURNING id::text")->queryScalar();
            $account = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $subject])->queryScalar();
            $actor = new AuthenticatedIdentity($account, $subject, '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', [], [], null, null);
            $requests = $container->get(DataSubjectRequestService::class);
            $corrections = $container->get(PersonalDataCorrectionService::class);
            $id = $requests->submitSelf($actor, 'correction');
            $requests->transition($actor, $id, 'in_review', null);
            $requests->transition($actor, $id, 'in_progress', null);
            try {
                $corrections->correct($actor, $id, new CorrectionCommand('core.person', $subject, ['givenName' => 'After']));
                \PHPUnit\Framework\Assert::fail('Correction completed without confirmed evidence.');
            } catch (DomainException) {
                assertTrue(true);
            }
            assertTrue($corrections->confirmEvidence($actor, $id));
            assertSame(false, $corrections->confirmEvidence($actor, $id));
            try {
                $corrections->correct($actor, $id, new CorrectionCommand('core.person', $other, ['givenName' => 'Wrong']));
                \PHPUnit\Framework\Assert::fail('Other person was corrected.');
            } catch (DomainException) {
                assertTrue(true);
            }
            foreach ([
                new CorrectionCommand('unknown.contributor', $subject, ['givenName' => 'Wrong']),
                new CorrectionCommand('core.person', $subject, ['id' => $other]),
                new CorrectionCommand('core.person', $subject, ['givenName' => str_repeat('A', 500)]),
                new CorrectionCommand('core.person', $subject, ['birthDate' => '2099-01-01']),
            ] as $invalid) {
                try {
                    $corrections->correct($actor, $id, $invalid);
                    \PHPUnit\Framework\Assert::fail('Invalid correction was accepted.');
                } catch (DomainException) {
                    assertTrue(true);
                }
            }
            $execution = $corrections->correct(
                $actor,
                $id,
                new CorrectionCommand('core.person', $subject, ['givenName' => 'After']),
            );
            assertSame('After', $db->createCommand('SELECT given_name FROM core.persons WHERE id = CAST(:id AS uuid)', [':id' => $subject])->queryScalar());
            assertSame('Other', $db->createCommand('SELECT given_name FROM core.persons WHERE id = CAST(:id AS uuid)', [':id' => $other])->queryScalar());
            assertSame('completed', $requests->getVisible($actor, $id)?->status);
            assertTrue($requests->getVisible($actor, $id)?->deadlines[0]->satisfiedAt !== null);
            $evidence = $db->createCommand('SELECT changed_paths::text, result_sha256 FROM core.data_subject_request_executions WHERE id = CAST(:id AS uuid)', [':id' => $execution])->queryOne();
            assertSame('{given_name}', $evidence['changed_paths']);
            assertSame(null, $evidence['result_sha256']);

            $contact = (string) $db->createCommand("INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value) VALUES (CAST(:person AS uuid), 'email', 'before@example.test', 'before@example.test') RETURNING id::text", [':person' => $subject])->queryScalar();
            $phoneContact = (string) $db->createCommand("INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value) VALUES (CAST(:person AS uuid), 'phone', '+79991234567', '+79991234567') RETURNING id::text", [':person' => $subject])->queryScalar();
            $otherContact = (string) $db->createCommand("INSERT INTO core.person_contacts (person_id, contact_type, value, normalized_value) VALUES (CAST(:person AS uuid), 'email', 'other@example.test', 'other@example.test') RETURNING id::text", [':person' => $other])->queryScalar();
            $contactRequest = $requests->submitSelf($actor, 'correction');
            $requests->transition($actor, $contactRequest, 'in_review', null);
            $requests->transition($actor, $contactRequest, 'in_progress', null);
            $corrections->confirmEvidence($actor, $contactRequest);
            foreach ([
                new CorrectionCommand('core.person_contact', $otherContact, ['value' => 'wrong@example.test']),
                new CorrectionCommand('core.person_contact', $contact, ['value' => 'invalid-email']),
                new CorrectionCommand('core.person_contact', $phoneContact, ['value' => 'invalid-phone']),
                new CorrectionCommand('core.person_contact', $contact, ['normalizedValue' => 'injected']),
            ] as $invalid) {
                try {
                    $corrections->correct($actor, $contactRequest, $invalid);
                    \PHPUnit\Framework\Assert::fail('Invalid contact correction was accepted.');
                } catch (DomainException) {
                    assertTrue(true);
                }
            }
            $corrections->correct(
                $actor,
                $contactRequest,
                new CorrectionCommand('core.person_contact', $contact, ['value' => 'After@Example.Test', 'isPrimary' => true]),
            );
            $row = $db->createCommand('SELECT value, normalized_value, is_primary FROM core.person_contacts WHERE id = CAST(:id AS uuid)', [':id' => $contact])->queryOne();
            assertSame('After@Example.Test', $row['value']);
            assertSame('after@example.test', $row['normalized_value']);
            $auditText = (string) $db->createCommand(<<<'SQL'
                SELECT COALESCE(string_agg(metadata::text, ' '), '') FROM core.audit_events
                WHERE resource_id = :id
                SQL, [':id' => $contactRequest])->queryScalar();
            $evidenceText = (string) $db->createCommand(<<<'SQL'
                SELECT COALESCE(string_agg(row_to_json(e)::text, ' '), '')
                FROM core.data_subject_request_executions e
                WHERE request_id = CAST(:id AS uuid)
                SQL, [':id' => $contactRequest])->queryScalar();
            foreach (['before@example.test', 'After@Example.Test', 'after@example.test'] as $privateValue) {
                assertTrue(!str_contains($auditText, $privateValue));
                assertTrue(!str_contains($evidenceText, $privateValue));
            }
        } finally {
            $transaction->rollBack();
        }
    }
}
