<?php

declare(strict_types=1);

namespace App\Core\Messaging\Application;

interface InboxRepository
{
    public function find(string $eventId, string $consumer): ?InboxEvent;

    public function registerReceived(string $eventId, string $consumer): InboxEvent;

    public function markProcessed(string $eventId, string $consumer): bool;

    public function markFailed(string $eventId, string $consumer, string $error, bool $dead = false): bool;
}
