<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class PersonsCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
        $I->amAuthenticatedSuperadmin();
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function listIsPaginated(ApiTester $I): void
    {
        $I->sendGET('/api/core/persons?limit=1&offset=0');

        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'status' => 'success',
            'data' => [
                'pagination' => [
                    'limit' => 1,
                    'offset' => 0,
                ],
            ],
        ]);
    }

    public function personLifecycleAndContacts(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/core/persons', [
            'givenName' => 'Integration',
            'familyName' => 'Person',
            'middleName' => null,
            'displayName' => 'Integration Person',
            'birthDate' => '2004-11-18',
        ]);

        $I->seeResponseCodeIs(HttpCode::CREATED);
        $created = $this->response($I);
        $id = (string) $created['data']['id'];

        $I->sendGET('/api/core/persons/' . $id);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [
                'id' => $id,
                'givenName' => 'Integration',
            ],
        ]);

        $I->sendPATCH('/api/core/persons/' . $id, [
            'displayName' => 'Updated Integration Person',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [
                'displayName' => 'Updated Integration Person',
            ],
        ]);

        foreach (['   ', "bad\0label", "bad\nlabel", str_repeat('a', 81)] as $label) {
            $I->sendPOST('/api/core/persons/' . $id . '/contacts', [
                'contactType' => 'email',
                'value' => 'integration@example.test',
                'label' => $label,
            ]);
            $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
            $I->seeResponseContainsJson(['error' => ['code' => 'validation.failed']]);
        }

        $I->sendPOST('/api/core/persons/' . $id . '/contacts', [
            'contactType' => 'email',
            'value' => ' integration@example.test ',
            'label' => 'test',
            'isPrimary' => true,
        ]);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $I->seeResponseContainsJson([
            'data' => [
                'personId' => $id,
                'normalizedValue' => 'integration@example.test',
                'isPrimary' => true,
            ],
        ]);

        $I->sendGET('/api/core/persons/' . $id . '/contacts');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [[
                'personId' => $id,
                'contactType' => 'email',
            ]],
        ]);

        $I->sendPOST('/api/core/persons/' . $id . '/archive');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [
                'id' => $id,
                'status' => 'archived',
            ],
        ]);

        $I->sendPATCH('/api/core/persons/' . $id, ['displayName' => 'Too late']);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    public function validationAndMalformedUuidAreSafe(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/core/persons', [
            'givenName' => ' ',
            'birthDate' => '2024-02-31',
        ]);

        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson([
            'error' => ['code' => 'validation.failed'],
        ]);

        $I->sendGET('/api/core/persons/not-a-uuid');
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson([
            'error' => ['code' => 'person.invalid_id'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function response(ApiTester $I): array
    {
        /** @var array<string, mixed> */
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }
}
