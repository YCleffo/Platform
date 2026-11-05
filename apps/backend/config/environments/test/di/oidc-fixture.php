<?php

declare(strict_types=1);

use App\Core\Auth\Application\OidcClientRegistryInterface;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Tests\Support\BrowserOidcFixture;
use Predis\ClientInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

if (getenv('CORE_AUTH_BROWSER_OIDC_FIXTURE') !== '1') {
    return [];
}

return [OidcClientRegistryInterface::class => static function (ConnectionInterface $db, ClientInterface $redis): OidcClientRegistryInterface {
    TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
    $proof = getenv('E2E_ISOLATION_PROOF');
    $base = getenv('AUTH_PUBLIC_BASE_URL');
    if (!is_string($proof) || strlen($proof) < 32 || !is_string($base) || $base === '') {
        throw new RuntimeException('A nonce-bound browser fixture is required.');
    }
    return new BrowserOidcFixture($redis, $base);
}];
