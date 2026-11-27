<?php

declare(strict_types=1);

namespace App\Shared\Application;

use RuntimeException;

/**
 * Shared structured HTTP domain error for modular API actions.
 */
class HttpError extends RuntimeException
{
    /** @param array<string, mixed>|null $meta */
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        ?string $message = null,
        public readonly ?array $meta = null,
    ) {
        parent::__construct($message ?? $reason);
    }
}
