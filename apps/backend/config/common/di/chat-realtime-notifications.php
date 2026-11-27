<?php

declare(strict_types=1);

use App\Chat\Application\ChatContextPolicyInterface;
use App\Chat\Infrastructure\ChatMediaAccessAuthorizer;
use App\Chat\Infrastructure\DefaultChatContextPolicy;
use App\Education\Infrastructure\EducationChatContextPolicy;
use App\Education\Infrastructure\EducationMediaAccessAuthorizer;
use App\Media\Application\MediaAccessAuthorizer;
use App\Realtime\Application\RealtimeChannelAuthorizerInterface;
use App\Realtime\Application\RealtimeService;
use App\Realtime\Infrastructure\DefaultRealtimeChannelAuthorizer;
use App\Realtime\Infrastructure\WebSocketServer;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array<string, mixed> $params */
$redis = $params['redis'] ?? [];

return [
    RealtimeChannelAuthorizerInterface::class => ['class' => DefaultRealtimeChannelAuthorizer::class],
    WebSocketServer::class => [
        '__construct()' => [
            'redisHost' => (string) ($redis['host'] ?? '127.0.0.1'),
            'redisPort' => (int) ($redis['port'] ?? 6379),
            'redisDatabase' => (int) ($redis['database'] ?? 0),
        ],
    ],
    RealtimeService::class => [
        '__construct()' => [
            'ticketSecret' => (string) ($params['auth']['totpEncryptionKey'] ?? ''),
        ],
    ],
    ChatContextPolicyInterface::class => class_exists(EducationChatContextPolicy::class)
        ? ['class' => EducationChatContextPolicy::class]
        : ['class' => DefaultChatContextPolicy::class],
    MediaAccessAuthorizer::class => static function (
        ConnectionInterface $db,
    ): MediaAccessAuthorizer {
        $education = class_exists(EducationMediaAccessAuthorizer::class)
            ? new EducationMediaAccessAuthorizer($db)
            : null;
        return new ChatMediaAccessAuthorizer($db, $education);
    },
];
