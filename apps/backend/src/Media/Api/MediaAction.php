<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Media\Application\MediaError;
use App\Media\Application\MediaScope;
use App\Media\Application\MediaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class MediaAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        MediaService $service,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $operation = (string) $route->getArgument('operation');
            $id = $route->getArgument('id');
            $id = is_string($id) ? $id : null;

            $identity = AuthenticatedIdentity::fromRequest($request);
            if ($identity->personId === null) {
                throw new MediaError(403, 'person_required');
            }
            $organizationId = $request->getHeaderLine('X-Education-Organization');
            if ($organizationId === '') {
                throw new MediaError(400, 'missing_organization_header');
            }

            $scope = new MediaScope($organizationId, $identity->accountId, $identity->personId);

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $query = $request->getQueryParams();

            /** @var array<string, mixed>|null $meta */
            $meta = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : null;

            $result = match ($operation) {
                'reserve' => $service->reserveUpload(
                    $scope,
                    (string) ($body['kind'] ?? ''),
                    (string) ($body['name'] ?? ''),
                    (string) ($body['mime'] ?? ''),
                    (int) ($body['sizeBytes'] ?? 0),
                    (string) ($body['sha256'] ?? ''),
                ),
                'confirm' => $service->confirmUpload(
                    $scope,
                    $id ?? throw new MediaError(404, 'not_found'),
                    $meta,
                ),
                'get' => $service->asset(
                    $scope,
                    $id ?? throw new MediaError(404, 'not_found'),
                ) ?? throw new MediaError(404, 'not_found'),
                'playback' => $service->playbackUrl(
                    $scope,
                    $id ?? throw new MediaError(404, 'not_found'),
                    isset($query['variant']) && is_string($query['variant']) ? $query['variant'] : null,
                ),
                'list' => $service->listAssets(
                    $scope,
                    isset($query['kind']) && is_string($query['kind']) ? $query['kind'] : null,
                    isset($query['offset']) && is_numeric($query['offset']) ? (int) $query['offset'] : 0,
                ),
                default => throw new MediaError(404, 'not_found'),
            };

            return $responses->success($result);
        } catch (MediaError $error) {
            return $responses->error($error->reason, $error->status);
        }
    }
}
