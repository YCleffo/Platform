<?php

declare(strict_types=1);

namespace App\Storage\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\DeviceManagement\Application\DeviceIdentity;
use App\Storage\Application\StorageAccess;
use App\Storage\Application\StorageError;
use App\Storage\Application\StorageService;
use HttpSoft\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class StorageAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        StorageAccess $access,
        StorageService $service,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $operation = (string) $route->getArgument('operation');
            $id = $route->getArgument('id');
            $id = is_string($id) ? $id : null;
            $body = $request->getParsedBody();
            if ($body !== null && !is_array($body)) {
                throw new StorageError(422, 'invalid_input');
            }
            /** @var array<string,mixed> $body */
            $body = $body ?? [];
            $device = $request->getAttribute(DeviceIdentity::ATTRIBUTE);
            if ($device instanceof DeviceIdentity) {
                $result = match ($operation) {
                    'agent_storage_manifest' => $service->agentManifest($device->deviceId, $body),
                    'agent_storage_manifest_read' => $service->agentConfirmedManifest($device->deviceId),
                    'agent_storage_ticket' => $service->agentTicket($device->deviceId, $body),
                    'agent_storage_sync' => $service->agentSync($device->deviceId, $body),
                    'agent_storage_space' => $service->agentSpace($device->deviceId),
                    default => throw new StorageError(404, 'not_found'),
                };
                return $responses->success($result);
            }
            $agentDevice = $device instanceof DeviceIdentity ? $device->deviceId : null;
            if (in_array($operation, ['upload', 'agent_storage_upload'], true)) {
                $token = (string) ($request->getQueryParams()['ticket'] ?? '');
                $claimed = $request->getHeaderLine('X-Content-Sha256');
                $stream = $request->getBody();
                $resource = fopen('php://temp', 'w+b');
                if ($resource === false) {
                    throw new StorageError(503, 'storage_unavailable');
                }
                $stream->rewind();
                $total = 0;
                while (!$stream->eof()) {
                    $chunk = $stream->read(65536);
                    $total += strlen($chunk);
                    if ($total > 268435456) {
                        fclose($resource);
                        throw new StorageError(413, 'file_too_large');
                    }
                    fwrite($resource, $chunk);
                }
                rewind($resource);
                return $responses->success($service->uploadWithTicket($token, $resource,
                    $claimed === '' ? null : $claimed, $agentDevice));
            }
            if (in_array($operation, ['download', 'agent_storage_download'], true)) {
                $token = (string) ($request->getQueryParams()['ticket'] ?? '');
                $file = $service->downloadWithTicket($token, $agentDevice);
                return new \Yiisoft\Http\Response(Status::OK, [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) $file['size'],
                    'Content-Disposition' => 'attachment; filename="' . $file['name'] . '"',
                    'X-Content-Sha256' => $file['sha256'],
                ], new Stream($file['stream']));
            }
            $identity = AuthenticatedIdentity::fromRequest($request);
            $scope = $access->studentScope($identity, $request->getHeaderLine('X-Education-Organization'));
            if ($request->getMethod() === 'GET') {
                return $responses->success($service->read($operation, $scope, $request->getQueryParams(), $id));
            }
            return $responses->success($service->write($operation, $scope, $body, $id))->withStatus(
                $operation === 'ticket' ? 201 : 200);
        } catch (StorageError $error) {
            $code = match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED, 404 => ApiMessage::HTTP_NOT_FOUND,
                503 => ApiMessage::HTTP_SERVICE_UNAVAILABLE, default => ApiMessage::VALIDATION_FAILED,
            };
            return $responses->error($code, httpCode: $error->status, meta: ['storageCode' => $error->reason]);
        }
    }
}
