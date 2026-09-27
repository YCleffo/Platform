<?php

declare(strict_types=1);

namespace App\Core\AccountSettings\Application;

use App\Api\Shared\ApiMessage;
use RuntimeException;
use Yiisoft\Validator\Result;

final class SettingsSchemaRegistry
{
    public const int CURRENT_VERSION = 1;
    public const int MAX_PAYLOAD_BYTES = 32768;
    private const int MAX_DEPTH = 6;

    /** @var array<string, AccountSettingDefinition> */
    private array $definitions = [];

    /** @param list<AccountSettingDefinition> $definitions */
    public function __construct(array $definitions = [])
    {
        foreach ([...self::coreDefinitions(), ...$definitions] as $definition) {
            if (isset($this->definitions[$definition->path])) {
                throw new RuntimeException('Duplicate account setting definition: ' . $definition->path);
            }
            $this->definitions[$definition->path] = $definition;
        }
    }

    /** @return list<AccountSettingDefinition> */
    public static function coreDefinitions(): array
    {
        return [
            new AccountSettingDefinition('interface.animations', 'boolean', true),
            new AccountSettingDefinition('interface.transparency', 'boolean', true),
            new AccountSettingDefinition('notifications.browser', 'boolean', true),
            new AccountSettingDefinition('notifications.sound', 'boolean', true),
            new AccountSettingDefinition('notifications.email', 'boolean', false),
            new AccountSettingDefinition('privacy.show_online_status', 'boolean', true),
            new AccountSettingDefinition('privacy.profile_visibility.students', 'boolean', true),
            new AccountSettingDefinition('privacy.profile_visibility.parents', 'boolean', true),
            new AccountSettingDefinition('privacy.profile_visibility.colleagues', 'boolean', true),
        ];
    }

    public function schemaVersion(): int
    {
        return self::CURRENT_VERSION;
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->definitions as $definition) {
            $defaults = self::withPath($defaults, explode('.', $definition->path), $definition->default);
        }
        return $defaults;
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function validatePatch(array $patch): array
    {
        $encoded = json_encode($patch, JSON_THROW_ON_ERROR);
        $result = new Result();
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            $result->addError(ApiMessage::VALIDATION_JSON_TOO_LARGE);
        }
        if ($patch === []) {
            $result->addError(ApiMessage::VALIDATION_UPDATE_REQUIRED);
        } else {
            $this->validateNode($patch, '', 1, $result, true);
        }
        if (!$result->isValid()) {
            throw new SettingsValidationException($result);
        }
        return $patch;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public function upgradeStored(array $stored, int $version): array
    {
        if ($version < 1 || $version > self::CURRENT_VERSION) {
            throw new RuntimeException('Unsupported account settings schema version.');
        }
        if ($stored !== []) {
            $result = new Result();
            $this->validateNode($stored, '', 1, $result, false);
            if (!$result->isValid()) {
                throw new RuntimeException('Stored account settings do not match their schema version.');
            }
        }
        // The explicit hook is the upgrade seam for a future v1 -> v2 migration.
        return $stored;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function effective(array $overrides, int $version = self::CURRENT_VERSION): array
    {
        return self::deepMerge($this->defaults(), $this->upgradeStored($overrides, $version));
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function applyPatch(array $overrides, array $patch): array
    {
        foreach (self::flatten($patch) as $path => $value) {
            $segments = explode('.', $path);
            if ($value === null) {
                $overrides = self::withoutPath($overrides, $segments);
            } else {
                $overrides = self::withPath($overrides, $segments, $value);
            }
        }
        return $overrides;
    }

    /** @param array<array-key, mixed> $value */
    private function validateNode(array $value, string $prefix, int $depth, Result $result, bool $allowNull): void
    {
        if ($depth > self::MAX_DEPTH) {
            $result->addError(ApiMessage::VALIDATION_INVALID, valuePath: $prefix === '' ? [] : explode('.', $prefix));
            return;
        }
        foreach ($value as $key => $candidate) {
            if (!is_string($key) || $key === '' || in_array(strtolower($key), ['__proto__', 'prototype', 'constructor'], true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [...($prefix === '' ? [] : explode('.', $prefix)), (string) $key]);
                continue;
            }
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            $definition = $this->definitions[$path] ?? null;
            $isBranch = $this->isBranch($path);
            if ($definition === null && !$isBranch) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: explode('.', $path));
                continue;
            }
            if ($definition !== null) {
                if ($candidate === null && $allowNull) {
                    continue;
                }
                $this->validateLeaf($definition, $candidate, $result);
                continue;
            }
            if (!is_array($candidate) || $candidate === [] || array_is_list($candidate)) {
                $result->addError(ApiMessage::VALIDATION_JSON_OBJECT, valuePath: explode('.', $path));
                continue;
            }
            /** @var array<string, mixed> $nested */
            $nested = $candidate;
            $this->validateNode($nested, $path, $depth + 1, $result, $allowNull);
        }
    }

