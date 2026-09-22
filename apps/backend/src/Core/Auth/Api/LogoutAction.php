<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\Logout;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class LogoutAction
{
    public function __invoke(
        ServerRequestInterface $request,
        Logout $logout,
        ResponseFactoryInterface $responses,
    ): ResponseInterface {
        $logout->execute(
            AuthenticatedIdentity::fromRequest($request),
            ClientContext::fromRequest($request),
        );

        return $responses->createResponse(Status::NO_CONTENT);
    }
}
