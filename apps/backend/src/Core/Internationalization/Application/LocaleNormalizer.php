<?php

declare(strict_types=1);

namespace App\Core\Internationalization\Application;

use App\Core\Internationalization\Domain\Locale;
use InvalidArgumentException;

final readonly class LocaleNormalizer
{
    public function normalize(string $value): Locale
    {
        $input = trim($value);
        if ($input === '' || strlen($input) > 35) {
            throw new InvalidArgumentException('Locale is empty or too long.');
        }

        $canonical = \Locale::canonicalize(str_replace('-', '_', $input));
        if ($canonical === null || $canonical === '') {
            throw new InvalidArgumentException('Locale is not a valid language tag.');
        }

        $tag = str_replace('_', '-', $canonical);
        $tag = match (strtolower($tag)) {
            'ru' => 'ru-RU',
            'en' => 'en-US',
            default => $tag,
        };

        return new Locale($tag);
    }
}
