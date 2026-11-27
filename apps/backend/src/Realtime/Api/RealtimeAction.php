<?php

declare(strict_types=1);

namespace App\Realtime\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Realtime\Application\RealtimeService;
use App\Shared\Application\HttpError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class RealtimeAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        RealtimeService $realtime,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $identity = AuthenticatedIdentity::fromRequest($request);
            $personId = $identity->personId;
            if ($personId === null || $personId === '') {
                throw new HttpError(403, 'access_denied');
            }

            $operation = (string) $route->getArgument('operation');
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
                'ticket' => $realtime->issueWsTicket($identity, $orgId),
                'events' => [
                    'items' => $realtime->sinceSeq(
                        $identity->accountId,
                        $personId,
                        RealtimeService::assertUuid($orgId, 'organizationId'),
                        (string) ($query['channel'] ?? ''),
                        isset($query['sinceSeq']) && is_numeric($query['sinceSeq']) ? (int) $query['sinceSeq'] : 0,
                        isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 200,
                    ),
                    'latestSeq' => $realtime->latestSeq(RealtimeService::assertUuid($orgId, 'organizationId')),
                ],
                'typing' => (function () use ($realtime, $identity, $personId, $orgId, $body): array {
                    $realtime->setTyping(
                        $identity->accountId,
                        $personId,
                        $orgId,
                        (string) ($body['roomId'] ?? ''),
                        (bool) ($body['typing'] ?? true),
                    );
                    return ['ok' => true];
                })(),
                'presence_update' => (function () use ($realtime, $request, $personId, $orgId, $body): array {
                    $online = ($body['status'] ?? 'online') !== 'offline';
                    $realtime->setPresence(
                        $orgId,
                        $personId,
                        $online,
                        ClientContext::fromRequest($request)->clientInstanceId,
                    );
                    return ['status' => $online ? 'online' : 'offline'];
                })(),
                'presence_query' => [
                    'items' => $realtime->getPresences(
                        $orgId,
                        (static function () use ($query, $body, $personId): array {
                            $qPids = $query['personIds'] ?? null;
                            if (is_string($qPids)) {
                                return explode(',', $qPids);
                            }
                            $bPids = $body['personIds'] ?? null;
                            if (is_array($bPids)) {
                                return array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $bPids));
                            }
                            return [$personId];
                        })(),
                    ),
                ],
                default => throw new HttpError(404, 'not_found'),
            };

            return $responses->success($result);
        } catch (HttpError $error) {
            return $responses->error($error->reason, $error->status);
        }
    }
}
