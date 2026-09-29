<?php

declare(strict_types=1);

namespace App\Core\Organization\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationAccessPolicy;
use App\Core\Organization\Infrastructure\PostgresOrganizationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class OrganizationAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        PostgresOrganizationRepository $organizations,
        OrganizationAccessPolicy $access,
        ResponseFactory $responses,
    ): ResponseInterface {
        $identity = AuthenticatedIdentity::fromRequest($request);
        $operation = (string) $route->getArgument('operation');
        if ($operation === 'list') {
            $personId = $identity->hasRole('core.superadmin') ? null : $identity->personId;
            if ($personId === null && !$identity->hasRole('core.superadmin')) {
                return $responses->success([]);
            }
            return $responses->success(array_map(
                static fn($organization): array => $organization->toArray(),
                $organizations->listFor($personId),
            ));
        }
        if ($operation === 'create') {
            if (!$identity->hasRole('core.superadmin')) {
                return $responses->error(ApiMessage::AUTH_PERMISSION_DENIED, httpCode: Status::FORBIDDEN);
            }
            $input = OrganizationInput::organization($request->getParsedBody());
            $organization = $organizations->create($input['code'], $input['name']);
            return $organization === null
                ? $responses->error(ApiMessage::VALIDATION_FAILED, httpCode: Status::CONFLICT)
                : $responses->success($organization->toArray())->withStatus(Status::CREATED);
        }

        $organizationId = (string) $route->getArgument('id');
        if (!Uuid::isValid($organizationId)) {
            return $responses->error(ApiMessage::VALIDATION_FAILED);
        }
        $permission = $operation === 'members' ? 'core.organization_memberships.read' : 'core.organization_memberships.manage';
        if (!$access->canAccess($identity, $organizationId, $permission)) {
            return $responses->error(ApiMessage::AUTH_PERMISSION_DENIED, httpCode: Status::FORBIDDEN);
        }
        if ($operation === 'members') {
            return $responses->success(array_map(
                static fn($membership): array => $membership->toArray(),
                $organizations->members($organizationId),
            ));
        }
        if ($operation === 'add') {
            $personId = OrganizationInput::member($request->getParsedBody());
            $membership = $organizations->addMember($organizationId, $personId);
            return $membership === null
                ? $responses->error(ApiMessage::VALIDATION_FAILED, httpCode: Status::CONFLICT)
                : $responses->success($membership->toArray())->withStatus(Status::CREATED);
        }
        $personId = (string) $route->getArgument('personId');
        if (!Uuid::isValid($personId)) {
            return $responses->error(ApiMessage::VALIDATION_FAILED);
        }
        if ($operation !== 'leave') {
            return $responses->notFound();
        }
        $membership = $organizations->leave($organizationId, $personId);
        return $membership === null
            ? $responses->notFound()
            : $responses->success($membership->toArray());
    }
}
