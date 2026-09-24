<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Domain;

final class EmailPolicy
{
    /** @return array{email: string, normalized: string} */
    public static function normalize(string $value): array
    {
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException('Invalid email address.');
        }
        $email = trim($value);
        $normalized = strtolower($email);
        if ($email === '' || strlen($email) > 320 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid email address.');
        }

        return ['email' => $email, 'normalized' => $normalized];
    }
}
