<?php

declare(strict_types=1);

namespace App\Core\Internationalization\Application;

use App\Core\Internationalization\Domain\Locale;
use InvalidArgumentException;

final readonly class ResolvedLocale
{
    public const EXPLICIT = 'explicit';
    public const ACCOUNT = 'account';
    public const ACCEPT_LANGUAGE = 'accept-language';
    public const DEFAULT = 'default';

    public function __construct(public Locale $locale, public string $source)
    {
        if (!in_array($source, [self::EXPLICIT, self::ACCOUNT, self::ACCEPT_LANGUAGE, self::DEFAULT], true)) {
            throw new InvalidArgumentException('Locale resolution source is invalid.');
        }
    }
}
