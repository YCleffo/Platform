<?php

declare(strict_types=1);

namespace App\Media\Application;

use RuntimeException;

final class MediaError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $reason);
    }
}
