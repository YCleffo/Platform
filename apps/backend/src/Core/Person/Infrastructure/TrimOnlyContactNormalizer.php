<?php

declare(strict_types=1);

namespace App\Core\Person\Infrastructure;

use App\Core\Person\Application\ContactNormalizer;

/**
 * Conservative phase-one normalization. Canonical email/phone rules must be
 * introduced as an explicit policy, not guessed inside persistence code.
 */
final readonly class TrimOnlyContactNormalizer implements ContactNormalizer
{
    public function normalize(string $contactType, string $value): string
    {
        return trim($value);
    }
}
