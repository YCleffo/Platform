<?php

declare(strict_types=1);

namespace ControlPlane\Shared\Domain;

final class IdentifierPolicy
{
    public static function machineCode(string $value, int $maxLength = 120): string
    {
        if (strlen($value) < 1 || strlen($value) > $maxLength || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid canonical machine code.');
        }

        return $value;
    }

    public static function slug(string $value): string
    {
        $reserved = ['www', 'api', 'admin', 'app', 'auth', 'billing', 'control', 'control-plane', 'support', 'status', 'cdn', 'storage', 'static', 'mail', 'smtp', 'root', 'system', 'platform'];
        if (strlen($value) < 1 || strlen($value) > 63 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $value) !== 1 || in_array($value, $reserved, true)) {
            throw new \InvalidArgumentException('Invalid system slug.');
        }

        return $value;
    }

    public static function opaqueKey(string $value, int $maxLength = 200): string
    {
        if (strlen($value) < 1 || strlen($value) > $maxLength || preg_match('/\A[!-~]+\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid opaque key.');
        }

        return $value;
    }
}
