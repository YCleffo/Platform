<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\EducationAction;
use App\Education\Api\PrivateResponse;
use Yiisoft\Router\Route;

$definitions = [
    ['GET', '/context', 'context'], ['GET', '/today/header', 'header'],
    ['GET', '/schedule', 'schedule'], ['GET', '/timeline', 'timeline'], ['GET', '/attention', 'attention'],
    ['GET', '/lessons/next', 'next'], ['GET', '/lessons/current', 'current'], ['POST', '/lessons', 'create_lesson'],
    ['GET', '/lessons/{id}/participants', 'participants'], ['GET', '/lessons/{id}/materials', 'materials'],
    ['PUT', '/lessons/{id}/participants/{studentId}', 'mark_attendance'], ['GET', '/lessons/{id}', 'lesson'],
    ['GET', '/groups', 'groups'], ['POST', '/groups', 'create_group'], ['GET', '/groups/{id}', 'group'],
    ['GET', '/students', 'students'], ['POST', '/students', 'enroll_student'], ['GET', '/people/candidates', 'candidates'],
    ['POST', '/events', 'create_event'], ['GET', '/files', 'files'], ['POST', '/files', 'upload_file'],
    ['GET', '/files/{id}', 'file_download'], ['GET', '/profile', 'profile'], ['PUT', '/profile', 'save_profile'],
    ['GET', '/colleagues', 'colleagues'], ['GET', '/threads', 'threads'], ['POST', '/threads', 'start_thread'],
    ['GET', '/threads/{id}', 'thread'], ['GET', '/threads/{id}/messages', 'messages'],
    ['POST', '/threads/{id}/messages', 'send_message'], ['POST', '/threads/{id}/read', 'read_thread'],
    ['GET', '/notifications', 'notifications'], ['POST', '/notifications/{id}/read', 'read_notification'],
];
$routes = [];
foreach ($definitions as [$method, $path, $operation]) {
    $route = match ($method) {
        'GET' => Route::get('/api/education' . $path),
        'POST' => Route::post('/api/education' . $path), 'PUT' => Route::put('/api/education' . $path),
    };
    $routes[] = $route->defaults(['operation' => $operation])->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(EducationAction::class)->name('education/' . $operation);
}
return $routes;
