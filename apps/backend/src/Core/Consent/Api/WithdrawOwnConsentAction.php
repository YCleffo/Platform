<?php

declare(strict_types=1);

namespace App\Core\Consent\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Consent\Application\ConsentService;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class WithdrawOwnConsentAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ConsentService $consents,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $withdrawn = $consents->withdraw(
                AuthenticatedIdentity::fromRequest($request)->accountId,
                (string) $route->getArgument('code'),
            );
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $withdrawn ? $responses->success() : $responses->notFound();
    }
}
