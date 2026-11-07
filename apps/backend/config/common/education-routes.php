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
    ['GET', '/lessons/{id}/results', 'lesson_results'],
    ['PUT', '/lessons/{id}/participants/{studentId}', 'mark_attendance'], ['PUT', '/lessons/{id}', 'update_lesson'],
    ['PUT', '/lessons/{id}/report', 'save_lesson_report'],
    ['PUT', '/lessons/{id}/results/{studentId}', 'save_lesson_result'],
    ['POST', '/lessons/{id}/complete', 'complete_lesson'], ['POST', '/lessons/{id}/cancel', 'cancel_lesson'],
    ['GET', '/lessons/{id}', 'lesson'],
    ['GET', '/groups', 'groups'], ['POST', '/groups', 'create_group'], ['PUT', '/groups/{id}', 'update_group'],
    ['POST', '/groups/{id}/archive', 'archive_group'], ['GET', '/groups/{id}/students', 'group_students'],
    ['GET', '/groups/{id}/journal', 'group_journal'],
    ['DELETE', '/groups/{id}/students/{studentId}', 'remove_group_student'],
    ['POST', '/groups/{id}/students/{studentId}/transfer', 'transfer_student'], ['GET', '/groups/{id}', 'group'],
    ['GET', '/students', 'students'], ['POST', '/students', 'enroll_student'], ['GET', '/people/candidates', 'candidates'],
    ['POST', '/students/{id}/invite', 'invite_student'], ['POST', '/students/{id}/revoke-account', 'revoke_student_account'],
    ['GET', '/students/{id}', 'student'], ['GET', '/students/{id}/attendance', 'student_attendance'],
    ['GET', '/students/{id}/results', 'student_results'], ['GET', '/students/{id}/progress', 'student_progress'],
    ['POST', '/student-activate', 'activate_invite'],
    ['GET', '/activities/assignments', 'activity_assignments'], ['POST', '/activities/{id}/assignments', 'activities_assign'],
    ['GET', '/groups/{id}/activity-results', 'group_activity_results'],
    ['GET', '/student/home', 'student_home'], ['GET', '/student/schedule', 'student_schedule'],
    ['GET', '/student/lessons', 'student_lessons'], ['GET', '/student/lessons/{id}', 'student_lesson'],
    ['GET', '/student/activities', 'student_activity_catalog'], ['GET', '/student/activities/{id}', 'student_activity_config'],
    ['GET', '/student/attempts', 'student_attempt_history'], ['POST', '/student/activities/{id}/attempts', 'student_start_attempt'],
    ['PUT', '/student/attempts/{id}/finish', 'student_finish_attempt'], ['GET', '/student/typing', 'student_typing_history'],
    ['POST', '/events', 'create_event'], ['GET', '/files', 'files'], ['POST', '/files', 'upload_file'],
    ['DELETE', '/files/{id}', 'delete_file'], ['GET', '/files/{id}', 'file_download'],
    ['GET', '/profile', 'profile'], ['PUT', '/profile', 'save_profile'],
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
        'DELETE' => Route::delete('/api/education' . $path),
    };
    $routes[] = $route->defaults(['operation' => $operation])->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(EducationAction::class)->name('education/' . $operation);
}
return $routes;
