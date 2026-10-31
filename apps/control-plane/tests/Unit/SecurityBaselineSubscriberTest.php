<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Infrastructure\Http\SecurityBaselineSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityBaselineSubscriberTest extends TestCase
{
    public function testDeclaredOversizedBodyIsRejectedWithoutReadingIt(): void
    {
        $request = Request::create('/api/control/auth/register', 'POST', server: ['HTTP_CONTENT_LENGTH' => '70000']);

        $response = $this->requestResponseFor($request);

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
        self::assertSame('request_too_large', json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function testChunkedOversizedBodyIsRejectedThroughBoundedRead(): void
    {
        $request = Request::create('/api/control/auth/register', 'POST', content: str_repeat('x', 65_537));

        $response = $this->requestResponseFor($request);

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    }

    public function testChunkedBodyWithinLimitIsAccepted(): void
    {
        $request = Request::create('/api/control/auth/register', 'POST', content: str_repeat('x', 65_536));

        $response = $this->requestResponseFor($request);

        self::assertNull($response);
    }

    public function testDeclaredBodyWithinLimitIsAcceptedWithoutReadingIt(): void
    {
        $request = Request::create('/api/control/auth/register', 'POST', server: ['HTTP_CONTENT_LENGTH' => '128']);

        $response = $this->requestResponseFor($request);

        self::assertNull($response);
    }

    public function testNonApiRequestIsIgnored(): void
    {
        $request = Request::create('/health/ready', 'POST', content: str_repeat('x', 200_000));

        $response = $this->requestResponseFor($request);

        self::assertNull($response);
    }

    private function requestResponseFor(Request $request): ?Response
    {
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new SecurityBaselineSubscriber())->onRequest($event);

        return $event->hasResponse() ? $event->getResponse() : null;
    }

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
        yield 'catalog' => ['/api/control/catalog/plans'];
        yield 'webhook' => ['/api/control/webhooks/yookassa'];
        yield 'invitation preview' => ['/api/control/invitations/preview'];
        yield 'invitation accept' => ['/api/control/invitations/accept'];
        yield 'invitation decline' => ['/api/control/invitations/decline'];
        yield 'account invitations' => ['/api/control/accounts/a/invitations'];
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
        yield 'unknown API' => ['/api/unknown'];
        yield 'health' => ['/health/ready'];
        yield 'non-API page' => ['/cabinet'];
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
