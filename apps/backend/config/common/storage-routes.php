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
    ['GET', '/files/versions', 'file_versions'], ['POST', '/files/restore', 'restore_file'],
    ['POST', '/files/rename', 'rename_file'], ['GET', '/conflicts', 'conflicts'],
    ['POST', '/conflicts/{id}/resolve', 'resolve_conflict'],
    ['DELETE', '/files', 'delete_file'],
    ['POST', '/transfers', 'transfer_start'], ['GET', '/transfers/{id}', 'transfer_read'],
    ['POST', '/transfers/{id}/refresh', 'transfer_refresh'], ['POST', '/transfers/{id}/parts', 'transfer_parts'],
    ['POST', '/transfers/{id}/complete', 'transfer_complete'], ['POST', '/transfers/{id}/abort', 'transfer_abort'],
];
$routes = [];
foreach ($definitions as [$method, $path, $operation]) {
    $route = match ($method) {
        'GET' => Route::get('/api/storage' . $path), 'POST' => Route::post('/api/storage' . $path),
        'PUT' => Route::put('/api/storage' . $path), 'DELETE' => Route::delete('/api/storage' . $path),
    };
    $routes[] = $route->defaults(['operation' => $operation])->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(StorageAction::class)->name('storage/' . $operation);
}
$agentDefinitions = [
    ['POST', '/api/device-agent/storage/manifest', 'agent_storage_manifest'],
    ['POST', '/api/device-agent/storage/manifest/rebase', 'agent_storage_manifest_rebase'],
    ['GET', '/api/device-agent/storage/manifest', 'agent_storage_manifest_read'],
    ['POST', '/api/device-agent/storage/ticket', 'agent_storage_ticket'],
    ['POST', '/api/device-agent/storage/sync', 'agent_storage_sync'],
    ['GET', '/api/device-agent/storage/space', 'agent_storage_space'],
    ['PUT', '/api/device-agent/storage/file', 'agent_storage_upload'],
    ['GET', '/api/device-agent/storage/file', 'agent_storage_download'],
    ['POST', '/api/device-agent/storage/transfers', 'agent_storage_transfer_start'],
    ['GET', '/api/device-agent/storage/transfers/{id}', 'agent_storage_transfer_read'],
    ['POST', '/api/device-agent/storage/transfers/{id}/refresh', 'agent_storage_transfer_refresh'],
    ['POST', '/api/device-agent/storage/transfers/{id}/parts', 'agent_storage_transfer_parts'],
    ['POST', '/api/device-agent/storage/transfers/{id}/complete', 'agent_storage_transfer_complete'],
    ['POST', '/api/device-agent/storage/transfers/{id}/abort', 'agent_storage_transfer_abort'],
];
foreach ($agentDefinitions as [$method, $path, $operation]) {
    $route = $method === 'GET' ? Route::get($path) : ($method === 'PUT' ? Route::put($path) : Route::post($path));
    $routes[] = $route->defaults(['operation' => $operation])
        ->middleware(PrivateResponse::class, RequireDeviceAuthentication::class)
        ->action(StorageAction::class)->name('storage/' . $operation);
}
return $routes;