    private function validateLeaf(AccountSettingDefinition $definition, mixed $value, Result $result): void
    {
        $path = explode('.', $definition->path);
        $valid = match ($definition->type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'string' => is_string($value),
            'enum' => is_string($value) && in_array($value, $definition->allowedValues ?? [], true),
            'list' => is_array($value) && array_is_list($value),
            default => false,
        };
        if (!$valid) {
            $message = match ($definition->type) {
                'boolean' => ApiMessage::VALIDATION_BOOLEAN,
                'integer' => ApiMessage::VALIDATION_INTEGER,
                'enum' => ApiMessage::VALIDATION_ENUM_INVALID,
                default => ApiMessage::VALIDATION_INVALID,
            };
            $result->addError($message, valuePath: $path);
            return;
        }
        if (is_int($value) && (($definition->minimum !== null && $value < $definition->minimum)
            || ($definition->maximum !== null && $value > $definition->maximum))) {
            $result->addError(ApiMessage::VALIDATION_LIMIT_RANGE, valuePath: $path);
        }
        if ($definition->type === 'list' && is_array($value)) {
            if ($definition->maximumItems !== null && count($value) > $definition->maximumItems) {
                $result->addError(ApiMessage::VALIDATION_LIMIT_RANGE, valuePath: $path);
            }
            foreach ($value as $index => $item) {
                $itemValid = match ($definition->itemType) {
                    'boolean' => is_bool($item),
                    'integer' => is_int($item),
                    'string' => is_string($item),
                    default => false,
                };
                if (!$itemValid) {
                    $result->addError(ApiMessage::VALIDATION_INVALID, valuePath: [...$path, $index]);
                }
            }
        }
    }

    private function isBranch(string $path): bool
    {
        foreach (array_keys($this->definitions) as $definedPath) {
            if (str_starts_with($definedPath, $path . '.')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public static function flatten(array $value, string $prefix = ''): array
    {
        $flat = [];
        foreach ($value as $key => $candidate) {
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($candidate) && $candidate !== [] && !array_is_list($candidate)) {
                /** @var array<string, mixed> $nested */
                $nested = $candidate;
                $flat += self::flatten($nested, $path);
            } else {
                $flat[$path] = $candidate;
            }
        }
        return $flat;
    }

    /**
     * @param array<string, mixed> $target
     * @param non-empty-list<string> $segments
     * @return array<string, mixed>
     */
    private static function withPath(array $target, array $segments, mixed $value): array
    {
        $segment = array_shift($segments);
        if ($segments === []) {
            $target[$segment] = $value;
            return $target;
        }
        $candidate = $target[$segment] ?? [];
        /** @var array<string, mixed> $child */
        $child = is_array($candidate) && !array_is_list($candidate) ? $candidate : [];
        $target[$segment] = self::withPath($child, $segments, $value);
        return $target;
    }

    /**
     * @param array<string, mixed> $target
     * @param non-empty-list<string> $segments
     * @return array<string, mixed>
     */
    private static function withoutPath(array $target, array $segments): array
    {
        $segment = array_shift($segments);
        if (!array_key_exists($segment, $target)) {
            return $target;
        }
        if ($segments === []) {
            unset($target[$segment]);
            return $target;
        }
        if (is_array($target[$segment])) {
            /** @var array<string, mixed> $child */
            $child = $target[$segment];
            $child = self::withoutPath($child, $segments);
            if ($child === []) {
                unset($target[$segment]);
            } else {
                $target[$segment] = $child;
            }
        }
        return $target;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                /** @var array<string, mixed> $baseChild */
                $baseChild = $base[$key];
                /** @var array<string, mixed> $overrideChild */
                $overrideChild = $value;
                $base[$key] = self::deepMerge($baseChild, $overrideChild);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
