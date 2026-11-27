<?php

declare(strict_types=1);

namespace App\Activity\Application;

/**
 * Shared typed payload normalization helpers for the Universal Activity Engine and its plugins.
 */
final class ActivityPayload
{
    /** @return array<string, mixed> */
    public static function decodeObject(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return self::assoc($decoded);
    }

    /** @param array<string, mixed> $value */
    public static function encodeObject(array $value): string
    {
        return $value === [] ? '{}' : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function assoc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /** @return list<string> */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }
        return $out;
    }

    /** @return list<int> */
    public static function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_numeric($item)) {
                $out[] = (int) $item;
            }
        }
        return $out;
    }
}
