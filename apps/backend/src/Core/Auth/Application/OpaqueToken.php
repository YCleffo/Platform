<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/** @internal Raw tokens may only live in issuance/request scope. */
final readonly class OpaqueToken
{
    public function __construct(
        public string $raw,
        public string $hashHex,
    ) {}
}
