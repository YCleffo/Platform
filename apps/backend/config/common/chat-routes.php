<?php

declare(strict_types=1);

use App\Chat\Api\ChatAction;
use App\Core\Auth\Infrastructure\RequireAuthentication;
use App\Education\Api\PrivateResponse;
use Yiisoft\Router\Route;

return [
    Route::get('/api/chat/organizations')
        ->defaults(['operation' => 'my_organizations'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/organizations'),
    Route::get('/api/chat/rooms')
        ->defaults(['operation' => 'rooms'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/rooms'),
    Route::post('/api/chat/rooms')
        ->defaults(['operation' => 'create_room'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/create_room'),
    Route::get('/api/chat/contacts')
        ->defaults(['operation' => 'contacts'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/contacts'),
    Route::get('/api/chat/search')
        ->defaults(['operation' => 'search_messages'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/search'),
    Route::get('/api/chat/rooms/{id}')
        ->defaults(['operation' => 'room'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/room'),
    Route::put('/api/chat/rooms/{id}/preferences')
        ->defaults(['operation' => 'room_preferences'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/room_preferences'),
    Route::get('/api/chat/rooms/{id}/messages')
        ->defaults(['operation' => 'messages'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/messages'),
    Route::post('/api/chat/rooms/{id}/messages')
        ->defaults(['operation' => 'send_message'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/send_message'),
    Route::put('/api/chat/rooms/{id}/messages/{messageId}')
        ->defaults(['operation' => 'edit_message'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/edit_message'),
    Route::delete('/api/chat/rooms/{id}/messages/{messageId}')
        ->defaults(['operation' => 'delete_message'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/delete_message'),
    Route::post('/api/chat/rooms/{id}/messages/{messageId}/delete')
        ->defaults(['operation' => 'delete_message'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/delete_message_post'),
    Route::post('/api/chat/rooms/{id}/reactions')
        ->defaults(['operation' => 'react_message'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/react_message'),
    Route::post('/api/chat/rooms/{id}/read')
        ->defaults(['operation' => 'read_room'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/read_room'),
    Route::post('/api/chat/rooms/{id}/pin')
        ->defaults(['operation' => 'pin_message'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/pin_message'),
    Route::put('/api/chat/rooms/{id}/draft')
        ->defaults(['operation' => 'save_draft'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/save_draft'),
    Route::post('/api/chat/rooms/{id}/members')
        ->defaults(['operation' => 'add_member'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/add_member'),
    Route::delete('/api/chat/rooms/{id}/members/{personId}')
        ->defaults(['operation' => 'remove_member'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/remove_member'),
    Route::post('/api/chat/rooms/{id}/leave')
        ->defaults(['operation' => 'leave_room'])
        ->middleware(PrivateResponse::class, RequireAuthentication::class)
        ->action(ChatAction::class)
        ->name('chat/leave_room'),
];
