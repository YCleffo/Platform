<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\DeviceManagement\Infrastructure\RequireDeviceAuthentication;
use App\Education\Api\PrivateResponse;
use App\Storage\Api\StorageAction;
use Yiisoft\Router\Route;

$definitions = [
    ['GET', '/space', 'space'], ['GET', '/files', 'files'], ['POST', '/tickets', 'ticket'],
    ['PUT', '/files', 'upload'], ['GET', '/file', 'download'],
];
$routes = [];
foreach ($definitions as [$method, $path, $operation]) {
    $route = match ($method) {
        'GET' => Route::get('/api/storage' . $path), 'POST' => Route::post('/api/storage' . $path),
        'PUT' => Route::put('/api/storage' . $path),
    };
    $routes[] = $route->defaults(['operation' => $operation])->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(StorageAction::class)->name('storage/' . $operation);
}
$agentDefinitions = [
    ['POST', '/api/device-agent/storage/manifest', 'agent_storage_manifest'],
    ['GET', '/api/device-agent/storage/manifest', 'agent_storage_manifest_read'],
    ['POST', '/api/device-agent/storage/ticket', 'agent_storage_ticket'],
    ['POST', '/api/device-agent/storage/sync', 'agent_storage_sync'],
    ['GET', '/api/device-agent/storage/space', 'agent_storage_space'],
    ['PUT', '/api/device-agent/storage/file', 'agent_storage_upload'],
    ['GET', '/api/device-agent/storage/file', 'agent_storage_download'],
];
foreach ($agentDefinitions as [$method, $path, $operation]) {
    $route = $method === 'GET' ? Route::get($path) : ($method === 'PUT' ? Route::put($path) : Route::post($path));
    $routes[] = $route->defaults(['operation' => $operation])
        ->middleware(PrivateResponse::class, RequireDeviceAuthentication::class)
        ->action(StorageAction::class)->name('storage/' . $operation);
}
return $routes;
