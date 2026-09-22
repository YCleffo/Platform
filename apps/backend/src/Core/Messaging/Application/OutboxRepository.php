<?php

declare(strict_types=1);

namespace App\Core\Messaging\Application;

interface OutboxRepository
{
    public function enqueue(OutboxEventCommand $command): OutboxEvent;

    /** @return list<OutboxEvent> */
    public function claimPending(string $worker, int $limit, int $leaseSeconds): array;

    /**
     * @param list<string> $eventTypes
     *
     * @return list<OutboxEvent>
     */
    public function claimPendingByTypes(
        string $worker,
        array $eventTypes,
        int $limit,
        int $leaseSeconds,
    ): array;

    public function markPublished(int $id, ?string $worker = null): bool;

    public function markFailed(
        int $id,
        string $error,
        bool $dead = false,
        ?string $availableAt = null,
        ?string $worker = null,
    ): bool;
}
