<?php

declare(strict_types=1);

namespace App\Core\Presence\Application;

use Predis\ClientInterface;
use Throwable;

final readonly class PresenceService
{
    public function __construct(private ClientInterface $redis, private int $ttlSeconds = 90)
    {
        if ($ttlSeconds < 30 || $ttlSeconds > 300) {
            throw new \InvalidArgumentException('Presence TTL is outside the supported range.');
        }
    }

    public function heartbeat(string $accountId, ?string $clientInstanceId): bool
    {
        try {
            $value = json_encode([
                'last_seen' => gmdate(DATE_ATOM),
                'client_instance_id' => $clientInstanceId,
            ], JSON_THROW_ON_ERROR);
            $this->redis->setex(self::key($accountId), $this->ttlSeconds, $value);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return 'online'|'offline'|'unknown' */
    public function state(string $accountId): string
    {
        try {
            return $this->redis->get(self::key($accountId)) === null ? 'offline' : 'online';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    public function clearIfCurrentClient(string $accountId, ?string $clientInstanceId): void
    {
        if ($clientInstanceId === null) {
            return;
        }
        try {
            $value = $this->redis->get(self::key($accountId));
            if (!is_string($value)) {
                return;
            }
            $state = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($state) && ($state['client_instance_id'] ?? null) === $clientInstanceId) {
                $this->redis->del([self::key($accountId)]);
            }
        } catch (Throwable) {
            // Presence is ephemeral; logout/session revocation remains authoritative.
        }
    }

    private static function key(string $accountId): string
    {
        return 'presence:' . $accountId;
    }
}
