<?php

declare(strict_types=1);

namespace App\DeviceManagement\Application;

/** Evidence is bound to one state report; a prepared account is never a logon. */
final class SessionEvidence
{
    public static function validate(array $body): array
    {
        $evidence = $body['evidence'] ?? [];
        if (!is_array($evidence) || array_diff(
            array_keys($evidence),
            ['profilePrepared', 'windowsLogonConfirmed', 'testAdapter', 'sessionEnded', 'storageConfirmed', 'cleanupDone'],
        ) !== []) {
            throw new DeviceError(422, 'invalid_evidence');
        }
        foreach ($evidence as $value) {
            if (!is_bool($value)) {
                throw new DeviceError(422, 'invalid_evidence');
            }
        }
        if (isset($body['progress'])) {
            $progress = $body['progress'];
            $stage = is_array($progress) ? ($progress['stage'] ?? null) : null;
            $completed = is_array($progress) ? ($progress['completed'] ?? null) : null;
            $total = is_array($progress) ? ($progress['total'] ?? null) : null;
            if (!is_string($stage) || strlen($stage) > 40 || !is_int($completed)
                || !is_int($total) || $completed < 0
                || $total < $completed) {
                throw new DeviceError(422, 'invalid_progress');
            }
            $evidence['progress'] = ['stage' => $stage, 'completed' => $completed, 'total' => $total];
        }
        if (isset($body['error'])) {
            $error = $body['error'];
            $code = is_array($error) ? ($error['code'] ?? null) : null;
            $message = is_array($error) ? ($error['message'] ?? null) : null;
            if (!is_string($code) || strlen($code) > 60
                || !is_string($message) || strlen($message) > 500) {
                throw new DeviceError(422, 'invalid_error');
            }
            $evidence['error'] = ['code' => $code, 'message' => $message, 'at' => SessionTransitions::now()];
        }
        return $evidence;
    }

    public static function assertTransition(string $status, array $evidence, bool $allowTestAdapter = false): void
    {
        $testAdapter = ($evidence['testAdapter'] ?? false) === true;
        $windowsLogon = ($evidence['windowsLogonConfirmed'] ?? false) === true;
        if ($testAdapter && !$allowTestAdapter) {
            throw new DeviceError(409, 'test_adapter_not_allowed');
        }
        if ($testAdapter && $windowsLogon) {
            throw new DeviceError(422, 'invalid_evidence');
        }
        if ($status === 'WINDOWS_PROFILE_READY' && ($evidence['profilePrepared'] ?? false) !== true) {
            throw new DeviceError(409, 'profile_not_confirmed');
        }
        if ($status === 'ACTIVE' && (($evidence['profilePrepared'] ?? false) !== true
            || (!$windowsLogon && !$testAdapter))) {
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
