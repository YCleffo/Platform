<?php

declare(strict_types=1);

namespace App\Realtime\Application;

final readonly class RealtimeEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $targetPersonIds
     */
    public function __construct(
        public int $seq,
        public string $eventId,
        public string $organizationId,
        public string $channel,
        public string $eventType,
        public string $aggregateType,
        public string $aggregateId,
        public ?string $actorAccountId,
        public ?string $actorPersonId,
        public array $payload,
        public ?array $targetPersonIds,
        public string $createdAt,
        public bool $ephemeral = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'seq' => $this->seq,
            'eventId' => $this->eventId,
            'organizationId' => $this->organizationId,
            'channel' => $this->channel,
            'eventType' => $this->eventType,
            'aggregateType' => $this->aggregateType,
            'aggregateId' => $this->aggregateId,
            'actorAccountId' => $this->actorAccountId,
            'actorPersonId' => $this->actorPersonId,
            'payload' => $this->payload,
            'targetPersonIds' => $this->targetPersonIds,
            'createdAt' => $this->createdAt,
            'ephemeral' => $this->ephemeral,
        ];
    }
}
