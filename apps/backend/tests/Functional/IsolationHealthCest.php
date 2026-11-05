<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\IsolationHealthAction;
use App\Api\Shared\ResponseFactory;
use App\Tests\Support\ApiTester;

use function PHPUnit\Framework\assertSame;

final readonly class IsolationHealthCest
{
    public function proofRequiresNonceAndAllRuntimeGuards(): void
    {
        $saved = [];
        foreach (['E2E_ISOLATION_PROOF', 'APP_ENV', 'TEST_ISOLATED_RUNTIME', 'REDIS_HOST'] as $key) {
            $saved[$key] = getenv($key);
        }
        $action = new IsolationHealthAction();
        $responses = ApiTester::getTestContainer()->get(ResponseFactory::class);
        $db = ApiTester::getDb();
        try {
            putenv('E2E_ISOLATION_PROOF');
            assertSame(404, $action($responses, $db)->getStatusCode());
            putenv('E2E_ISOLATION_PROOF=too-short');
            assertSame(404, $action($responses, $db)->getStatusCode());
            putenv('E2E_ISOLATION_PROOF=' . bin2hex(random_bytes(32)));
            assertSame(200, $action($responses, $db)->getStatusCode());
            foreach (['APP_ENV' => 'prod', 'TEST_ISOLATED_RUNTIME' => '0', 'REDIS_HOST' => 'redis'] as $key => $invalid) {
                $previous = getenv($key);
                putenv($key . '=' . $invalid);
                assertSame(404, $action($responses, $db)->getStatusCode());
                putenv($previous === false ? $key : $key . '=' . $previous);
            }
        } finally {
            foreach ($saved as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }
}
