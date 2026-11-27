<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\PrivateResponse;
use App\Notifications\Api\NotificationsAction;
use Yiisoft\Router\Route;

return [
    Route::get('/api/notifications')
        ->defaults(['operation' => 'list'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/list'),
    Route::post('/api/notifications/read-all')
        ->defaults(['operation' => 'mark_all_read'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/read_all'),
    Route::post('/api/notifications/bulk-read')
        ->defaults(['operation' => 'bulk_read'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/bulk_read'),
    Route::post('/api/notifications/bulk-archive')
        ->defaults(['operation' => 'bulk_archive'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/bulk_archive'),
    Route::get('/api/notifications/push-status')
        ->defaults(['operation' => 'push_status'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/push_status'),
    Route::post('/api/notifications/{id}/read')
        ->defaults(['operation' => 'mark_read'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/mark_read'),
    Route::get('/api/notifications/preferences')
        ->defaults(['operation' => 'preferences_get'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/preferences_get'),
    Route::put('/api/notifications/preferences')
        ->defaults(['operation' => 'preferences_update'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/preferences_update'),
    Route::post('/api/notifications/push-subscriptions')
        ->defaults(['operation' => 'push_subscribe'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/push_subscribe'),
    Route::delete('/api/notifications/push-subscriptions/{id}')
        ->defaults(['operation' => 'push_revoke'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(NotificationsAction::class)
        ->name('notifications/push_revoke'),
];
