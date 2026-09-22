<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/** @internal Recovery-code hash must not be passed to public presenters. */
final readonly class RecoveryCode
{
    public function __construct(
        public string $id,
        public string $accountId,
        public string $codeHashHex,
        public string $createdAt,
        public ?string $usedAt,
        public ?string $revokedAt,
    ) {}
}
