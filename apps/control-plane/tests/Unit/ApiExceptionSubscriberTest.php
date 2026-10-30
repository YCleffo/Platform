<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Infrastructure\Http\ApiExceptionSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiExceptionSubscriberTest extends TestCase
{
    #[DataProvider('errors')]
    public function testErrorsKeepProtocolSemanticsWithoutLeakingDetails(\Throwable $error, int $status, string $code): void
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/control/accounts'),
            HttpKernelInterface::MAIN_REQUEST,
            $error,
        );
        (new ApiExceptionSubscriber())->onException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($code, $body['error']['code']);
        self::assertStringNotContainsString('private exception details', (string) $response->getContent());
        if ($status === 503) {
            self::assertSame('17', $response->headers->get('Retry-After'));
        }
    }

    /** @return iterable<string, array{\Throwable, int, string}> */
    public static function errors(): iterable
    {
        yield 'bad HTTP request' => [new BadRequestHttpException('private exception details'), 400, 'http_error'];
        yield 'temporary HTTP failure' => [new ServiceUnavailableHttpException(17, 'private exception details'), 503, 'internal_error'];
        yield 'unexpected application failure' => [new \RuntimeException('private exception details'), 500, 'internal_error'];
    }
}
