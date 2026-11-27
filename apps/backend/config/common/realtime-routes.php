<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\PrivateResponse;
use App\Realtime\Api\RealtimeAction;
use Yiisoft\Router\Route;

return [
    Route::post('/api/realtime/ticket')
        ->defaults(['operation' => 'ticket'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(RealtimeAction::class)
        ->name('realtime/ticket'),
    Route::get('/api/realtime/events')
        ->defaults(['operation' => 'events'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(RealtimeAction::class)
        ->name('realtime/events'),
    Route::post('/api/realtime/typing')
        ->defaults(['operation' => 'typing'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(RealtimeAction::class)
        ->name('realtime/typing'),
    Route::post('/api/realtime/presence')
        ->defaults(['operation' => 'presence_update'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(RealtimeAction::class)
        ->name('realtime/presence_update'),
    Route::get('/api/realtime/presence')
        ->defaults(['operation' => 'presence_query'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(RealtimeAction::class)
        ->name('realtime/presence_query'),
];
