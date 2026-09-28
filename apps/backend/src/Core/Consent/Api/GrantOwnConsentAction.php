<?php

declare(strict_types=1);

namespace App\Core\Consent\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Consent\Application\ConsentService;
use App\Core\Consent\Application\UnknownConsentException;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class GrantOwnConsentAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ConsentService $consents,
        ResponseFactory $responses,
    ): ResponseInterface {
        $input = $request->getParsedBody();
        if ($input !== null && $input !== []) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        $context = ClientContext::fromRequest($request);
        try {
            $result = $consents->grant(
                AuthenticatedIdentity::fromRequest($request)->accountId,
                (string) $route->getArgument('code'),
                $context->locale->locale->tag,
                $context->requestId,
            );
        } catch (UnknownConsentException) {
            return $responses->error(ApiMessage::HTTP_NOT_FOUND, httpCode: Status::NOT_FOUND);
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $responses->success($result);
    }
}
