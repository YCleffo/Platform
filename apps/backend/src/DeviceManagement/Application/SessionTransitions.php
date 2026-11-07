<?php

declare(strict_types=1);

namespace App\DeviceManagement\Application;

use DateTimeImmutable;

final readonly class SessionTransitions
{
    /** @var array<string, list<string>> */
    public const ALLOWED = [
        'AUTHORIZED' => ['PREPARING', 'FAILED', 'RECOVERY_REQUIRED'],
        'PREPARING' => ['WINDOWS_PROFILE_READY', 'FAILED', 'RECOVERY_REQUIRED'],
        'WINDOWS_PROFILE_READY' => ['SYNCING_IN', 'FAILED', 'RECOVERY_REQUIRED'],
        'SYNCING_IN' => ['ACTIVE', 'SYNC_PENDING', 'FAILED', 'RECOVERY_REQUIRED'],
        'ACTIVE' => ['ENDING', 'SYNC_PENDING', 'FAILED', 'RECOVERY_REQUIRED'],
        'ENDING' => ['SYNCING_OUT', 'SYNC_PENDING', 'FAILED', 'RECOVERY_REQUIRED'],
        'SYNCING_OUT' => ['STORAGE_CONFIRMED', 'SYNC_PENDING', 'FAILED', 'RECOVERY_REQUIRED'],
        'STORAGE_CONFIRMED' => ['CLEANUP', 'SYNC_PENDING', 'FAILED', 'RECOVERY_REQUIRED'],
        'CLEANUP' => ['CLOSED', 'SYNC_PENDING', 'FAILED', 'RECOVERY_REQUIRED'],
        'CLOSED' => [],
        'SYNC_PENDING' => ['SYNCING_OUT', 'ACTIVE', 'RECOVERY_REQUIRED', 'FAILED'],
        'RECOVERY_REQUIRED' => ['SYNCING_OUT', 'CLEANUP', 'CLOSED', 'FAILED'],
        'FAILED' => [],
    ];

    public static function allowed(string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public static function terminal(string $status): bool
    {
        return in_array($status, ['CLOSED', 'FAILED'], true);
    }

    public static function isTransitionBlocking(string $status): bool
    {
        return in_array($status, ['SYNC_PENDING', 'RECOVERY_REQUIRED'], true);
    }

    public static function validate(string $status): void
    {
        if (!isset(self::ALLOWED[$status])) {
            throw new DeviceError(422, 'invalid_session_status');
        }
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM);
    }
}
