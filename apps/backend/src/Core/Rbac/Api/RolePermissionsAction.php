<?php

declare(strict_types=1);

namespace App\Core\Rbac\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Rbac\Application\ManageCustomRoles;
use App\Core\Shared\Application\ConflictException;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class RolePermissionsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ManageCustomRoles $management,
        ResponseFactory $responses,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        if (!Uuid::isValid($id)) {
            return $responses->error(ApiMessage::ROLE_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }
        if ($request->getMethod() === Method::GET) {
            $codes = $management->permissionCodes($id);
            return $codes === null ? $responses->notFound(ApiMessage::RBAC_TARGET_NOT_FOUND) : $responses->success(['permissions' => $codes]);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_keys($body) !== ['permissions']
            || !is_array($body['permissions']) || !array_is_list($body['permissions'])) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        try {
            $codes = $management->replacePermissions(
                AuthenticatedIdentity::fromRequest($request)->accountId,
                $id,
                $body['permissions'],
                ClientContext::fromRequest($request),
            );
            return $codes === null ? $responses->notFound(ApiMessage::RBAC_TARGET_NOT_FOUND) : $responses->success(['permissions' => $codes]);
        } catch (ConflictException) {
            return $responses->error(ApiMessage::RBAC_CONFLICT, httpCode: Status::CONFLICT);
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
