<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class PersonsCest
{
    public function list(ApiTester $I): void
    {
        $I->sendGET('/api/core/persons');

        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();

        $I->seeResponseContainsJson([
            'status' => 'success',
        ]);
    }
}
