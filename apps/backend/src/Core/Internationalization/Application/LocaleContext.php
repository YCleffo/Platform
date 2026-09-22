<?php

declare(strict_types=1);

namespace App\Core\Internationalization\Application;

use App\Core\Internationalization\Domain\Locale;

final readonly class LocaleContext
{
    public function __construct(public Locale $locale, public string $source) {}

    public static function fromResolved(ResolvedLocale $resolved): self
    {
        return new self($resolved->locale, $resolved->source);
    }
}
