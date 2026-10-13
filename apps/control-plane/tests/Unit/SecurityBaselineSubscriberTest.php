<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Infrastructure\Http\SecurityBaselineSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityBaselineSubscriberTest extends TestCase
{
    #[DataProvider('sensitivePaths')]
    public function testSensitiveResponsesCannotBeCached(string $path): void
    {
        foreach ([Response::HTTP_OK, Response::HTTP_FORBIDDEN] as $status) {
            $response = $this->responseFor($path, $status);

            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertSame('no-cache', $response->headers->get('Pragma'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function sensitivePaths(): iterable
    {
        yield 'auth' => ['/api/control/auth/login'];
        yield 'account' => ['/api/control/accounts'];
        yield 'invoice' => ['/api/control/accounts/a/invoices/i'];
        yield 'payment' => ['/api/control/accounts/a/payments/p'];
        yield 'refund' => ['/api/control/accounts/a/refunds/r'];
        yield 'receipt' => ['/api/control/accounts/a/payments/p/receipt'];
        yield 'privacy' => ['/api/control/privacy/me/export'];
        yield 'm2m' => ['/api/m2m/entitlements/latest'];
    }

    #[DataProvider('otherPaths')]
    public function testOtherResponsesKeepTheirCachePolicy(string $path): void
    {
        $response = $this->responseFor($path, Response::HTTP_OK);

        self::assertFalse($response->headers->has('Pragma'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** @return iterable<string, array{string}> */
    public static function otherPaths(): iterable
    {
        yield 'catalog' => ['/api/control/catalog/plans'];
        yield 'webhook' => ['/api/control/webhooks/yookassa'];
        yield 'similar auth path' => ['/api/control/authentic'];
        yield 'health' => ['/health/ready'];
    }

    private function responseFor(string $path, int $status): Response
    {
        $request = Request::create($path);
        $response = new Response('', $status);
        $kernel = $this->createStub(HttpKernelInterface::class);

        (new SecurityBaselineSubscriber())->onResponse(new ResponseEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        return $response;
    }
}
