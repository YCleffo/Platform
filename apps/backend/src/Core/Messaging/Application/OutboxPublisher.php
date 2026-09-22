<?php

declare(strict_types=1);

namespace App\Core\Messaging\Application;

final readonly class OutboxPublisher
{
    public function __construct(private OutboxRepository $repository) {}

    /** @return list<OutboxEvent> */
    public function claim(string $worker, int $limit = 50, int $leaseSeconds = 60): array
    {
        return $this->repository->claimPending($worker, $limit, $leaseSeconds);
    }

    /**
     * @param list<string> $eventTypes
     *
     * @return list<OutboxEvent>
     */
    public function claimTypes(
        string $worker,
        array $eventTypes,
        int $limit = 50,
        int $leaseSeconds = 60,
    ): array {
        return $this->repository->claimPendingByTypes(
            $worker,
            $eventTypes,
            $limit,
            $leaseSeconds,
        );
    }

    public function published(int $id): bool
    {
        return $this->repository->markPublished($id);
    }

    public function failed(
        int $id,
        string $error,
        bool $dead = false,
        ?string $availableAt = null,
    ): bool {
        return $this->repository->markFailed($id, $error, $dead, $availableAt);
    }
}
