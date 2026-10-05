<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use InvalidArgumentException;

final class AuthenticationMethod
{
    public const PASSWORD = 'password';
    public const GOOGLE = 'google';
    public const TELEGRAM = 'telegram';
    public const PASSKEY = 'passkey';
    public const EXTERNAL = 'external';

    private const FIRST_FACTORS = [self::PASSWORD, self::GOOGLE, self::TELEGRAM, self::PASSKEY, self::EXTERNAL];
    private const SECOND_FACTORS = ['totp', 'recovery_code'];

    public static function firstFactor(string $method): string
    {
        if (!in_array($method, self::FIRST_FACTORS, true)) {
            throw new InvalidArgumentException('Unsupported first-factor authentication method.');
        }

        return $method;
    }

    public static function withSecondFactor(string $firstFactor, string $secondFactor): string
    {
        self::firstFactor($firstFactor);
        if (!in_array($secondFactor, self::SECOND_FACTORS, true)) {
            throw new InvalidArgumentException('Unsupported second-factor authentication method.');
        }

        return $firstFactor . '+' . $secondFactor;
    }
}
