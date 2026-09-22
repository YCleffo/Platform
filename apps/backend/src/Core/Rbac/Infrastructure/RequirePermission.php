<?php

declare(strict_types=1);

namespace App\Core\Rbac\Infrastructure;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Rbac\Application\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

final readonly class RequirePermission implements MiddlewareInterface
{
    public function __construct(
        private string $permission,
        private PermissionService $permissions,
        private ResponseFactory $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = AuthenticatedIdentity::fromRequest($request);
        if (!$this->permissions->hasPermission($identity->accountId, $this->permission)) {
            return $this->responses->error(ApiMessage::AUTH_PERMISSION_DENIED, httpCode: Status::FORBIDDEN);
        }

        return $handler->handle($request);
    }
}
