<?php

declare(strict_types=1);

namespace App\Core\Internationalization\Domain;

use InvalidArgumentException;

final readonly class Locale implements \Stringable
{
    public function __construct(public string $tag)
    {
        if (preg_match('/\A[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?(?:-[A-Za-z0-9]{5,8})*\z/', $tag) !== 1) {
            throw new InvalidArgumentException('Locale must be a canonical BCP 47 tag.');
        }
    }

    public function language(): string
    {
        return strtolower(explode('-', $this->tag, 2)[0]);
    }

    public function __toString(): string
    {
        return $this->tag;
    }
}
