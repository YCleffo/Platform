<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

final class SettingSecurityPolicy
{
    private const FORBIDDEN_TERMS = [
        'password',
        'passwd',
        'secret',
        'token',
        'privatekey',
        'apikey',
        'credential',
    ];

    public function isSafe(string $key, mixed $value): bool
    {
        return !$this->isForbiddenKey($key) && $this->isSafeValue($value);
    }

    public function assertSafe(string $key, mixed $value): void
    {
        if (!$this->isSafe($key, $value)) {
            throw new UnsafeSettingException('Settings and feature configuration cannot contain secret-like keys.');
        }
    }

    public function assertValueSafe(mixed $value): void
    {
        if (!$this->isSafeValue($value)) {
            throw new UnsafeSettingException('Feature configuration cannot contain secret-like keys.');
        }
    }

    private function isForbiddenKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower($key)) ?? '';
        foreach (self::FORBIDDEN_TERMS as $term) {
            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }

    private function isSafeValue(mixed $value): bool
    {
        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $key => $nestedValue) {
            if (is_string($key) && $this->isForbiddenKey($key)) {
                return false;
            }
            if (!$this->isSafeValue($nestedValue)) {
                return false;
            }
        }

        return true;
    }
}
