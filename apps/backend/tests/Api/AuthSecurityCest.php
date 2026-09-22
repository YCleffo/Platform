<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class AuthSecurityCest
{
    public function sensitiveApiResponsesAreNotCacheableAndCorsIsNotPermissive(ApiTester $I): void
    {
        foreach (['/api/auth/me', '/api/core/persons'] as $path) {
            $I->sendGET($path);
            $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
            $I->seeHttpHeader('X-Content-Type-Options', 'nosniff');
            $I->seeHttpHeader('Referrer-Policy', 'no-referrer');
            $I->seeHttpHeader('X-Frame-Options', 'DENY');
            $I->seeHttpHeader('Cache-Control', 'no-store');
            $I->seeHttpHeader('Pragma', 'no-cache');
            $I->dontSeeHttpHeader('Access-Control-Allow-Origin');
            $I->dontSeeHttpHeader('Access-Control-Allow-Credentials');
        }
    }

    public function sensitiveRepositoriesHaveNoPublicCrud(ApiTester $I): void
    {
        foreach ([
            '/api/core/password-credentials',
            '/api/core/recovery-codes',
            '/api/core/passkeys',
            '/api/core/sessions',
            '/api/core/auth-challenges',
        ] as $path) {
            $I->sendGET($path);
            $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
            $I->dontSeeResponseContains('password_hash');
            $I->dontSeeResponseContains('secret_hash');
            $I->dontSeeResponseContains('token_hash');
            $I->dontSeeResponseContains('passwordHash');
            $I->dontSeeResponseContains('securityStamp');
            $I->dontSeeResponseContains('sessionTokenHash');
            $I->dontSeeResponseContains('refreshTokenHash');
            $I->dontSeeResponseContains('fingerprintHashHex');
        }
    }
}
