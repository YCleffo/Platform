<?php

declare(strict_types=1);

namespace App\Core\Internationalization\Application;

use App\Core\Internationalization\Domain\Locale;
use InvalidArgumentException;

final readonly class SupportedLocales
{
    /** @var non-empty-list<Locale> */
    private array $locales;

    private Locale $defaultLocale;

    /** @param non-empty-list<string> $localeTags */
    public function __construct(
        private LocaleNormalizer $normalizer,
        array $localeTags,
        string $defaultLocale,
    ) {
        $normalized = [];
        foreach ($localeTags as $tag) {
            $locale = $normalizer->normalize($tag);
            $normalized[$locale->tag] = $locale;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('At least one supported locale is required.');
        }

        $default = $normalizer->normalize($defaultLocale);
        if (!isset($normalized[$default->tag])) {
            throw new InvalidArgumentException('Default locale must be supported.');
        }

        /** @var non-empty-list<Locale> */
        $localeList = array_values($normalized);
        $this->locales = $localeList;
        $this->defaultLocale = $default;
    }

    public function default(): Locale
    {
        return $this->defaultLocale;
    }

    public function match(string $candidate): ?Locale
    {
        try {
            $normalized = $this->normalizer->normalize($candidate);
        } catch (InvalidArgumentException) {
            return null;
        }

        foreach ($this->locales as $locale) {
            if ($locale->tag === $normalized->tag) {
                return $locale;
            }
        }
        foreach ($this->locales as $locale) {
            if ($locale->language() === $normalized->language()) {
                return $locale;
            }
        }

        return null;
    }

    public function resolve(string $candidate): Locale
    {
        return $this->fromLanguageSignal($candidate) ?? $this->defaultLocale;
    }

    /**
     * Maps a valid client language signal to the translations currently available.
     * Exact/configured language families win; any other valid language uses English.
     */
    public function fromLanguageSignal(string $candidate): ?Locale
    {
        try {
            $normalized = $this->normalizer->normalize($candidate);
        } catch (InvalidArgumentException) {
            return null;
        }

        $supported = $this->match($normalized->tag);
        if ($supported !== null) {
            return $supported;
        }

        foreach ($this->locales as $locale) {
            if ($locale->language() === 'en') {
                return $locale;
            }
        }

        return $this->defaultLocale;
    }

    /** @return non-empty-list<string> */
    public function tags(): array
    {
        return array_map(
            static fn(Locale $locale): string => $locale->tag,
            $this->locales,
        );
    }
}
