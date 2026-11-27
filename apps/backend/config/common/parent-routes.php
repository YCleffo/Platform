<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\ParentAction;
use App\Education\Api\PrivateResponse;
use Yiisoft\Router\Route;

$definitions = [
    ['GET','/parent/context','context'],['POST','/parent/activate','activate'],['POST','/parent/register','register'],
    ['GET','/parent/children/{studentId}','child'],['GET','/parent/children/{studentId}/home','home'],
    ['GET','/parent/children/{studentId}/schedule','schedule'],['GET','/parent/children/{studentId}/diary','diary'],
    ['GET','/parent/children/{studentId}/homework','homework'],['GET','/parent/children/{studentId}/homework/files/{id}','homework_material_download'],['GET','/parent/children/{studentId}/progress','progress'],
    ['GET','/parent/children/{studentId}/notifications','notifications'],
    ['POST','/parent/children/{studentId}/notifications/{id}/read','read_notification'],
    ['GET','/parent/children/{studentId}/contacts','contacts'],
    ['GET','/parent/children/{studentId}/threads','threads'],
    ['POST','/parent/children/{studentId}/threads','parent_start_thread'],
    ['GET','/parent/children/{studentId}/threads/{id}','parent_thread'],
    ['GET','/parent/children/{studentId}/threads/{id}/messages','parent_messages'],
    ['POST','/parent/children/{studentId}/threads/{id}/messages','parent_send_message'],
    ['POST','/parent/children/{studentId}/threads/{id}/reactions','parent_react_message'],
    ['POST','/parent/children/{studentId}/threads/{id}/read','parent_read_thread'],
    ['GET','/family/candidates','staff_candidates'],
    ['GET','/students/{studentId}/guardians','staff_list'],['POST','/students/{studentId}/guardians','staff_add'],
    ['GET','/students/{studentId}/guardians/history','staff_history'],
    ['PUT','/students/{studentId}/guardians/{personId}','staff_update'],
    ['POST','/students/{studentId}/guardians/{personId}/confirm','staff_confirm'],
    ['POST','/students/{studentId}/guardians/{personId}/revoke','staff_revoke'],
    ['POST','/students/{studentId}/guardians/{personId}/invite','staff_invite'],
    ['POST','/students/{studentId}/guardians/{personId}/invite/revoke','staff_revoke_invite'],
];
$routes = [];
foreach ($definitions as [$method,$path,$operation]) {
    $route = match ($method) {
        'GET' => Route::get('/api/education' . $path),'POST' => Route::post('/api/education' . $path),
        'PUT' => Route::put('/api/education' . $path),
    };
    $middleware = $operation === 'register' ? [PrivateResponse::class] : [PrivateResponse::class,RequireAuthentication::class];
    $routes[] = $route->defaults(['operation' => $operation])->middleware(...$middleware)->action(ParentAction::class)->name('education/parent/' . $operation);
}
return $routes;
