<?php

declare(strict_types=1);

namespace App\Core\Shared\Domain;

use InvalidArgumentException;
use Normalizer;

use function filter_var;
use function idn_to_ascii;
use function is_string;
use function mb_check_encoding;
use function mb_strlen;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function strtolower;
use function substr_count;
use function trim;

use const FILTER_VALIDATE_EMAIL;
use const IDNA_DEFAULT;
use const INTL_IDNA_VARIANT_UTS46;

final class ValueNormalizer
{
    /**
     * Unicode-aware person name validation and normalization.
     * Supports international names: Unicode letters (\p{L}), combining marks (\p{M}),
     * and single internal separators (space, hyphen, apostrophes).
     *
     * @throws InvalidArgumentException
     */
    public static function normalizePersonName(string $value, int $maxLength = 120): string
    {
        // Reject control characters, NUL bytes, CR, LF, tabs upfront
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Person name must not contain control characters.');
        }

        $normalized = Normalizer::normalize(trim($value), Normalizer::FORM_C);
        if (!is_string($normalized) || $normalized === '') {
            throw new InvalidArgumentException('Person name cannot be blank.');
        }

        // Collapse repeated ordinary whitespace into a single space
        $collapsed = (string) preg_replace('/\s+/u', ' ', $normalized);

        if (!mb_check_encoding($collapsed, 'UTF-8')) {
            throw new InvalidArgumentException('Person name must be valid UTF-8.');
        }

        $length = mb_strlen($collapsed);
        if ($length < 1 || $length > $maxLength) {
            throw new InvalidArgumentException("Person name length must be between 1 and {$maxLength} characters.");
        }

        // Must contain at least one Unicode letter
        if (preg_match('/\p{L}/u', $collapsed) !== 1) {
            throw new InvalidArgumentException('Person name must contain at least one letter.');
        }

        // Reject digits
        if (preg_match('/\p{N}/u', $collapsed) === 1) {
            throw new InvalidArgumentException('Person name must not contain digits.');
        }

        // Allowed pattern: word of letters/marks, optionally followed by separator (space, hyphen, straight/typographic apostrophe) and another word
        if (preg_match('/^[\p{L}\p{M}]+(?:[ \-\'\x{2019}][\p{L}\p{M}]+)*$/u', $collapsed) !== 1) {
            throw new InvalidArgumentException('Person name format is invalid.');
        }

