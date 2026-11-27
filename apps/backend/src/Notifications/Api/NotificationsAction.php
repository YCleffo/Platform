<?php

declare(strict_types=1);

namespace App\Notifications\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Notifications\Application\NotificationsService;
use App\Shared\Application\HttpError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class NotificationsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        NotificationsService $notifications,
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

            $result = match ($operation) {
                'list' => $notifications->listNotifications($identity, $orgId, $query),
                'mark_read' => $notifications->markRead($identity, $orgId, $id),
                'mark_all_read' => $notifications->markAllRead(
                    $identity,
                    $orgId,
                    isset($body['category']) && is_string($body['category']) ? $body['category'] : null,
                ),
                'bulk_read' => $notifications->bulkRead($identity, $orgId, $body),
                'bulk_archive' => $notifications->bulkArchive($identity, $orgId, $body),
                'push_status' => $notifications->pushStatus($identity, $orgId),
                'preferences_get' => ['items' => $notifications->preferences($identity, $orgId)],
                'preferences_update' => $notifications->updatePreference($identity, $orgId, $body),
                'push_subscribe' => $notifications->registerPushSubscription($identity, $orgId, $body),
                'push_revoke' => $notifications->revokePushSubscription($identity, $orgId, $id),
                default => throw new HttpError(404, 'not_found'),
            };

            return $responses->success($result);
        } catch (HttpError $error) {
            return $responses->error($error->reason, $error->status);
        }
    }
}
