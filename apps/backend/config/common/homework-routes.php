<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\HomeworkAction;
use App\Education\Api\PrivateResponse;
use Yiisoft\Router\Route;

$definitions = [
    ['GET', '/homework', 'teacher_list'], ['POST', '/homework', 'create'],
    ['GET', '/homework/progress', 'teacher_summary'],
    ['GET', '/homework/submissions/{id}', 'teacher_submission_detail'],
    ['POST', '/homework/submissions/{id}/review', 'review'],
    ['GET', '/homework/{id}/history', 'teacher_history'],
    ['GET', '/homework/{id}/submissions', 'teacher_submissions'],
    ['POST', '/homework/{id}/publish', 'publish'], ['POST', '/homework/{id}/close', 'close'],
    ['POST', '/homework/{id}/archive', 'archive'], ['POST', '/homework/{id}/recipients', 'add_recipients'],
    ['GET', '/homework/{id}', 'teacher_detail'], ['PUT', '/homework/{id}', 'update'],
    ['GET', '/student/homework', 'student_list'],
    ['GET', '/student/homework/progress', 'student_summary'],
    ['GET', '/student/homework/{id}', 'student_detail'],
    ['PUT', '/student/homework/{id}/draft', 'save_draft'], ['POST', '/student/homework/{id}/submit', 'submit'],
];
$routes = [];
foreach ($definitions as [$method, $path, $operation]) {
    $routes[] = Route::methods([$method], '/api/education' . $path)->defaults(['operation' => $operation])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)->action(HomeworkAction::class)->name('education/homework/' . $operation);
}
return $routes;
