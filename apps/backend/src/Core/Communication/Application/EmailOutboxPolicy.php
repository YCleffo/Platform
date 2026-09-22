<?php

declare(strict_types=1);

namespace App\Core\Communication\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class EmailOutboxPolicy
{
    public function __construct(
        public int $leaseSeconds,
        public int $maxAttempts,
        public int $retryBaseSeconds,
        public int $retryMaxSeconds,
        public string $messageIdDomain,
    ) {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Email lease must be between 1 and 3600 seconds.');
        }
        if ($maxAttempts < 1 || $maxAttempts > 100) {
            throw new InvalidArgumentException('Email max attempts must be between 1 and 100.');
        }
        if ($retryBaseSeconds < 1 || $retryMaxSeconds < $retryBaseSeconds) {
            throw new InvalidArgumentException('Email retry interval is invalid.');
        }
        if (
            trim($messageIdDomain) === ''
            || preg_match('/[\s<>@]/', $messageIdDomain) === 1
        ) {
            throw new InvalidArgumentException('Email Message-ID domain is invalid.');
        }
    }

    public function retryAt(int $attempts): string
    {
        $exponent = max(0, min(30, $attempts - 1));
        $delay = min(
            $this->retryMaxSeconds,
            $this->retryBaseSeconds * (2 ** $exponent),
        );

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('+%d seconds', $delay))
            ->format(DATE_ATOM);
    }
}
