<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Application;

final readonly class AccountSettingDefinition
{
    /**
     * @param bool|int|string|list<bool|int|string> $default
     * @param list<string>|null $allowedValues
     */
    public function __construct(
        public string $path,
        public string $type,
        public bool|int|string|array $default,
        public ?array $allowedValues = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public ?int $maximumItems = null,
        public ?string $itemType = null,
    ) {}
}
