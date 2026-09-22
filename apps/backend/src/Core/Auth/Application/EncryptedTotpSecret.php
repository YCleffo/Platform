<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class EncryptedTotpSecret
{
    public function __construct(
        public string $ciphertextHex,
        public int $keyVersion,
    ) {}
}
