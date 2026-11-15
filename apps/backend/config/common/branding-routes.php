<?php

declare(strict_types=1);

use App\Branding\BrandingAction;
use App\Branding\BrandingReadAction;
use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Core\Rbac\Infrastructure\RequirePermission;
use Yiisoft\Router\Route;

$brandingPermission = ['class' => RequirePermission::class, '__construct()' => ['permission' => 'core.settings.manage']];

return [
    Route::get('/api/branding')->action(BrandingReadAction::class)->name('branding/read'),
    Route::get('/api/branding/favicon/{id}')->defaults(['operation' => 'icon'])
        ->action(BrandingAction::class)->name('branding/favicon'),
    Route::put('/api/branding')->defaults(['operation' => 'name'])
        ->middleware(RequireAuthentication::class, $brandingPermission)
        ->action(BrandingAction::class)->name('branding/name'),
    Route::post('/api/branding/favicon')->defaults(['operation' => 'start'])
        ->middleware(RequireAuthentication::class, $brandingPermission)
        ->action(BrandingAction::class)->name('branding/upload'),
    Route::post('/api/branding/favicon/{id}/refresh')->defaults(['operation' => 'refresh'])
        ->middleware(RequireAuthentication::class, $brandingPermission)
        ->action(BrandingAction::class)->name('branding/refresh'),
    Route::post('/api/branding/favicon/{id}/complete')->defaults(['operation' => 'complete'])
        ->middleware(RequireAuthentication::class, $brandingPermission)
        ->action(BrandingAction::class)->name('branding/complete'),
    Route::post('/api/branding/favicon/{id}/abort')->defaults(['operation' => 'abort'])
        ->middleware(RequireAuthentication::class, $brandingPermission)
        ->action(BrandingAction::class)->name('branding/abort'),
    Route::delete('/api/branding/favicon')->defaults(['operation' => 'remove'])
        ->middleware(RequireAuthentication::class, $brandingPermission)
        ->action(BrandingAction::class)->name('branding/remove'),
];
