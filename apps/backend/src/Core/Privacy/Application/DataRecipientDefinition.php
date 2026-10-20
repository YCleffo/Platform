<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DateTimeImmutable;

final readonly class DataRecipientDefinition extends ProcessorDefinition
{
    /**
     * @param list<string> $purposes
     * @param list<string> $dataCategories
     */
    public function __construct(string $code, string $nameReference,
        array $purposes, array $dataCategories, string $country, string $contractReference,
        bool $securityRequirementsConfirmed, bool $localizationCompatible,
        DateTimeImmutable $activeFrom, ?DateTimeImmutable $activeUntil = null,
        ?string $name = null, ?string $address = null)
    {
        parent::__construct($code, $nameReference, 'recipient', $purposes, $dataCategories,
            $country, $contractReference, $securityRequirementsConfirmed,
            $localizationCompatible, $activeFrom, $activeUntil, $name, $address);
    }
}
