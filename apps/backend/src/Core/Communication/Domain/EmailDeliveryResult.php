<?php

declare(strict_types=1);

namespace App\Core\Communication\Domain;

use InvalidArgumentException;

final readonly class EmailDeliveryResult
{
    private function __construct(
        public bool $successful,
        public ?string $providerMessageId,
        public ?string $error,
    ) {
        if ($successful && $error !== null) {
            throw new InvalidArgumentException('A successful delivery cannot contain an error.');
        }
        if (!$successful && ($error === null || trim($error) === '')) {
            throw new InvalidArgumentException('A failed delivery must contain an error.');
        }
    }

    public static function success(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
