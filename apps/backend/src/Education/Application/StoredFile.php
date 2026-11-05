<?php

declare(strict_types=1);

namespace App\Education\Application;

final readonly class StoredFile
{
    public function __construct(public string $key, public string $name, public string $mime,
        public int $size, public string $sha256) {}
}
