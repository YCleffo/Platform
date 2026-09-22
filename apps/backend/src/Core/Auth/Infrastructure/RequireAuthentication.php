<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\AuthenticationService;
use App\Core\Client\Domain\ClientContext;
use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Application\LocaleResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\RequestProvider\RequestProviderInterface;

final readonly class RequireAuthentication implements MiddlewareInterface
{
    public function __construct(
        private AuthenticationService $authentication,
        private ResponseFactory $responses,
        private LocaleResolver $localeResolver,
        private RequestProviderInterface $requestProvider,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        $token = preg_match('/\ABearer ([A-Za-z0-9_-]+)\z/', $authorization, $matches) === 1
            ? $matches[1]
            : null;
        $identity = $token === null
            ? null
            : $this->authentication->authenticate($token, ClientContext::fromRequest($request));

        if ($identity === null) {
            return $this->responses
                ->error(ApiMessage::AUTH_REQUIRED, httpCode: Status::UNAUTHORIZED)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        $client = ClientContext::fromRequest($request);
        $localizedClient = $client->withLocale(LocaleContext::fromResolved(
            $this->localeResolver->resolveAuthenticated(
                $client->locale,
                $identity->locale,
                $client->acceptLanguage,
            ),
        ));
        $request = $request
            ->withAttribute(ClientContext::ATTRIBUTE, $localizedClient)
            ->withAttribute(AuthenticatedIdentity::ATTRIBUTE, $identity);
        $this->requestProvider->set($request);

        return $handler->handle($request);
    }
}
