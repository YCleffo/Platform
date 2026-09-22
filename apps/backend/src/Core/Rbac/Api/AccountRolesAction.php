<?php

declare(strict_types=1);

namespace App\Core\Rbac\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Rbac\Application\ManageAccountRoles;
use App\Core\Rbac\Application\RoleView;
use App\Core\Shared\Application\ConflictException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class AccountRolesAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ManageAccountRoles $roles,
        ResponseFactory $responses,
    ): ResponseInterface {
        $accountId = (string) $route->getArgument('id');
        if (!Uuid::isValid($accountId)) {
            return $responses->error(ApiMessage::ACCOUNT_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }

        if ($request->getMethod() === Method::GET) {
            return $responses->success(array_map(
                static fn(RoleView $role): array => $role->toArray(),
                $roles->list($accountId),
            ));
        }

        $roleId = (string) $route->getArgument('roleId');
        if (!Uuid::isValid($roleId)) {
            return $responses->error(ApiMessage::ROLE_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }
        $identity = AuthenticatedIdentity::fromRequest($request);
        try {
            $result = $request->getMethod() === Method::POST
                ? $roles->assign(
                    $identity->accountId,
                    $accountId,
                    $roleId,
                    ClientContext::fromRequest($request),
                )
                : $roles->revoke(
                    $identity->accountId,
                    $accountId,
                    $roleId,
                    ClientContext::fromRequest($request),
                );
        } catch (ConflictException) {
            return $responses->error(ApiMessage::RBAC_CONFLICT, httpCode: Status::CONFLICT);
        }

        return $result === null
            ? $responses->notFound(ApiMessage::RBAC_TARGET_NOT_FOUND)
            : $responses->success(['changed' => $result]);
    }
}
