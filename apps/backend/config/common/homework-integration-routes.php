<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\{HomeworkIntegrationAction, PrivateResponse};
use Yiisoft\Router\Route;

$routes = [];
foreach ([
    ['POST','/homework/{id}/files','material'],
    ['POST','/student/homework/{id}/files','submission'],
    ['DELETE','/student/homework/{id}/files/{fileId}','unlink'],
    ['GET','/homework/files/{id}','download'],
    ['POST','/student/homework/{id}/activity/start','start_activity'],
    ['POST','/student/homework/{id}/activity/attach','attach_activity'],
] as [$method, $path, $operation]) {
    $route = match ($method) {
        'POST' => Route::post('/api/education' . $path),
        'DELETE' => Route::delete('/api/education' . $path), default => Route::get('/api/education' . $path),
    };
    $routes[] = $route->defaults(['operation' => $operation])->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(HomeworkIntegrationAction::class)->name('education/homework-integration/' . $operation);
}
return $routes;
