<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Activity\Application\ActivityPluginRegistry;

/**
 * Compatibility code mapping & canonical plugin-schema config validation for Education & Homework.
 */
final class ActivityCode
{
    public static function canonical(string $code): string
    {
        return match ($code) {
            'kiberone-typing' => 'platform-typing',
            'kiberone-logic' => 'platform-logic',
            default => $code,
        };
    }

    /**
     * Translates legacy Education shorthand keys and validates strictly against canonical plugin schema.
     *
     * @param array<array-key, mixed> $config
     * @return array<string, mixed>
     */
    public static function toCanonicalConfig(ActivityPluginRegistry $plugins, string $code, array $config, bool $includeDefaults = false): array
    {
        $canonicalCode = self::canonical($code);
        if (!$plugins->has($canonicalCode)) {
            throw new EducationError(404, 'not_found');
        }
        if ($config !== [] && array_is_list($config)) {
            throw new EducationError(422, 'invalid_input');
        }

        $plugin = $plugins->get($canonicalCode);
        $schema = $plugin->configSchema();
        $out = $includeDefaults ? $plugin->defaultConfig() : [];

        $aliasMap = match ($canonicalCode) {
            'platform-typing' => ['durationSeconds' => 'timeLimitSeconds'],
            'platform-logic' => ['roundCount' => 'rounds'],
            'platform-math-sprint' => ['durationSeconds' => 'timeLimitSeconds'],
            default => [],
        };

        $allowedEducationExtras = match ($canonicalCode) {
            'platform-assessment' => ['assessmentId', 'maxAttempts'],
            'platform-math-sprint' => ['targetScore', 'language', 'maxAttempts'],
            default => ['maxAttempts'],
        };

        foreach ($config as $rawKey => $value) {
            if (!is_string($rawKey)) {
                throw new EducationError(422, 'invalid_input');
            }
            $key = $aliasMap[$rawKey] ?? $rawKey;

            if ($key === 'maxAttempts') {
                if (!is_int($value) || $value < 1 || $value > 20) {
                    throw new EducationError(422, 'invalid_input');
                }
                $out['maxAttempts'] = $value;
                continue;
            }
            if ($key === 'assessmentId' && in_array('assessmentId', $allowedEducationExtras, true)) {
                if ($value !== null && $value !== '') {
                    $out['assessmentId'] = Input::uuid($value);
                }
                continue;
            }
            if ($canonicalCode === 'platform-typing' && $key === 'mode' && $value === 'phrases') {
                $value = 'sentences';
            }
            if ($key === 'targetScore' && in_array('targetScore', $allowedEducationExtras, true)) {
                if (!is_int($value) || $value < 1 || $value > 200) {
                    throw new EducationError(422, 'invalid_input');
                }
                $out['targetScore'] = $value;
                continue;
            }
            if ($key === 'language' && !isset($schema['language']) && in_array('language', $allowedEducationExtras, true)) {
                if (!in_array($value, ['ru', 'en'], true)) {
                    throw new EducationError(422, 'invalid_input');
                }
                $out['language'] = $value;
                continue;
            }

            if (!array_key_exists($key, $schema)) {
                throw new EducationError(422, 'invalid_input');
            }
            $out[$key] = self::validateSchemaValue($schema[$key], $value);
        }

        if ($includeDefaults && !isset($out['maxAttempts'])) {
            $out['maxAttempts'] = 1;
        }

        return $out;
    }

    /**
     * Checks normalized canonical configs for equivalence (ignoring maxAttempts which is enforced by assignment/homework).
     *
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    public static function sameConfig(ActivityPluginRegistry $plugins, string $code, array $left, array $right): bool
    {
        $a = self::toCanonicalConfig($plugins, $code, $left, true);
        $b = self::toCanonicalConfig($plugins, $code, $right, true);
        unset($a['maxAttempts'], $b['maxAttempts']);
        ksort($a);
        ksort($b);

        return $a == $b;
    }

    /**
     * Checks whether a canonical activity attempt satisfies a homework assignment's configured plugin & params.
     *
     * @param array<array-key, mixed> $attemptRuntimeConfig
     * @param array<array-key, mixed> $assignedConfig
     */
    public static function matchesHomeworkConfig(
        ActivityPluginRegistry $plugins,
        string $code,
        string $attemptActivityId,
        array $attemptRuntimeConfig,
        array $assignedConfig,
    ): bool {
        $canonicalCode = self::canonical($code);
        if ($canonicalCode === 'platform-assessment' && isset($assignedConfig['assessmentId']) && is_string($assignedConfig['assessmentId'])) {
            return strtolower($attemptActivityId) === strtolower($assignedConfig['assessmentId']);
        }
        $expected = self::toCanonicalConfig($plugins, $canonicalCode, $assignedConfig, true);
        unset($expected['maxAttempts'], $expected['assessmentId'], $expected['targetScore']);
        if (!array_key_exists('minAccuracy', $assignedConfig)) {
            unset($expected['minAccuracy']);
        }
        if ($canonicalCode !== 'platform-logic' && $canonicalCode !== 'platform-typing') {
            unset($expected['language']);
        }
        $allowedKeys = array_flip([...array_keys($plugins->get($canonicalCode)->configSchema()), 'language', 'targetScore', 'assessmentId', 'maxAttempts']);
        $filteredActual = array_intersect_key($attemptRuntimeConfig, $allowedKeys);
        $actual = self::toCanonicalConfig($plugins, $canonicalCode, $filteredActual, true);
        foreach ($expected as $k => $v) {
            if (($actual[$k] ?? null) !== $v) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private static function validateSchemaValue(array $rule, mixed $value): mixed
    {
        $type = $rule['type'] ?? '';
        return match ($type) {
            'enum' => in_array($value, is_array($rule['values'] ?? null) ? $rule['values'] : [], true)
                ? $value
                : throw new EducationError(422, 'invalid_input'),
            'integer' => is_int($value)
                && (!isset($rule['min']) || $value >= (int) $rule['min'])
                && (!isset($rule['max']) || $value <= (int) $rule['max'])
                ? $value
                : throw new EducationError(422, 'invalid_input'),
            'boolean' => is_bool($value)
                ? $value
                : throw new EducationError(422, 'invalid_input'),
            'string' => is_string($value)
                && (!isset($rule['maxLength']) || mb_strlen($value, 'UTF-8') <= (int) $rule['maxLength'])
                ? trim($value)
                : throw new EducationError(422, 'invalid_input'),
            'multi_enum' => self::validateMultiEnum($rule, $value),
            default => throw new EducationError(422, 'invalid_input'),
        };
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private static function validateMultiEnum(array $rule, mixed $value): array
    {
        $allowed = is_array($rule['values'] ?? null) ? $rule['values'] : [];
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new EducationError(422, 'invalid_input');
        }
        $clean = [];
        foreach ($value as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true)) {
                throw new EducationError(422, 'invalid_input');
            }
            $clean[$item] = $item;
        }

        return array_values($clean);
    }
}
