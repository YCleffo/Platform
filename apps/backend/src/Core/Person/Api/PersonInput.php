<?php

declare(strict_types=1);

namespace App\Core\Person\Api;

use App\Api\Shared\ApiMessage;
use App\Core\Person\Application\CreatePersonCommand;
use App\Core\Person\Application\CreatePersonContactCommand;
use App\Core\Person\Application\UpdatePersonCommand;
use App\Core\Shared\Domain\ValueNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\Validator\Result;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function trim;

final class PersonInput
{
    public static function createPerson(mixed $body): CreatePersonCommand
    {
        $data = self::body($body);
        $result = new Result();
        self::rejectUnknown($data, ['givenName', 'familyName', 'middleName', 'displayName', 'birthDate'], $result);

        $givenName = self::personName($data, 'givenName', 120, false, true, $result);
        $familyName = self::personName($data, 'familyName', 120, false, true, $result);
        $middleName = self::personName($data, 'middleName', 120, true, false, $result);
        $displayName = self::displayName($data, 'displayName', 255, true, false, $result);
        $birthDate = self::date($data, 'birthDate', false, $result);

        self::throwIfInvalid($result);

        return new CreatePersonCommand(
            givenName: (string) $givenName,
            familyName: (string) $familyName,
            middleName: $middleName,
            displayName: $displayName,
            birthDate: $birthDate,
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

        $givenName = self::personName($data, 'givenName', 120, false, false, $result);
        $familyName = self::personName($data, 'familyName', 120, false, false, $result);
        $middleName = self::personName($data, 'middleName', 120, true, false, $result);
        $displayName = self::displayName($data, 'displayName', 255, true, false, $result);
        $birthDate = self::date($data, 'birthDate', false, $result);

        self::throwIfInvalid($result);

        return new UpdatePersonCommand(
            id: $id,
            givenNameProvided: array_key_exists('givenName', $data),
            givenName: $givenName,
            familyNameProvided: array_key_exists('familyName', $data),
            familyName: $familyName,
            middleNameProvided: array_key_exists('middleName', $data),
            middleName: $middleName,
            displayNameProvided: array_key_exists('displayName', $data),
            displayName: $displayName,
            birthDateProvided: array_key_exists('birthDate', $data),
            birthDate: $birthDate,
        );
    }

    public static function createContact(string $personId, mixed $body): CreatePersonContactCommand
    {
        $data = self::body($body);
        $result = new Result();
        self::rejectUnknown($data, ['contactType', 'value', 'label', 'isPrimary'], $result);

        self::string($data, 'contactType', 20, false, true, $result);
        self::string($data, 'value', 320, false, true, $result);
        $label = self::label($data, 'label', 80, true, false, $result);

        $contactType = isset($data['contactType']) && is_string($data['contactType'])
            ? $data['contactType']
            : null;

        if ($contactType !== null && !in_array($contactType, ['email', 'phone'], true)) {
            $result->addError(ApiMessage::VALIDATION_CONTACT_TYPE, valuePath: ['contactType']);
        }

        if (array_key_exists('isPrimary', $data) && !is_bool($data['isPrimary'])) {
            $result->addError(ApiMessage::VALIDATION_BOOLEAN, valuePath: ['isPrimary']);
        }

        $value = isset($data['value']) && is_string($data['value']) ? $data['value'] : '';
        if ($contactType === 'email' && $value !== '') {
            if (!ValueNormalizer::isValidEmail($value)) {
                $result->addError(ApiMessage::VALIDATION_EMAIL_INVALID, valuePath: ['value']);
            }
        } elseif ($contactType === 'phone' && $value !== '') {
            if (!ValueNormalizer::isValidPhone($value)) {
                $result->addError(ApiMessage::VALIDATION_PHONE_INVALID, valuePath: ['value']);
            }
        }

        self::throwIfInvalid($result);

        return new CreatePersonContactCommand(
            personId: $personId,
            contactType: (string) $contactType,
            value: $value,
            label: $label,
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
    private static function personName(
        array $data,
        string $field,
        int $maxLength,
        bool $nullable,
        bool $required,
        Result $result,
    ): ?string {
        if (!array_key_exists($field, $data)) {
            if ($required) {
                $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: [$field]);
            }
            return null;
        }

        $value = $data[$field];
        if ($value === null) {
            if (!$nullable) {
                $result->addError(ApiMessage::VALIDATION_STRING, valuePath: [$field]);
            }
            return null;
        }

        if (!is_string($value)) {
            $result->addError(ApiMessage::VALIDATION_STRING, valuePath: [$field]);
            return null;
        }

        if (trim($value) === '') {
            $result->addError(ApiMessage::VALIDATION_NOT_BLANK, valuePath: [$field]);
            return null;
        }

        if (self::unicodeLength($value) > $maxLength) {
            $result->addError(
                ApiMessage::VALIDATION_MAX_LENGTH,
                ['max' => $maxLength],
                [$field],
            );
            return null;
        }

        try {
            return ValueNormalizer::normalizePersonName($value, $maxLength);
        } catch (InvalidArgumentException) {
            $result->addError(ApiMessage::VALIDATION_PERSON_NAME_INVALID, valuePath: [$field]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function displayName(
        array $data,
        string $field,
        int $maxLength,
        bool $nullable,
        bool $required,
        Result $result,
    ): ?string {
        if (!array_key_exists($field, $data)) {
            if ($required) {
                $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: [$field]);
            }
            return null;
        }

        $value = $data[$field];
        if ($value === null) {
            if (!$nullable) {
                $result->addError(ApiMessage::VALIDATION_STRING, valuePath: [$field]);
            }
            return null;
        }

        if (!is_string($value)) {
            $result->addError(ApiMessage::VALIDATION_STRING, valuePath: [$field]);
            return null;
        }

        if (trim($value) === '') {
            $result->addError(ApiMessage::VALIDATION_NOT_BLANK, valuePath: [$field]);
            return null;
        }

        if (self::unicodeLength($value) > $maxLength) {
            $result->addError(
                ApiMessage::VALIDATION_MAX_LENGTH,
                ['max' => $maxLength],
                [$field],
            );
            return null;
        }

        try {
            return ValueNormalizer::normalizeDisplayName($value, $maxLength);
        } catch (InvalidArgumentException) {
            $result->addError(ApiMessage::VALIDATION_TEXT_INVALID, valuePath: [$field]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function label(
        array $data,
        string $field,
        int $maxLength,
        bool $nullable,
        bool $required,
        Result $result,
    ): ?string {
        if (!array_key_exists($field, $data)) {
            if ($required) {
                $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: [$field]);
            }
            return null;
        }

        $value = $data[$field];
        if ($value === null) {
            if (!$nullable) {
                $result->addError(ApiMessage::VALIDATION_STRING, valuePath: [$field]);
            }
            return null;
        }

        if (!is_string($value)) {
            $result->addError(ApiMessage::VALIDATION_STRING, valuePath: [$field]);
            return null;
        }

        if (trim($value) === '') {
            $result->addError(ApiMessage::VALIDATION_NOT_BLANK, valuePath: [$field]);
            return null;
        }

        if (self::unicodeLength($value) > $maxLength) {
            $result->addError(
                ApiMessage::VALIDATION_MAX_LENGTH,
                ['max' => $maxLength],
                [$field],
            );
            return null;
        }

        try {
            return ValueNormalizer::normalizeLabel($value, $maxLength);
        } catch (InvalidArgumentException) {
            $result->addError(ApiMessage::VALIDATION_TEXT_INVALID, valuePath: [$field]);
            return null;
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
    private static function date(array $data, string $field, bool $required, Result $result): ?string
    {
        if (!array_key_exists($field, $data)) {
            if ($required) {
                $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: [$field]);
            }
            return null;
        }

        $value = $data[$field];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            $result->addError(ApiMessage::VALIDATION_DATE_STRING, valuePath: [$field]);
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $result->addError(ApiMessage::VALIDATION_DATE_INVALID, valuePath: [$field]);
            return null;
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        if ($date > $today) {
            $result->addError(ApiMessage::VALIDATION_BIRTH_DATE_FUTURE, valuePath: [$field]);
            return null;
        }

        $minDate = new DateTimeImmutable('1900-01-01', new DateTimeZone('UTC'));
        if ($date < $minDate) {
            $result->addError(ApiMessage::VALIDATION_DATE_INVALID, valuePath: [$field]);
            return null;
        }

        return $value;
    }

    private static function throwIfInvalid(Result $result): void
    {
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
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
