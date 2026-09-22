<?php

declare(strict_types=1);

namespace App\Core\Account\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Account\Application\ManageAccountLifecycle;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Shared\Application\ConflictException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class AccountLifecycleAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ManageAccountLifecycle $lifecycle,
        ResponseFactory $responses,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        if (!Uuid::isValid($id)) {
            return $responses->error(ApiMessage::ACCOUNT_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }
        $operation = (string) $route->getArgument('operation');
        $identity = AuthenticatedIdentity::fromRequest($request);
        try {
            $change = $lifecycle->execute(
                $operation,
                $id,
                $identity->accountId,
                ClientContext::fromRequest($request),
            );
        } catch (ConflictException) {
            return $responses->error(ApiMessage::ACCOUNT_CONFLICT, httpCode: Status::CONFLICT);
        }

        return $change === null
            ? $responses->notFound(ApiMessage::ACCOUNT_NOT_FOUND)
            : $responses->success([
                'id' => $change->accountId,
                'status' => $change->afterStatus,
                'revokedSessions' => $change->revokedSessions,
            ]);
    }
}
