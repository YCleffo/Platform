<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\PrivateResponse;
use App\Workspace\Api\WorkspaceAction;
use Yiisoft\Router\Route;

return [
    Route::get('/api/workspace/dashboard')
        ->defaults(['operation' => 'dashboard'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/dashboard'),
    Route::get('/api/workspace/search')
        ->defaults(['operation' => 'search'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/search'),
    Route::get('/api/workspace/preferences')
        ->defaults(['operation' => 'preferences_get'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/preferences_get'),
    Route::put('/api/workspace/preferences')
        ->defaults(['operation' => 'preferences_set'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/preferences_set'),
    Route::post('/api/workspace/actions/execute')
        ->defaults(['operation' => 'execute_action'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/actions_execute'),
    Route::get('/api/workspace/operations')
        ->defaults(['operation' => 'list_operations'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/operations_list'),
    Route::post('/api/workspace/operations')
        ->defaults(['operation' => 'track_operation'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/operations_track'),
    Route::post('/api/workspace/operations/{id}/undo')
        ->defaults(['operation' => 'undo_operation'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/operations_undo'),
    Route::post('/api/workspace/operations/{id}/cancel')
        ->defaults(['operation' => 'cancel_operation'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/operations_cancel'),
    Route::post('/api/workspace/operations/{id}/retry')
        ->defaults(['operation' => 'retry_operation'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/operations_retry'),
    Route::get('/api/workspace/trash')
        ->defaults(['operation' => 'list_trash'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/trash_list'),
    Route::post('/api/workspace/trash')
        ->defaults(['operation' => 'move_to_trash'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/trash_move'),
    Route::post('/api/workspace/trash/empty')
        ->defaults(['operation' => 'empty_trash'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/trash_empty'),
    Route::post('/api/workspace/trash/{id}/restore')
        ->defaults(['operation' => 'restore_trash'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/trash_restore'),
    Route::delete('/api/workspace/trash/{id}')
        ->defaults(['operation' => 'delete_trash'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(WorkspaceAction::class)
        ->name('workspace/trash_delete'),
];
