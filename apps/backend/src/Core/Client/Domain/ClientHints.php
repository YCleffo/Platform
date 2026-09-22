<?php

declare(strict_types=1);

namespace App\Core\Client\Domain;

final readonly class ClientHints
{
    public function __construct(
        public ?string $brands,
        public ?string $platform,
        public ?bool $mobile,
    ) {}
}
