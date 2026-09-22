<?php

declare(strict_types=1);

namespace App\Core\Person\Api;

use App\Api\Shared\ApiMessage;
use App\Core\Person\Application\CreatePersonCommand;
use App\Core\Person\Application\CreatePersonContactCommand;
use App\Core\Person\Application\UpdatePersonCommand;
use DateTimeImmutable;
use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\Validator\Result;

final class PersonInput
{
    public static function createPerson(mixed $body): CreatePersonCommand
    {
        $data = self::body($body);
        $result = new Result();
        self::rejectUnknown($data, ['givenName', 'familyName', 'middleName', 'displayName', 'birthDate'], $result);
        self::string($data, 'givenName', 120, false, true, $result);
        self::string($data, 'familyName', 120, false, true, $result);
        self::string($data, 'middleName', 120, true, false, $result);
        self::string($data, 'displayName', 255, true, false, $result);
        self::date($data, 'birthDate', false, $result);
        self::throwIfInvalid($result);

        return new CreatePersonCommand(
            (string) $data['givenName'],
            (string) $data['familyName'],
            self::nullableString($data['middleName'] ?? null),
            self::nullableString($data['displayName'] ?? null),
            self::nullableString($data['birthDate'] ?? null),
        );
    }

    public static function updatePerson(string $id, mixed $body): UpdatePersonCommand
    {
        $data = self::body($body);
        $result = new Result();
        $allowed = ['givenName', 'familyName', 'middleName', 'displayName', 'birthDate'];
        self::rejectUnknown($data, $allowed, $result);

        $provided = array_filter(
            $allowed,
            static fn(string $field): bool => array_key_exists($field, $data),
        );
        if ($provided === []) {
            $result->addError(ApiMessage::VALIDATION_UPDATE_REQUIRED);
        }

        self::string($data, 'givenName', 120, false, false, $result);
        self::string($data, 'familyName', 120, false, false, $result);
        self::string($data, 'middleName', 120, true, false, $result);
        self::string($data, 'displayName', 255, true, false, $result);
        self::date($data, 'birthDate', false, $result);
        self::throwIfInvalid($result);

        return new UpdatePersonCommand(
            id: $id,
            givenNameProvided: array_key_exists('givenName', $data),
            givenName: self::nullableString($data['givenName'] ?? null),
            familyNameProvided: array_key_exists('familyName', $data),
            familyName: self::nullableString($data['familyName'] ?? null),
            middleNameProvided: array_key_exists('middleName', $data),
            middleName: self::nullableString($data['middleName'] ?? null),
            displayNameProvided: array_key_exists('displayName', $data),
            displayName: self::nullableString($data['displayName'] ?? null),
            birthDateProvided: array_key_exists('birthDate', $data),
            birthDate: self::nullableString($data['birthDate'] ?? null),
        );
    }

    public static function createContact(string $personId, mixed $body): CreatePersonContactCommand
    {
        $data = self::body($body);
        $result = new Result();
        self::rejectUnknown($data, ['contactType', 'value', 'label', 'isPrimary'], $result);
        self::string($data, 'contactType', 20, false, true, $result);
        self::string($data, 'value', 320, false, true, $result);
        self::string($data, 'label', 80, true, false, $result);

        if (
            isset($data['contactType'])
            && is_string($data['contactType'])
            && !in_array($data['contactType'], ['email', 'phone'], true)
        ) {
            $result->addError(ApiMessage::VALIDATION_CONTACT_TYPE, valuePath: ['contactType']);
        }
        if (array_key_exists('isPrimary', $data) && !is_bool($data['isPrimary'])) {
            $result->addError(ApiMessage::VALIDATION_BOOLEAN, valuePath: ['isPrimary']);
        }
        self::throwIfInvalid($result);

        return new CreatePersonContactCommand(
            personId: $personId,
            contactType: (string) ($data['contactType'] ?? ''),
            value: (string) $data['value'],
            label: self::nullableString($data['label'] ?? null),
            isPrimary: self::boolean($data['isPrimary'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(mixed $body): array
    {
        if (!is_array($body)) {
            $result = (new Result())->addError(ApiMessage::VALIDATION_BODY_OBJECT_REQUIRED);
            throw new InputValidationException($result);
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private static function rejectUnknown(array $data, array $allowed, Result $result): void
    {
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $allowed, true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [$field]);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(
        array $data,
        string $field,
        int $maxLength,
        bool $nullable,
        bool $required,
        Result $result,
    ): void {
        if (!array_key_exists($field, $data)) {
            if ($required) {
                $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: [$field]);
            }
            return;
        }

        $value = $data[$field];
        if ($value === null && $nullable) {
            return;
        }
        if (!is_string($value)) {
            $result->addError(ApiMessage::VALIDATION_STRING, valuePath: [$field]);
            return;
        }
        if (!$nullable && trim($value) === '') {
            $result->addError(ApiMessage::VALIDATION_NOT_BLANK, valuePath: [$field]);
        }
        if (self::unicodeLength($value) > $maxLength) {
            $result->addError(
                ApiMessage::VALIDATION_MAX_LENGTH,
                ['max' => $maxLength],
                [$field],
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function date(array $data, string $field, bool $required, Result $result): void
    {
        if (!array_key_exists($field, $data)) {
            if ($required) {
                $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: [$field]);
            }
            return;
        }

        $value = $data[$field];
        if ($value === null) {
            return;
        }
        if (!is_string($value)) {
            $result->addError(ApiMessage::VALIDATION_DATE_STRING, valuePath: [$field]);
            return;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $result->addError(ApiMessage::VALIDATION_DATE_INVALID, valuePath: [$field]);
        }
    }

    private static function throwIfInvalid(Result $result): void
    {
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function boolean(mixed $value): bool
    {
        return is_bool($value) ? $value : false;
    }

    private static function unicodeLength(string $value): int
    {
        preg_match_all('/./us', $value, $characters);
        return count($characters[0]);
    }
}
