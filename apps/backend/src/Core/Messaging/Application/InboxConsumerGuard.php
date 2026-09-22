<?php

declare(strict_types=1);

namespace App\Core\Messaging\Application;

final readonly class InboxConsumerGuard
{
    public function __construct(private InboxRepository $repository) {}

    public function wasProcessed(string $eventId, string $consumer): bool
    {
        return $this->repository->find($eventId, $consumer)?->status === 'processed';
    }

    public function register(string $eventId, string $consumer): InboxEvent
    {
        return $this->repository->registerReceived($eventId, $consumer);
    }

    public function processed(string $eventId, string $consumer): bool
    {
        return $this->repository->markProcessed($eventId, $consumer);
    }

    public function failed(string $eventId, string $consumer, string $error, bool $dead = false): bool
    {
        return $this->repository->markFailed($eventId, $consumer, $error, $dead);
    }
}
