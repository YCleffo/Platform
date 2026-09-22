<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Shared;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Internationalization\Application\LocaleNormalizer;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Internationalization\Application\TranslationService;
use Codeception\Test\Unit;
use HttpSoft\Message\ResponseFactory as PsrResponseFactory;
use Yiisoft\DataResponse\DataStream\DataStream;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactory;
use Yiisoft\RequestProvider\RequestProvider;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\IntlMessageFormatter;
use Yiisoft\Translator\Message\Php\MessageSource;
use Yiisoft\Translator\Translator;
use Yiisoft\Validator\Result;

final class ResponseFactoryTest extends Unit
{
    public function testSuccess(): void
    {
        $response = $this
            ->createResponseFactory()
            ->success(['name' => 'test']);

        $body = $response->getBody();

        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(
            [
                'status' => 'success',
                'data' => ['name' => 'test'],
            ],
            $body->getData(),
        );
        $this->assertSame('en-US', $response->getHeaderLine('Content-Language'));
        $this->assertSame('Accept-Language, X-Client-Locale', $response->getHeaderLine('Vary'));
    }

    public function testError(): void
    {
        $response = $this
            ->createResponseFactory()
            ->error(ApiMessage::HTTP_BAD_REQUEST);

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

    public function testNotFound(): void
    {
        $response = $this
            ->createResponseFactory()
            ->notFound();

        $body = $response->getBody();

        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(
            [
                'error' => [
                    'code' => 'http.not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ],
            $body->getData(),
        );
    }

    public function testFailValidation(): void
    {
        $result = (new Result())
            ->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['name'])
            ->addError(ApiMessage::VALIDATION_NOT_BLANK, valuePath: ['name'])
            ->addError(ApiMessage::VALIDATION_INTEGER, valuePath: ['age']);
        $response = $this
            ->createResponseFactory()
            ->failValidation($result);

        $body = $response->getBody();

        $this->assertInstanceOf(DataStream::class, $body);
        $this->assertSame(
            [
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'Validation failed.',
                    'fields' => [
                        'name' => [
                            ['code' => 'validation.required', 'message' => 'This field is required.'],
                            ['code' => 'validation.not_blank', 'message' => 'The value must not be blank.'],
                        ],
                        'age' => [
                            ['code' => 'validation.integer', 'message' => 'The value must be an integer.'],
                        ],
                    ],
                ],
            ],
            $body->getData(),
        );
    }

    public function testLocaleHeadersPreserveExistingVaryValues(): void
    {
        $response = $this->createResponseFactory()->withLocaleHeaders(
            (new PsrResponseFactory())->createResponse()->withHeader('Vary', 'Origin, Accept-Encoding'),
        );

        $this->assertSame('en-US', $response->getHeaderLine('Content-Language'));
        $this->assertSame('Origin, Accept-Encoding, Accept-Language, X-Client-Locale', $response->getHeaderLine('Vary'));
    }

    public function testAllStandardHttpErrorsHaveStableLocalizedContracts(): void
    {
        $expected = [
            400 => 'http.bad_request',
            401 => 'http.unauthorized',
            403 => 'http.forbidden',
            404 => 'http.not_found',
            405 => 'http.method_not_allowed',
            409 => 'http.conflict',
            413 => 'http.payload_too_large',
            415 => 'http.unsupported_media_type',
            422 => 'http.unprocessable_entity',
            429 => 'http.too_many_requests',
            500 => 'http.internal_error',
            503 => 'http.service_unavailable',
        ];

        foreach ($expected as $status => $code) {
            $response = $this->createResponseFactory()->forStatus($status);
            $body = $response->getBody();
            $this->assertSame($status, $response->getStatusCode());
            $this->assertInstanceOf(DataStream::class, $body);
            $data = $body->getData();
            $this->assertIsArray($data);
            $this->assertSame($code, $data['error']['code']);
            $this->assertNotSame('', $data['error']['message']);
        }
    }

    private function createResponseFactory(): ResponseFactory
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

        return new ResponseFactory(
            new DataResponseFactory(
                new PsrResponseFactory(),
            ),
            new TranslationService($translator),
            new SupportedLocales(new LocaleNormalizer(), ['en-US', 'ru-RU'], 'en-US'),
            new RequestProvider(),
        );
    }
}
