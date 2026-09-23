<?php

declare(strict_types=1);

namespace App\Api\Shared;

use App\Api\Shared\Presenter\AsIsPresenter;
use App\Api\Shared\Presenter\PresenterInterface;
use App\Api\Shared\Presenter\SuccessPresenter;
use App\Core\Client\Domain\ClientContext;
use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Internationalization\Application\TranslationService;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\RequestProvider\RequestNotSetException;
use Yiisoft\RequestProvider\RequestProviderInterface;
use Yiisoft\Validator\Result;

final readonly class ResponseFactory
{
    public function __construct(
        private DataResponseFactoryInterface $dataResponseFactory,
        private TranslationService $translations,
        private SupportedLocales $supportedLocales,
        private RequestProviderInterface $requestProvider,
    ) {}

    public function success(
        array|object|null $data = null,
        PresenterInterface $presenter = new AsIsPresenter(),
    ): ResponseInterface {
        return $this->withLocaleHeaders($this->dataResponseFactory->createResponse(
            (new SuccessPresenter($presenter))->present($data),
        ));
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @param array<string, non-empty-list<array{code: string, message: string}>>|null $fields
     * @param array<string, mixed>|null $meta
     */
    public function error(
        string $messageId,
        int $httpCode = Status::BAD_REQUEST,
        array $parameters = [],
        ?array $fields = null,
        ?array $meta = null,
    ): ResponseInterface {
        [$locale, $requestId] = $this->requestContext();
        $error = [
            'code' => $messageId,
            'message' => $this->translations->translate($messageId, $locale, $parameters),
        ];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }
        if ($meta !== null) {
            $error['meta'] = $meta;
        }
        if ($requestId !== null) {
            $error['requestId'] = $requestId;
        }

        return $this->withLocaleHeaders(
            $this->dataResponseFactory
                ->createResponse(['error' => $error])
                ->withStatus($httpCode),
            $locale,
        );
    }

    public function notFound(string $messageId = ApiMessage::HTTP_NOT_FOUND): ResponseInterface
    {
        return $this->error($messageId, httpCode: Status::NOT_FOUND);
    }

    public function failValidation(Result $result): ResponseInterface
    {
        [$locale] = $this->requestContext();
        $fields = [];
        foreach ($result->getErrors() as $error) {
            $path = implode('.', $error->getValuePath('.'));
            $code = $error->getMessage();
            $fields[$path][] = [
                'code' => $code,
                'message' => $this->translations->translate($code, $locale, $error->getParameters()),
            ];
        }

        return $this->error(
            ApiMessage::VALIDATION_FAILED,
            httpCode: Status::UNPROCESSABLE_ENTITY,
            fields: $fields,
        );
    }

    public function forStatus(int $status): ResponseInterface
    {
        return $this->error(ApiMessage::forStatus($status), httpCode: $status);
    }

    public function withLocaleHeaders(
        ResponseInterface $response,
        ?LocaleContext $locale = null,
    ): ResponseInterface {
        [$locale] = $locale === null ? $this->requestContext() : [$locale];
        $vary = array_values(array_filter(array_map(
            trim(...),
            explode(',', $response->getHeaderLine('Vary')),
        )));
        $lowercaseVary = array_map(strtolower(...), $vary);
        foreach (['Accept-Language', 'X-Client-Locale'] as $header) {
            if (!in_array(strtolower($header), $lowercaseVary, true)) {
                $vary[] = $header;
            }
        }

        return $response
            ->withHeader('Content-Language', $locale->locale->tag)
            ->withHeader('Vary', implode(', ', $vary));
    }

    /** @return array{LocaleContext, ?string} */
    private function requestContext(): array
    {
        try {
            $client = ClientContext::fromRequest($this->requestProvider->get());
            return [$client->locale, $client->requestId];
        } catch (RequestNotSetException | RuntimeException) {
            return [new LocaleContext($this->supportedLocales->default(), 'default'), null];
        }
    }
}
