<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Time\Domain\TimeZone;
use InvalidArgumentException;

final readonly class UpdateAccountPreferences
{
    public function __construct(
        private AccountPreferencesRepository $repository,
        private SupportedLocales $supportedLocales,
    ) {}

    public function update(
        string $accountId,
        ?string $locale,
        ?string $timezone,
    ): ?AccountInternationalPreferences {
        $canonicalLocale = null;
        if ($locale !== null) {
            $supportedLocale = $this->supportedLocales->match($locale);
            if ($supportedLocale === null) {
                throw new InvalidArgumentException('Account locale is unsupported.');
            }
            $canonicalLocale = $supportedLocale->tag;
        }
        $ianaTimezone = $timezone === null
            ? null
            : (new TimeZone($timezone))->identifier;

        return $this->repository->save($accountId, $canonicalLocale, $ianaTimezone);
    }
}
