<?php

declare(strict_types=1);

namespace App\DeviceManagement\Application;

/** Evidence is bound to one state report; a prepared account is never a logon. */
final class SessionEvidence
{
    public static function validate(array $body): array
    {
        $evidence = $body['evidence'] ?? [];
        if (!is_array($evidence) || array_diff(array_keys($evidence),
            ['profilePrepared', 'windowsLogonConfirmed', 'testAdapter', 'sessionEnded', 'storageConfirmed', 'cleanupDone']) !== []) {
            throw new DeviceError(422, 'invalid_evidence');
        }
        foreach ($evidence as $value) {
            if (!is_bool($value)) throw new DeviceError(422, 'invalid_evidence');
        }
        if (isset($body['progress'])) {
            $progress = $body['progress'];
            if (!is_array($progress) || !is_string($progress['stage'] ?? null)
                || strlen($progress['stage']) > 40 || !is_int($progress['completed'] ?? null)
                || !is_int($progress['total'] ?? null) || $progress['completed'] < 0
                || $progress['total'] < $progress['completed']) throw new DeviceError(422, 'invalid_progress');
            $evidence['progress'] = ['stage' => $progress['stage'], 'completed' => $progress['completed'], 'total' => $progress['total']];
        }
        if (isset($body['error'])) {
            $error = $body['error'];
            if (!is_array($error) || !is_string($error['code'] ?? null) || strlen($error['code']) > 60
                || !is_string($error['message'] ?? null) || strlen($error['message']) > 500) throw new DeviceError(422, 'invalid_error');
            $evidence['error'] = ['code' => $error['code'], 'message' => $error['message'], 'at' => SessionTransitions::now()];
        }
        return $evidence;
    }

    public static function assertTransition(string $status, array $evidence): void
    {
        if ($status === 'WINDOWS_PROFILE_READY' && ($evidence['profilePrepared'] ?? false) !== true) {
            throw new DeviceError(409, 'profile_not_confirmed');
        }
        if ($status === 'ACTIVE' && (($evidence['profilePrepared'] ?? false) !== true
            || (($evidence['windowsLogonConfirmed'] ?? false) !== true && ($evidence['testAdapter'] ?? false) !== true))) {
            throw new DeviceError(409, 'logon_not_confirmed');
        }
        if ($status === 'SYNCING_OUT' && ($evidence['sessionEnded'] ?? false) !== true) {
            throw new DeviceError(409, 'session_not_ended');
        }
        if (in_array($status, ['STORAGE_CONFIRMED', 'CLEANUP', 'CLOSED'], true)
            && (($evidence['storageConfirmed'] ?? false) !== true || ($evidence['sessionEnded'] ?? false) !== true)) {
            throw new DeviceError(409, 'storage_not_confirmed');
        }
        if ($status === 'CLOSED' && ($evidence['cleanupDone'] ?? false) !== true) {
            throw new DeviceError(409, 'cleanup_not_confirmed');
        }
    }
}
