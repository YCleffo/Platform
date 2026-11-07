<?php

declare(strict_types=1);

namespace App\Storage\Application;

use RuntimeException;

final class StorageError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
    ) {
        parent::__construct('Storage request rejected.');
    }
}
