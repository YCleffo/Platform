<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\CreateCommercialAccount;
use ControlPlane\Identity\Application\AuthService;
use ControlPlane\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class CommercialMeHttpTest extends TestCase
{
    public function testNewEndpointsRequireAuthentication(): void
    {
        $this->assertIsolatedEnvironment();
        $kernel = new Kernel('test', false);
        $kernel->boot();
        try {
            $me = $kernel->handle(Request::create('/api/control/me', 'GET'));
            self::assertSame(Response::HTTP_UNAUTHORIZED, $me->getStatusCode());
            self::assertSame('authentication_required', $this->errorCode($me));

            $accounts = $kernel->handle(Request::create('/api/control/accounts', 'GET'));
            self::assertSame(Response::HTTP_UNAUTHORIZED, $accounts->getStatusCode());
            self::assertSame('authentication_required', $this->errorCode($accounts));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testMeAndAccountListingReflectOnlyTheAuthenticatedUser(): void
    {
        $this->assertIsolatedEnvironment();
        $kernel = new Kernel('test', false);
        $kernel->boot();
        try {
            $container = $kernel->getContainer();
            $auth = $container->get(AuthService::class);
            $accounts = $container->get(CreateCommercialAccount::class);
            if (!$auth instanceof AuthService || !$accounts instanceof CreateCommercialAccount) {
                self::fail('Commercial application services must be available in the container.');
            }
            $correlation = Uuid::v7()->toRfc4122();
            $email = 'me-' . bin2hex(random_bytes(8)) . '@example.test';
            $registered = $auth->register($email, 'correct horse battery staple', '192.0.2.9', $correlation);
            $auth->verifyEmail($registered['verification_token'], $correlation);
            $session = $auth->login($email, 'correct horse battery staple', '192.0.2.9', 'PHPUnit', $correlation);

            $ownAccount = $accounts->create($registered['user_id'], 'academy-' . bin2hex(random_bytes(4)), 'Academy Me', null, 'http-me-' . bin2hex(random_bytes(4)), $correlation);

            $me = $kernel->handle(Request::create('/api/control/me', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $session['access_token']]));
            self::assertSame(Response::HTTP_OK, $me->getStatusCode());
            $meBody = json_decode((string) $me->getContent(), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame($registered['user_id'], $meBody['id']);
            self::assertSame($email, $meBody['email']);
            self::assertArrayNotHasKey('password_hash', $meBody);
            self::assertArrayNotHasKey('email_normalized', $meBody);

            $list = $kernel->handle(Request::create('/api/control/accounts', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $session['access_token']]));
            self::assertSame(Response::HTTP_OK, $list->getStatusCode());
            $listBody = json_decode((string) $list->getContent(), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame([$ownAccount], array_column($listBody['items'], 'id'));
            self::assertSame(1, $listBody['total']);
        } finally {
            $kernel->shutdown();
        }
    }

    private function errorCode(Response $response): string
    {
        $content = $response->getContent();

        return json_decode((string) $content, true, 8, JSON_THROW_ON_ERROR)['error']['code'];
    }

    private function assertIsolatedEnvironment(): void
    {
        $url = getenv('CONTROL_DATABASE_URL');
        $parts = is_string($url) ? parse_url($url) : false;
        if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
            || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
            || ($parts['path'] ?? '') !== '/platform_commercial_test') {
            throw new \RuntimeException('Commercial HTTP tests require the isolated Commercial test database.');
        }
    }
}
