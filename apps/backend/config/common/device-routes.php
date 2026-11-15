<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\DeviceManagement\Api\DeviceAction;
use App\DeviceManagement\Infrastructure\RequireDeviceAuthentication;
use App\Education\Api\PrivateResponse;
use Yiisoft\Router\Route;

$definitions = [
    ['GET', '/classrooms', 'classrooms'], ['POST', '/classrooms', 'create_classroom'],
    ['PUT', '/classrooms/{id}', 'update_classroom'], ['POST', '/classrooms/{id}/archive', 'archive_classroom'],
    ['POST', '/classrooms/{id}/restore', 'restore_classroom'],
    ['POST', '/devices/{id}/session/cancel', 'cancel_assignment'],
    ['POST', '/devices/{id}/recovery/retry', 'retry_recovery'],
    ['GET', '/devices/{id}/commands', 'device_commands'],
    ['GET', '/devices', 'devices'], ['POST', '/devices', 'create_device'],
    ['GET', '/devices/{id}', 'device'], ['PUT', '/devices/{id}', 'update_device'],
    ['POST', '/devices/{id}/enroll', 'device_enroll'],
    ['POST', '/devices/{id}/revoke', 'revoke_device'],
    ['GET', '/devices/{id}/events', 'device_events'],
    ['GET', '/devices/{id}/sessions', 'device_sessions'],
    ['POST', '/devices/{id}/session', 'device_session'], ['POST', '/devices/{id}/session/end', 'device_session_end'],
];
$routes = [];
foreach ($definitions as [$method, $path, $operation]) {
    $route = match ($method) {
        'GET' => Route::get('/api/device-management' . $path),
        'POST' => Route::post('/api/device-management' . $path), 'PUT' => Route::put('/api/device-management' . $path),
        'DELETE' => Route::delete('/api/device-management' . $path),
    };
    $routes[] = $route->defaults(['operation' => $operation])->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(DeviceAction::class)->name('device/' . $operation);
}
$agentDefinitions = [
    ['POST', '/api/device-agent/enroll', 'agent_enroll'],
    ['POST', '/api/device-agent/heartbeat', 'agent_heartbeat'],
    ['GET', '/api/device-agent/commands', 'agent_commands'],
    ['POST', '/api/device-agent/commands/{id}/ack', 'agent_command_ack'],
    ['GET', '/api/device-agent/session', 'agent_session'],
    ['POST', '/api/device-agent/session/{id}/state', 'agent_session_state'],
    ['POST', '/api/device-agent/session/{id}/end', 'agent_session_end'],
    ['POST', '/api/device-agent/events', 'agent_event'],
];
foreach ($agentDefinitions as [$method, $path, $operation]) {
    $route = $method === 'GET' ? Route::get($path) : Route::post($path);
    $middleware = $operation === 'agent_enroll'
        ? [PrivateResponse::class] : [PrivateResponse::class, RequireDeviceAuthentication::class];
    $routes[] = $route->defaults(['operation' => $operation])->middleware(...$middleware)
        ->action(DeviceAction::class)->name('device/' . $operation);
}
return $routes;
