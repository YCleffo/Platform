<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Infrastructure\Http\CorrelationIdSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdSubscriberTest extends TestCase
{
    public function testValidCorrelationIdIsReturned(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $request = Request::create('/health');
        $request->headers->set(CorrelationIdSubscriber::HEADER, strtoupper($id));
        $response = new Response();
        $subscriber = new CorrelationIdSubscriber();
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertSame($id, $response->headers->get(CorrelationIdSubscriber::HEADER));
    }

    public function testInvalidCorrelationIdIsReplaced(): void
    {
        $request = Request::create('/health');
        $request->headers->set(CorrelationIdSubscriber::HEADER, "bad\r\nvalue");
        $response = new Response();
        $subscriber = new CorrelationIdSubscriber();
        $kernel = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $actual = $response->headers->get(CorrelationIdSubscriber::HEADER);
        self::assertIsString($actual);
        self::assertTrue(Uuid::isValid($actual));
    }
}
