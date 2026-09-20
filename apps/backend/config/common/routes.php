<?php

declare(strict_types=1);

use App\Api;
use Yiisoft\Router\Route;

return [
    Route::get('/')
        ->action(Api\IndexAction::class)
        ->name('app/index'),

    Route::get('/health')
        ->action(Api\HealthAction::class)
        ->name('app/health'),
];
