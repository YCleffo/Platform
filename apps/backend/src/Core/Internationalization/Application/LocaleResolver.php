<?php

declare(strict_types=1);

namespace App\Core\Internationalization\Application;

final readonly class LocaleResolver
{
    public function __construct(private SupportedLocales $supportedLocales) {}

    public function resolve(
        ?string $explicitLocale,
        ?string $accountLocale,
        ?string $acceptLanguage,
    ): ResolvedLocale {
        $explicit = $this->candidate($explicitLocale);
        if ($explicit !== null) {
            return new ResolvedLocale($explicit, ResolvedLocale::EXPLICIT);
        }

        $account = $this->candidate($accountLocale);
        if ($account !== null) {
            return new ResolvedLocale($account, ResolvedLocale::ACCOUNT);
        }

        $header = $this->fromAcceptLanguage($acceptLanguage);
        if ($header !== null) {
            return new ResolvedLocale($header, ResolvedLocale::ACCEPT_LANGUAGE);
        }

        return new ResolvedLocale($this->supportedLocales->default(), ResolvedLocale::DEFAULT);
    }

    public function resolveAuthenticated(
        LocaleContext $requestLocale,
        ?string $accountLocale,
        ?string $acceptLanguage,
    ): ResolvedLocale {
        return $this->resolve(
            $requestLocale->source === ResolvedLocale::EXPLICIT ? $requestLocale->locale->tag : null,
            $accountLocale,
            $acceptLanguage,
        );
    }

    private function candidate(?string $value): ?\App\Core\Internationalization\Domain\Locale
    {
        return $value === null || trim($value) === ''
            ? null
            : $this->supportedLocales->fromLanguageSignal($value);
    }

    private function fromAcceptLanguage(?string $header): ?\App\Core\Internationalization\Domain\Locale
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $ranges = [];
        foreach (explode(',', $header) as $order => $item) {
            $parts = array_map(trim(...), explode(';', $item));
            $range = array_shift($parts);
            if ($range === '') {
                continue;
            }

            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/\Aq\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/i', $parameter, $match) === 1) {
                    $quality = (float) $match[1];
                    break;
                }
                if (preg_match('/\Aq\s*=/i', $parameter) === 1) {
                    $quality = 0.0;
                    break;
                }
            }
            if ($quality <= 0.0) {
                continue;
            }
            $ranges[] = ['range' => $range, 'quality' => $quality, 'order' => $order];
        }

        usort($ranges, static fn(array $left, array $right): int =>
            $right['quality'] <=> $left['quality'] ?: $left['order'] <=> $right['order']);

        foreach ($ranges as $range) {
            if ($range['range'] === '*') {
                return $this->supportedLocales->default();
            }
            $locale = $this->supportedLocales->fromLanguageSignal($range['range']);
            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }
}
