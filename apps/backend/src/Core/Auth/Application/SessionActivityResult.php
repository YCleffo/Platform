<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class SessionActivityResult
{
    public function __construct(
        public AuthSession $previous,
        public AuthSession $current,
        public bool $updated,
        public bool $ipChanged,
        public bool $deviceChanged,
    ) {}
}
