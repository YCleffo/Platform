<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Shared;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ExceptionResponderFactory;
use App\Api\Shared\ResponseFactory;
use App\Core\Internationalization\Application\LocaleNormalizer;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Internationalization\Application\TranslationService;
use Codeception\Test\Unit;
use HttpSoft\Message\ResponseFactory as PsrResponseFactory;
use HttpSoft\Message\ServerRequest;
use LogicException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\DataResponse\DataStream\DataStream;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactory;
use Yiisoft\Di\Container;
use Yiisoft\ErrorHandler\Exception\UserException;
use Yiisoft\ErrorHandler\Middleware\ExceptionResponder;
use Yiisoft\Injector\Injector;
use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\RequestProvider\RequestProvider;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\IntlMessageFormatter;
use Yiisoft\Translator\Message\Php\MessageSource;
use Yiisoft\Translator\Translator;
use Yiisoft\Validator\Result;

final class ExceptionResponderFactoryTest extends Unit
{
    public function testInputValidationException(): void
    {
        $request = new ServerRequest();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new InputValidationException(
                    (new Result())->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['name']),
                );
            }
        };

        $response = $this->createExceptionResponder()->process($request, $handler);
        $body = $response->getBody();

        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(
            [
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'Validation failed.',
                    'fields' => [
                        'name' => [[
                            'code' => 'validation.required',
                            'message' => 'This field is required.',
                        ]],
                    ],
                ],
            ],
            $body->getData(),
        );
    }

    public function testUserException(): void
    {
        $request = new ServerRequest();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new UserException('Hello, Exception!');
            }
        };

        $response = $this->createExceptionResponder()->process($request, $handler);
        $body = $response->getBody();

        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(
            [
                'error' => [
                    'code' => 'http.bad_request',
                    'message' => 'The request is invalid.',
                ],
            ],
            $body->getData(),
        );
    }

    public function testOtherThrowable(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
        $request = new ServerRequest();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new LogicException(
                    'secret-password=NeverExpose; SQL=SELECT * FROM credentials; path=C:\\private\\source.php',
                );
            }
        };

        $response = $this->createExceptionResponder($logger)->process($request, $handler);
        $body = $response->getBody();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(
            [
                'error' => [
                    'code' => 'http.internal_error',
                    'message' => 'An internal server error occurred.',
                ],
            ],
            $body->getData(),
        );
        $this->assertSame([
            [
                'level' => 'error',
                'message' => 'Unhandled API exception.',
                'context' => [
                    'errorCode' => 'http.internal_error',
                    'exceptionType' => LogicException::class,
                ],
            ],
        ], $logger->records);
        $serializedResponse = json_encode($body->getData(), JSON_THROW_ON_ERROR);
        $serializedLog = json_encode($logger->records, JSON_THROW_ON_ERROR);
        foreach (['NeverExpose', 'SELECT *', 'private\\source.php'] as $secret) {
            $this->assertStringNotContainsString($secret, $serializedResponse);
            $this->assertStringNotContainsString($secret, $serializedLog);
        }
    }

    private function createExceptionResponder(?LoggerInterface $logger = null): ExceptionResponder
    {
        $formatter = new IntlMessageFormatter();
        $translator = new Translator(
            locale: 'en-US',
            fallbackLocale: 'en-US',
            defaultMessageFormatter: $formatter,
        );
        $translator->addCategorySources(new CategorySource(
            'app',
            new MessageSource(dirname(__DIR__, 4) . '/resources/messages'),
            $formatter,
        ));

        return (new ExceptionResponderFactory(
            new PsrResponseFactory(),
            new ResponseFactory(
                new DataResponseFactory(
                    new PsrResponseFactory(),
                ),
                new TranslationService($translator),
                new SupportedLocales(new LocaleNormalizer(), ['en-US', 'ru-RU'], 'en-US'),
                new RequestProvider(),
            ),
            new Injector(new Container()),
            $logger ?? new \Psr\Log\NullLogger(),
        ))->create();
    }
}
