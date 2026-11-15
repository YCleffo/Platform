<?php

declare(strict_types=1);

namespace App\DeviceManagement\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\DeviceManagement\Application\DeviceAccess;
use App\DeviceManagement\Application\DeviceError;
use App\DeviceManagement\Application\DeviceIdentity;
use App\DeviceManagement\Application\DeviceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class DeviceAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        DeviceAccess $access,
        DeviceService $service,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $operation = (string) $route->getArgument('operation');
            $id = $route->getArgument('id');
            $id = is_string($id) ? $id : null;
            $body = $request->getParsedBody();
            if ($body !== null && !is_array($body)) {
                throw new DeviceError(422, 'invalid_input');
            }
            /** @var array<string,mixed> $body */
            $body = $body ?? [];
            $device = $request->getAttribute(DeviceIdentity::ATTRIBUTE);
            if ($device instanceof DeviceIdentity) {
                $result = match ($operation) {
                    'agent_heartbeat' => $service->agentHeartbeat($device->deviceId, $body),
                    'agent_commands' => $service->agentCommands($device->deviceId),
                    'agent_command_ack' => $service->agentAcknowledge($device->deviceId, (string) $id, $body),
                    'agent_session_state' => $service->agentSessionState($device->deviceId, (string) $id, $body),
                    'agent_session_end' => $service->agentSessionEnd($device->deviceId, (string) $id, $body),
                    'agent_session' => $service->agentSession($device->deviceId),
                    'agent_event' => $service->agentEvent($device->deviceId, $body),
                    default => throw new DeviceError(404, 'not_found'),
                };
                return $responses->success($result);
            }
            if ($operation === 'agent_enroll') {
                return $responses->success($service->agentEnroll($body))->withStatus(201);
            }
            $identity = AuthenticatedIdentity::fromRequest($request);
            $scope = $access->scope($identity, $request->getHeaderLine('X-Education-Organization'));
            if ($request->getMethod() === 'GET') {
                return $responses->success($service->read($operation, $scope, $request->getQueryParams(), $id));
            }
            $result = $service->write($operation, $scope, $body, $id);
            return $responses->success($result)->withStatus(in_array($operation,
                ['create_classroom', 'create_device'], true) ? 201 : 200);
        } catch (DeviceError $error) {
            $code = match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED, 404 => ApiMessage::HTTP_NOT_FOUND,
                503 => ApiMessage::HTTP_SERVICE_UNAVAILABLE, default => ApiMessage::VALIDATION_FAILED,
            };
            return $responses->error($code, httpCode: $error->status, meta: ['deviceCode' => $error->reason]);
        }
    }
}
