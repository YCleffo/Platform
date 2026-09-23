<?php

declare(strict_types=1);

namespace App\Core\Person\Infrastructure;

use App\Core\Person\Application\ContactNormalizer;
use App\Core\Shared\Domain\ValueNormalizer;
use InvalidArgumentException;

/**
 * Field-specific contact normalization for email and phone using canonical rules.
 */
final readonly class TrimOnlyContactNormalizer implements ContactNormalizer
{
    public function normalize(string $contactType, string $value): string
    {
        return match ($contactType) {
            'email' => ValueNormalizer::normalizeEmail($value),
            'phone' => ValueNormalizer::normalizePhone($value),
            default => throw new InvalidArgumentException("Unsupported contact type: {$contactType}"),
        };
    }
}
