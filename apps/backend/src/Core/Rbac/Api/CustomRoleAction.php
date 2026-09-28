<?php

declare(strict_types=1);

namespace App\Core\Rbac\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Rbac\Application\ManageCustomRoles;
use App\Core\Rbac\Application\RoleRepository;
use App\Core\Shared\Application\ConflictException;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class CustomRoleAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ManageCustomRoles $management,
        RoleRepository $roles,
        ResponseFactory $responses,
    ): ResponseInterface {
        $id = $route->getArgument('id');
        if ($id !== null && !Uuid::isValid($id)) {
            return $responses->error(ApiMessage::ROLE_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }
        $actor = AuthenticatedIdentity::fromRequest($request)->accountId;
        $context = ClientContext::fromRequest($request);
        $method = $request->getMethod();
        try {
            if ($method === Method::DELETE && is_string($id)) {
                return $management->delete($actor, $id, $context)
                    ? $responses->success(['deleted' => true])
                    : $responses->notFound(ApiMessage::RBAC_TARGET_NOT_FOUND);
            }
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            if (($body !== [] && array_is_list($body))
                || array_diff(array_keys($body), ['code', 'name', 'description']) !== []) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            $existing = $method === Method::PATCH && is_string($id) ? $roles->findById($id) : null;
            if ($method === Method::PATCH && $existing === null) {
                return $responses->notFound(ApiMessage::RBAC_TARGET_NOT_FOUND);
            }
            $code = $body['code'] ?? $existing?->code;
            $name = $body['name'] ?? $existing?->name;
            $description = array_key_exists('description', $body) ? $body['description'] : $existing?->description;
            if (!is_string($code) || !is_string($name) || ($description !== null && !is_string($description))) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            $role = $method === Method::POST
                ? $management->create($actor, $code, $name, $description, $context)
                : $management->update($actor, (string) $id, $code, $name, $description, $context);
            return $role === null ? $responses->notFound(ApiMessage::RBAC_TARGET_NOT_FOUND) : $responses->success($role->toArray());
        } catch (ConflictException) {
            return $responses->error(ApiMessage::RBAC_CONFLICT, httpCode: Status::CONFLICT);
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
