<?php

declare(strict_types=1);

namespace App\Core\Module\Application;

final readonly class ModuleView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public ?string $description,
        public string $version,
        public int $settingsSchemaVersion,
        public bool $enabled,
        public string $installedAt,
        public ?string $enabledAt,
        public ?string $disabledAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'version' => $this->version,
            'settingsSchemaVersion' => $this->settingsSchemaVersion,
            'enabled' => $this->enabled,
            'installedAt' => $this->installedAt,
            'enabledAt' => $this->enabledAt,
            'disabledAt' => $this->disabledAt,
        ];
    }
}