        return $collapsed;
    }

    public static function isValidPersonName(string $value, int $maxLength = 120): bool
    {
        try {
            self::normalizePersonName($value, $maxLength);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Display name validation and normalization.
     * More permissive than Person name, but rejects controls, NUL, whitespace-only, and invalid UTF-8.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeDisplayName(?string $value, int $maxLength = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Display name must not contain control characters.');
        }

        $normalized = Normalizer::normalize(trim($value), Normalizer::FORM_C);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        $collapsed = (string) preg_replace('/\s+/u', ' ', $normalized);

        if (!mb_check_encoding($collapsed, 'UTF-8')) {
            throw new InvalidArgumentException('Display name must be valid UTF-8.');
        }

        if (mb_strlen($collapsed) > $maxLength) {
            throw new InvalidArgumentException("Display name must not exceed {$maxLength} characters.");
        }

        return $collapsed;
    }

    public static function isValidDisplayName(?string $value, int $maxLength = 255): bool
    {
        try {
            self::normalizeDisplayName($value, $maxLength);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Label validation and normalization.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeLabel(?string $value, int $maxLength = 80): ?string
    {
        return self::normalizeOptionalText($value, $maxLength, 'Label');
    }

    public static function isValidLabel(?string $value, int $maxLength = 80): bool
    {
        try {
            self::normalizeLabel($value, $maxLength);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Lightweight normalization for nullable user-controlled text.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeText(?string $value, int $maxLength = 1_000): ?string
    {
        return self::normalizeOptionalText($value, $maxLength, 'Text');
    }

    public static function isValidText(?string $value, int $maxLength = 1_000): bool
    {
        try {
            self::normalizeText($value, $maxLength);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Phone validation and normalization.
     * Normalizes UX formatted input (+7 (999) 123-45-67) to canonical E.164 (+79991234567).
     * Rejects numbers without leading '+', invalid length, and non-digit characters.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizePhone(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Phone number must not contain control characters.');
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !str_starts_with($trimmed, '+')) {
            throw new InvalidArgumentException('Phone number must start with "+" and country code (canonical E.164).');
        }

        // Reject if letters or invalid characters are present
        if (preg_match('/[a-zA-Z\p{L}]/u', $trimmed) === 1) {
            throw new InvalidArgumentException('Phone number must not contain alphabetic characters.');
        }

        // Strip allowable UX separators: spaces, hyphens, brackets, dots
        $cleaned = (string) preg_replace('/[\s\(\)\-\.]+/u', '', $trimmed);

        // Canonical E.164: + followed by 1-9, then 7-14 digits (total 8 to 15 digits)
        if (preg_match('/\A\+[1-9][0-9]{7,14}\z/', $cleaned) !== 1) {
            throw new InvalidArgumentException('Phone number must be a valid canonical E.164 number (8 to 15 digits).');
        }

        return $cleaned;
    }

    public static function isValidPhone(string $value): bool
    {
        try {
            self::normalizePhone($value);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Email validation and normalization.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeEmail(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Email address must not contain control characters.');
        }

        $trimmed = trim($value);
        if (substr_count($trimmed, '@') !== 1) {
            throw new InvalidArgumentException('Email address must contain exactly one "@".');
        }

        $parts = explode('@', $trimmed, 2);
        $local = $parts[0];
        $domain = $parts[1] ?? '';

        if ($local === '' || $domain === '' || preg_match('/[\x00-\x20\x7F]/u', $local) === 1) {
            throw new InvalidArgumentException('Email local part is invalid.');
        }

        $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($asciiDomain === false || filter_var('x@' . $asciiDomain, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email domain is invalid.');
        }

        $normalized = mb_strtolower($local) . '@' . strtolower($asciiDomain);
        if (mb_strlen($normalized) > 320) {
            throw new InvalidArgumentException('Email address must not exceed 320 characters.');
        }

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email address format is invalid.');
        }

        return $normalized;
    }

    public static function isValidEmail(string $value): bool
    {
        try {
            self::normalizeEmail($value);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Username validation and normalization.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeUsername(string $value, int $minLength = 3, int $maxLength = 60): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Username must not contain control characters.');
        }

        if ($value === '') {
            throw new InvalidArgumentException('Username cannot be blank.');
        }

        if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Username must not contain whitespace or control characters.');
        }

        $length = strlen($value);
        if ($length < $minLength || $length > $maxLength) {
            throw new InvalidArgumentException("Username length must be between {$minLength} and {$maxLength} characters.");
        }

        if (preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/', $value) !== 1) {
            throw new InvalidArgumentException(
                'Username must be strictly lowercase ASCII alphanumeric with single internal [._-].',
            );
        }

        return $value;
    }

    public static function isValidUsername(string $value, int $minLength = 3, int $maxLength = 60): bool
    {
        try {
            self::normalizeUsername($value, $minLength, $maxLength);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Machine code validation and normalization (module codes, role codes, permissions, setting keys).
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeMachineCode(string $value, int $maxLength = 120): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Machine code must not contain control characters.');
        }

        if ($value === '') {
            throw new InvalidArgumentException('Machine code cannot be blank.');
        }

        $length = strlen($value);
        if ($length < 1 || $length > $maxLength) {
            throw new InvalidArgumentException("Machine code length must be between 1 and {$maxLength} characters.");
        }

        if (preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/', $value) !== 1) {
            throw new InvalidArgumentException('Machine code must be strictly lowercase alphanumeric with single internal [._-].');
        }

        return $value;
    }

    public static function isValidMachineCode(string $value, int $maxLength = 120): bool
    {
        try {
            self::normalizeMachineCode($value, $maxLength);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @throws InvalidArgumentException */
    private static function normalizeOptionalText(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("{$field} must not contain control characters.");
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (!is_string($normalized) || !mb_check_encoding($normalized, 'UTF-8')) {
            throw new InvalidArgumentException("{$field} must be valid UTF-8.");
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $normalized));
        if ($collapsed === '') {
            throw new InvalidArgumentException("{$field} cannot be blank.");
        }

        if (mb_strlen($collapsed) > $maxLength) {
            throw new InvalidArgumentException("{$field} must not exceed {$maxLength} characters.");
        }

        return $collapsed;
    }
}
