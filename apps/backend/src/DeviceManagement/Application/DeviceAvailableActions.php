<?php

declare(strict_types=1);

namespace App\DeviceManagement\Application;

final class DeviceAvailableActions
{
    public static function forDevice(array $device, ?array $session, bool $online, bool $archived = false): array
    {
        if (($device['enrollmentState'] ?? '') === 'revoked') return [];
        if ($session === null) {
            $actions = ['edit', 'enroll', 'revoke'];
            if ($online && !$archived && ($device['enrollmentState'] ?? '') === 'confirmed'
                && ($device['status'] ?? '') === 'AVAILABLE') $actions[] = 'assign';
            return $actions;
        }
        $status = $session['status'];
        if ($status === 'AUTHORIZED') return ['cancel_assignment'];
        if (in_array($status, ['SYNC_PENDING', 'RECOVERY_REQUIRED', 'FAILED'], true)) return $online ? ['retry_recovery'] : [];
        return $online && $status === 'ACTIVE' && ($session['desiredStatus'] ?? null) !== 'ENDING' ? ['end_session'] : [];
    }
}
