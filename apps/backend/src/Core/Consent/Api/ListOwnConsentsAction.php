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

final readonly class ListOwnConsentsAction
{
    public function __invoke(ServerRequestInterface $request, ConsentService $consents, ResponseFactory $responses): ResponseInterface
    {
        try {
            return $responses->success($consents->list(AuthenticatedIdentity::fromRequest($request)->accountId));
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
