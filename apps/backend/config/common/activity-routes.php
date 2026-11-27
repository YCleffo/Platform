<?php

declare(strict_types=1);

use App\Activity\Api\ActivityAction;
use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\PrivateResponse;
use Yiisoft\Router\Route;

return [
    Route::get('/api/activity/plugins')
        ->defaults(['operation' => 'plugins'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/plugins'),
    Route::get('/api/activity/items')
        ->defaults(['operation' => 'activities'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/items'),
    Route::post('/api/activity/items')
        ->defaults(['operation' => 'create_activity'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/create_item'),
    Route::get('/api/activity/items/{id}')
        ->defaults(['operation' => 'activity'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/get_item'),
    Route::put('/api/activity/items/{id}')
        ->defaults(['operation' => 'update_activity'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/update_item'),
    Route::get('/api/activity/items/{id}/versions')
        ->defaults(['operation' => 'activity_versions'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/item_versions'),
    Route::post('/api/activity/items/{id}/duplicate')
        ->defaults(['operation' => 'duplicate_activity'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/duplicate_item'),
    Route::post('/api/activity/items/{id}/assignments')
        ->defaults(['operation' => 'assign_activity'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/assign_item'),
    Route::get('/api/activity/assignments')
        ->defaults(['operation' => 'list_assignments'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/list_assignments'),
    Route::post('/api/activity/items/{id}/attempts')
        ->defaults(['operation' => 'start_attempt'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/start_attempt'),
    Route::get('/api/activity/attempts/{id}')
        ->defaults(['operation' => 'get_attempt'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/get_attempt'),
    Route::put('/api/activity/attempts/{id}/draft')
        ->defaults(['operation' => 'save_draft'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/save_draft'),
    Route::post('/api/activity/attempts/{id}/submit')
        ->defaults(['operation' => 'submit_attempt'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/submit_attempt'),
    Route::post('/api/activity/attempts/{id}/review')
        ->defaults(['operation' => 'review_attempt'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/review_attempt'),
    Route::post('/api/activity/attempts/{id}/regrade')
        ->defaults(['operation' => 'regrade_attempt'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/regrade_attempt'),
    Route::post('/api/activity/attempts/{id}/publish')
        ->defaults(['operation' => 'publish_attempt_results'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/publish_attempt_results'),
    Route::get('/api/activity/items/{id}/attempts')
        ->defaults(['operation' => 'list_activity_attempts'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/list_activity_attempts'),
    Route::get('/api/activity/question-bank')
        ->defaults(['operation' => 'question_bank_list'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/question_bank_list'),
    Route::post('/api/activity/question-bank')
        ->defaults(['operation' => 'question_bank_create'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/question_bank_create'),
    Route::post('/api/activity/question-bank/import')
        ->defaults(['operation' => 'question_bank_import'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/question_bank_import'),
    Route::post('/api/activity/question-bank/{id}/archive')
        ->defaults(['operation' => 'question_bank_archive'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/question_bank_archive'),
    Route::get('/api/activity/analytics')
        ->defaults(['operation' => 'analytics'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/analytics'),
    Route::post('/api/activity/typing/bootstrap')
        ->defaults(['operation' => 'bootstrap_typing'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/bootstrap_typing'),
    Route::post('/api/activity/plugins/{id}/bootstrap')
        ->defaults(['operation' => 'bootstrap_plugin'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ActivityAction::class)
        ->name('activity/bootstrap_plugin'),
];
