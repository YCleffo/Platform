<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Shared\Domain\ValueNormalizer;
use InvalidArgumentException;

final readonly class AuthIdentifierNormalizer
{
    public function normalize(string $type, string $value): string
    {
        return match ($type) {
            'email' => ValueNormalizer::normalizeEmail($value),
            'username' => ValueNormalizer::normalizeUsername($value),
            'phone' => ValueNormalizer::normalizePhone($value),
            default => throw new InvalidArgumentException('Unsupported authentication identifier type.'),
        };
    }
}
