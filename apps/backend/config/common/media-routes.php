<?php

declare(strict_types=1);

use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\PrivateResponse;
use App\Media\Api\MediaAction;
use Yiisoft\Router\Route;

return [
    Route::post('/api/media/reserve')
        ->defaults(['operation' => 'reserve'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/reserve'),
    Route::post('/api/media/upload/{id}')
        ->defaults(['operation' => 'upload'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/upload'),
    Route::post('/api/media/{id}/confirm')
        ->defaults(['operation' => 'confirm'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/confirm'),
    Route::post('/api/media/{id}/cancel')
        ->defaults(['operation' => 'cancel'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/cancel'),
    Route::post('/api/media/{id}/rename')
        ->defaults(['operation' => 'rename'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/rename'),
    Route::post('/api/media/{id}/move')
        ->defaults(['operation' => 'move'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/move'),
    Route::post('/api/media/{id}/copy')
        ->defaults(['operation' => 'copy'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/copy'),
    Route::get('/api/media/stream/{id}')
        ->defaults(['operation' => 'stream'])
        ->middleware(PrivateResponse::class)
        ->action(MediaAction::class)
        ->name('media/stream'),
    Route::get('/api/media/{id}/playback')
        ->defaults(['operation' => 'playback'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/playback'),
    Route::get('/api/media/{id}')
        ->defaults(['operation' => 'get'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/get'),
    Route::get('/api/media')
        ->defaults(['operation' => 'list'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(MediaAction::class)
        ->name('media/list'),
];
