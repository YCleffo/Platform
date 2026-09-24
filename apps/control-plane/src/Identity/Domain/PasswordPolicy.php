<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Domain;

final class PasswordPolicy
{
    public const MIN_BYTES = 12;
    public const MAX_BYTES = 4096;

    public static function validate(string $password): void
    {
        $length = strlen($password);
        if ($length < self::MIN_BYTES || $length > self::MAX_BYTES || preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            throw new \InvalidArgumentException('Password length or characters are invalid.');
        }
    }
}
