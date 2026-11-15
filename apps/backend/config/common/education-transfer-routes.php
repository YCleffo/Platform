<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\{EducationTransferAction, PrivateResponse};
use Yiisoft\Router\Route;

$routes = [];
foreach ([
    ['POST', '/file-transfers', 'start'],
    ['POST', '/homework/{id}/file-transfers', 'homework_start'],
    ['POST', '/student/homework/{id}/file-transfers', 'student_homework_start'],
    ['GET', '/file-transfers/{id}', 'read'],
    ['POST', '/file-transfers/{id}/complete', 'complete'],
    ['POST', '/file-transfers/{id}/abort', 'abort'],
    ['GET', '/files/{id}/transfer', 'download'],
    ['GET', '/homework/files/{id}/transfer', 'homework_download'],
] as [$method, $path, $operation]) {
    $route = $method === 'GET' ? Route::get('/api/education' . $path) : Route::post('/api/education' . $path);
    $routes[] = $route->defaults(['operation' => $operation])->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(EducationTransferAction::class)->name('education_transfer/' . $operation);
}
return $routes;
