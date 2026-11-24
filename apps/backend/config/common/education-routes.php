<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\EducationAction;
use App\Education\Api\CurriculumAction;
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
    ['GET', '/activities', 'activity_catalog'],
    ['GET', '/groups/{id}/activity-progress', 'group_activity_progress'],
    ['PUT', '/attempts/{id}/revoke', 'revoke_activity_attempt'],
    ['GET', '/student/achievements', 'student_achievements'],
    ['GET', '/student/activity-progress', 'student_activity_progress'],
    ['GET', '/student/attempts/{id}', 'student_attempt'],
    ['PUT', '/student/attempts/{id}/state', 'student_save_attempt_state'],
    ['GET', '/activities/assignments', 'activity_assignments'], ['POST', '/activities/{id}/assignments', 'activities_assign'],
    ['GET', '/groups/{id}/activity-results', 'group_activity_results'],
    ['GET', '/student/home', 'student_home'], ['GET', '/student/schedule', 'student_schedule'],
    ['GET', '/student/notifications', 'student_notifications'], ['POST', '/student/notifications/{id}/read', 'student_read_notification'],
    ['GET', '/student/lessons', 'student_lessons'], ['GET', '/student/lessons/{id}', 'student_lesson'],
    ['GET', '/student/activities', 'student_activity_catalog'], ['GET', '/student/activities/{id}', 'student_activity_config'],
    ['GET', '/student/attempts', 'student_attempt_history'], ['POST', '/student/activities/{id}/attempts', 'student_start_attempt'],
    ['PUT', '/student/attempts/{id}/finish', 'student_finish_attempt'], ['GET', '/student/typing', 'student_typing_history'],
    ['POST', '/events', 'create_event'], ['GET', '/files', 'files'], ['POST', '/files', 'upload_file'],
    ['DELETE', '/files/{id}', 'delete_file'], ['GET', '/files/{id}', 'file_download'],
    ['GET', '/profile', 'profile'], ['PUT', '/profile', 'save_profile'],
    ['GET', '/colleagues', 'colleagues'], ['GET', '/contacts', 'contacts'],
    ['GET', '/threads', 'threads'], ['POST', '/threads', 'start_thread'],
    ['GET', '/threads/{id}', 'thread'], ['GET', '/threads/{id}/messages', 'messages'],
    ['POST', '/threads/{id}/messages', 'send_message'], ['POST', '/threads/{id}/reactions', 'react_message'], ['POST', '/threads/{id}/read', 'read_thread'],
    ['GET', '/student/contacts', 'student_contacts'],
    ['GET', '/student/threads', 'student_threads'], ['POST', '/student/threads', 'student_start_thread'],
    ['GET', '/student/threads/{id}', 'student_thread'], ['GET', '/student/threads/{id}/messages', 'student_messages'],
    ['POST', '/student/threads/{id}/messages', 'student_send_message'], ['POST', '/student/threads/{id}/reactions', 'student_react_message'], ['POST', '/student/threads/{id}/read', 'student_read_thread'],
    ['GET', '/notifications', 'notifications'], ['POST', '/notifications/{id}/read', 'read_notification'],
];
$routes = (static fn(): array => require __DIR__ . '/parent-routes.php')();
$curriculumDefinitions = [
    ['GET', '/programs', 'programs'], ['POST', '/programs', 'create_program'],
    ['GET', '/programs/{id}', 'program'], ['PUT', '/programs/{id}', 'update_program'],
    ['POST', '/programs/{id}/modules', 'create_curriculum_module'],
    ['GET', '/curriculum-modules/{id}', 'curriculum_module'], ['PUT', '/curriculum-modules/{id}', 'update_curriculum_module'],
    ['POST', '/curriculum-modules/{id}/lessons', 'create_curriculum_lesson'],
    ['GET', '/curriculum-lessons/{id}', 'curriculum_lesson'], ['PUT', '/curriculum-lessons/{id}', 'update_curriculum_lesson'],
    ['GET', '/groups/{id}/programs', 'group_programs'], ['POST', '/groups/{id}/programs', 'assign_program'],
    ['GET', '/groups/{id}/progress', 'group_progress'], ['GET', '/students/{id}/curriculum-progress', 'student_curriculum_progress'],
    ['GET', '/student/curriculum-progress', 'own_curriculum_progress'],
    ['GET', '/groups/{id}/teachers', 'group_teachers'], ['PUT', '/groups/{id}/teachers', 'set_group_teachers'],
    ['POST', '/students/new', 'create_student'],
];
foreach ($curriculumDefinitions as [$method, $path, $operation]) {
    $route = match ($method) {
        'GET' => Route::get('/api/education' . $path), 'POST' => Route::post('/api/education' . $path),
        'PUT' => Route::put('/api/education' . $path),
    };
    $routes[] = $route->defaults(['operation' => $operation])->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(CurriculumAction::class)->name('education/curriculum/' . $operation);
}
foreach ($definitions as [$method, $path, $operation]) {
    $route = match ($method) {
        'GET' => Route::get('/api/education' . $path),
        'POST' => Route::post('/api/education' . $path), 'PUT' => Route::put('/api/education' . $path),
        'DELETE' => Route::delete('/api/education' . $path),
    };
    $routes[] = $route->defaults(['operation' => $operation])->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(EducationAction::class)->name('education/' . $operation);
}
return [...$routes, ...(require __DIR__ . '/education-transfer-routes.php')];
