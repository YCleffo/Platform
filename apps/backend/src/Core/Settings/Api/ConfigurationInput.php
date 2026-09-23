<?php

declare(strict_types=1);

namespace App\Core\Settings\Api;

use App\Api\Shared\ApiMessage;
use App\Core\Settings\Application\SettingSecurityPolicy;
use App\Core\Shared\Domain\ValueNormalizer;
use InvalidArgumentException;
use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\Validator\Result;

final class ConfigurationInput
{
    private const int MAX_JSON_BYTES = 16_384;
    private const int MAX_DESCRIPTION_LENGTH = 1_000;

    public static function moduleCode(string $code): string
    {
        return self::identifier($code, 100, 'moduleCode', ApiMessage::VALIDATION_CODE);
    }

    public static function featureCode(string $code): string
    {
        return self::identifier($code, 160, 'code', ApiMessage::VALIDATION_CODE);
    }

    public static function settingKey(string $key): string
    {
        return self::identifier($key, 160, 'key', ApiMessage::VALIDATION_KEY);
    }

    /** @return array{value: mixed, isPublic: bool, description: ?string} */
    public static function systemSetting(
        mixed $body,
        string $key,
        SettingSecurityPolicy $securityPolicy,
    ): array {
        $data = self::object($body);
        $result = new Result();
        self::rejectUnknown($data, ['value', 'isPublic', 'description'], $result);

        $hasValue = array_key_exists('value', $data);
        $value = $data['value'] ?? null;
        if (!$hasValue) {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['value']);
        } elseif (!self::jsonFits($value)) {
            $result->addError(ApiMessage::VALIDATION_JSON_TOO_LARGE, valuePath: ['value']);
        } elseif (!$securityPolicy->isSafe($key, $value)) {
            $result->addError(ApiMessage::VALIDATION_SECRET_KEY_FORBIDDEN, valuePath: ['key']);
        }

        $isPublic = $data['isPublic'] ?? false;
        if (!is_bool($isPublic)) {
            $result->addError(ApiMessage::VALIDATION_BOOLEAN, valuePath: ['isPublic']);
        }
        $description = self::description($data, $result);
        self::throwIfInvalid($result);

        return [
            'value' => $value,
            'isPublic' => (bool) $isPublic,
            'description' => $description,
        ];
    }

    /** @return array{value: mixed, description: ?string} */
    public static function moduleSetting(
        mixed $body,
        string $key,
        SettingSecurityPolicy $securityPolicy,
    ): array {
        $data = self::object($body);
        $result = new Result();
        self::rejectUnknown($data, ['value', 'description'], $result);

        $hasValue = array_key_exists('value', $data);
        $value = $data['value'] ?? null;
        if (!$hasValue) {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['value']);
        } elseif (!self::jsonFits($value)) {
            $result->addError(ApiMessage::VALIDATION_JSON_TOO_LARGE, valuePath: ['value']);
        } elseif (!$securityPolicy->isSafe($key, $value)) {
            $result->addError(ApiMessage::VALIDATION_SECRET_KEY_FORBIDDEN, valuePath: ['key']);
        }

        $description = self::description($data, $result);
        self::throwIfInvalid($result);

        return ['value' => $value, 'description' => $description];
    }

    /** @return array{moduleCode: ?string, enabled: bool, rolloutPercent: int, config: array<array-key, mixed>, description: ?string} */
    public static function featureFlag(
        mixed $body,
        SettingSecurityPolicy $securityPolicy,
    ): array {
        $data = self::object($body);
        $result = new Result();
        self::rejectUnknown($data, ['moduleCode', 'enabled', 'rolloutPercent', 'config', 'description'], $result);

        $moduleCode = $data['moduleCode'] ?? null;
        if ($moduleCode !== null) {
            if (!is_string($moduleCode) || !ValueNormalizer::isValidMachineCode($moduleCode, 100)) {
                $result->addError(ApiMessage::VALIDATION_CODE, valuePath: ['moduleCode']);
            }
        }

        $enabled = $data['enabled'] ?? null;
        if (!is_bool($enabled)) {
            $result->addError(ApiMessage::VALIDATION_BOOLEAN, valuePath: ['enabled']);
        }

        $rolloutPercent = $data['rolloutPercent'] ?? null;
        if (!is_int($rolloutPercent)) {
            $result->addError(ApiMessage::VALIDATION_INTEGER, valuePath: ['rolloutPercent']);
        } elseif ($rolloutPercent < 0 || $rolloutPercent > 100) {
            $result->addError(
                ApiMessage::VALIDATION_LIMIT_RANGE,
                ['min' => 0, 'max' => 100],
                ['rolloutPercent'],
            );
        }

        $config = $data['config'] ?? null;
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            $result->addError(ApiMessage::VALIDATION_JSON_OBJECT, valuePath: ['config']);
        } elseif (!self::jsonFits($config)) {
            $result->addError(ApiMessage::VALIDATION_JSON_TOO_LARGE, valuePath: ['config']);
        } elseif (!$securityPolicy->isSafe('featureConfig', $config)) {
            $result->addError(ApiMessage::VALIDATION_SECRET_KEY_FORBIDDEN, valuePath: ['config']);
        }

        $description = self::description($data, $result);
        self::throwIfInvalid($result);

        $safeConfig = is_array($config) ? $config : [];
        return [
            'moduleCode' => $moduleCode === null ? null : (string) $moduleCode,
            'enabled' => (bool) $enabled,
            'rolloutPercent' => (int) $rolloutPercent,
            'config' => $safeConfig,
            'description' => $description,
        ];
    }

    private static function identifier(string $value, int $maxLength, string $field, string $message): string
    {
        try {
            return ValueNormalizer::normalizeMachineCode($value, $maxLength);
        } catch (InvalidArgumentException) {
            throw new InputValidationException(
                (new Result())->addError($message, valuePath: [$field]),
            );
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $body): array
    {
        if (!is_array($body)) {
            throw new InputValidationException(
                (new Result())->addError(ApiMessage::VALIDATION_BODY_OBJECT_REQUIRED),
            );
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private static function rejectUnknown(array $data, array $allowed, Result $result): void
    {
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $allowed, true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [$field]);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function description(array $data, Result $result): ?string
    {
        $description = $data['description'] ?? null;
        if ($description !== null && !is_string($description)) {
            $result->addError(ApiMessage::VALIDATION_STRING_OR_NULL, valuePath: ['description']);
            return null;
        }
        if (is_string($description)) {
            try {
                return ValueNormalizer::normalizeText($description, self::MAX_DESCRIPTION_LENGTH);
            } catch (InvalidArgumentException) {
                $result->addError(ApiMessage::VALIDATION_TEXT_INVALID, valuePath: ['description']);
                return null;
            }
        }

        return $description;
    }

    private static function jsonFits(mixed $value): bool
    {
        try {
            return strlen(json_encode($value, JSON_THROW_ON_ERROR)) <= self::MAX_JSON_BYTES;
        } catch (\JsonException) {
            return false;
        }
    }

    private static function throwIfInvalid(Result $result): void
    {
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
    }
}
