<?php

declare(strict_types=1);

namespace App\Workspace\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Shared\Application\HttpError;
use App\Workspace\Application\WorkspaceDashboardService;
use App\Workspace\Application\WorkspaceOperationService;
use App\Workspace\Application\WorkspacePreferencesService;
use App\Workspace\Application\WorkspaceSearchService;
use App\Workspace\Application\WorkspaceTrashService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class WorkspaceAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        WorkspaceDashboardService $dashboard,
        WorkspaceSearchService $search,
        WorkspaceOperationService $operations,
        WorkspaceTrashService $trash,
        WorkspacePreferencesService $preferences,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $identity = AuthenticatedIdentity::fromRequest($request);
            $operation = (string) $route->getArgument('operation');
            $idArg = $route->getArgument('id');
            $id = is_string($idArg) ? $idArg : '';

            $body = $request->getParsedBody();
            /** @var array<string, mixed> $body */
            $body = is_array($body) ? $body : [];
            /** @var array<string, mixed> $query */
            $query = $request->getQueryParams();

            $orgId = $request->getHeaderLine('X-Education-Organization');
            if ($orgId === '') {
                $orgId = (string) ($request->getHeaderLine('X-Platform-Organization') ?: ($body['organizationId'] ?? $query['organizationId'] ?? ''));
            }
            if ($orgId === '') {
                throw new HttpError(400, 'missing_organization_header');
            }

            $studentIdParam = is_string($query['studentId'] ?? null) ? $query['studentId'] : null;

            $result = match ($operation) {
                'dashboard' => $dashboard->getDashboard($identity, $orgId, $studentIdParam),
                'search' => $search->search($identity, $orgId, $query),
                'preferences_get' => $preferences->getAll($identity, $orgId),
                'preferences_set' => $preferences->set($identity, $orgId, $body),
                'execute_action' => $operations->execute($identity, $orgId, $body),
                'list_operations' => $operations->listOperations($identity, $orgId, $query),
                'track_operation' => $operations->trackOperation($identity, $orgId, $body),
                'undo_operation' => $operations->undo($identity, $orgId, $id),
                'cancel_operation' => $operations->cancel($identity, $orgId, $id),
                'retry_operation' => $operations->retry($identity, $orgId, $id),
                'list_trash' => $trash->listTrash($identity, $orgId, $query),
                'move_to_trash' => $trash->moveToTrash(
                    $identity,
                    $orgId,
                    (string) ($body['entityType'] ?? ''),
                    (string) ($body['entityId'] ?? ''),
                ),
                'restore_trash' => $trash->restore(
                    $identity,
                    $orgId,
                    $id,
                    ($body['renameOnConflict'] ?? false) === true,
                ),
                'delete_trash' => $trash->deletePermanent($identity, $orgId, $id),
                'empty_trash' => $trash->emptyTrash($identity, $orgId),
                default => throw new HttpError(404, 'not_found'),
            };

            $response = $responses->success($result);
            if ($request->getMethod() === 'POST' && in_array($operation, ['move_to_trash', 'track_operation'], true)) {
                return $response->withStatus(Status::CREATED);
            }
            return $response;
        } catch (HttpError $error) {
            return $responses->error($error->reason, $error->status, meta: $error->meta);
        }
    }
}
