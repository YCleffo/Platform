<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Core\Auth\Application\LoginCommand;
use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\Validator\Result;

final class AuthInput
{
    public static function login(mixed $body): LoginCommand
    {
        $data = self::object($body);
        $type = $data['identifierType'] ?? 'email';
        $identifier = $data['identifier'] ?? null;
        $password = $data['password'] ?? null;
        $result = new Result();

        if (!is_string($type) || !in_array($type, ['username', 'email', 'phone'], true)) {
            $result->addError(ApiMessage::VALIDATION_IDENTIFIER_TYPE, valuePath: ['identifierType']);
        }
        if (!is_string($identifier) || trim($identifier) === '') {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['identifier']);
        }
        if (!is_string($password) || $password === '') {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['password']);
        }
        foreach (array_keys($data) as $field) {
            if (!in_array($field, ['identifierType', 'identifier', 'password'], true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [$field]);
            }
        }

        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return new LoginCommand((string) $type, (string) $identifier, (string) $password);
    }

    public static function refreshToken(mixed $body): string
    {
        $data = self::object($body);
        $token = $data['refreshToken'] ?? null;
        if (!is_string($token) || $token === '' || array_diff(array_keys($data), ['refreshToken']) !== []) {
            $result = new Result();
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['refreshToken']);
            throw new InputValidationException($result);
        }

        return $token;
    }

    public static function email(mixed $body): string
    {
        $data = self::object($body);
        $email = $data['email'] ?? null;
        if (!is_string($email) || trim($email) === '' || array_diff(array_keys($data), ['email']) !== []) {
            $result = new Result();
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['email']);
            throw new InputValidationException($result);
        }

        return $email;
    }

    /** @return array{challengeToken: string, totpCode: ?string, recoveryCode: ?string} */
    public static function mfaVerification(mixed $body): array
    {
        $data = self::object($body);
        $challenge = $data['challengeToken'] ?? null;
        $totp = $data['totpCode'] ?? null;
        $recovery = $data['recoveryCode'] ?? null;
        $result = new Result();
        if (!is_string($challenge) || $challenge === '') {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['challengeToken']);
        }
        if (($totp === null) === ($recovery === null)
            || ($totp !== null && (!is_string($totp) || preg_match('/\A[0-9]{6}\z/', $totp) !== 1))
            || ($recovery !== null && (!is_string($recovery) || trim($recovery) === ''))
        ) {
            $result->addError(ApiMessage::VALIDATION_EXACTLY_ONE_MFA_CODE);
        }
        self::unknown($data, ['challengeToken', 'totpCode', 'recoveryCode'], $result);
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return [
            'challengeToken' => (string) $challenge,
            'totpCode' => is_string($totp) ? $totp : null,
            'recoveryCode' => is_string($recovery) ? $recovery : null,
        ];
    }

    /** @return array{label: ?string} */
    public static function totpSetup(mixed $body): array
    {
        $data = self::object($body);
        $label = $data['label'] ?? null;
        $result = new Result();
        if ($label !== null && (!is_string($label) || mb_strlen(trim($label)) > 160)) {
            $result->addError(ApiMessage::VALIDATION_LABEL, ['max' => 160], ['label']);
        }
        self::unknown($data, ['label'], $result);
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return ['label' => is_string($label) && trim($label) !== '' ? trim($label) : null];
    }

    /** @return array{setupId: string, code: string} */
    public static function totpConfirm(mixed $body): array
    {
        $data = self::object($body);
        $setupId = $data['setupId'] ?? null;
        $code = $data['code'] ?? null;
        $result = new Result();
        if (!is_string($setupId) || $setupId === '') {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['setupId']);
        }
        if (!is_string($code) || preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            $result->addError(ApiMessage::VALIDATION_CODE, valuePath: ['code']);
        }
        self::unknown($data, ['setupId', 'code'], $result);
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return ['setupId' => (string) $setupId, 'code' => (string) $code];
    }

    public static function totpCode(mixed $body): string
    {
        $data = self::object($body);
        $code = $data['code'] ?? null;
        $result = new Result();
        if (!is_string($code) || preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            $result->addError(ApiMessage::VALIDATION_CODE, valuePath: ['code']);
        }
        self::unknown($data, ['code'], $result);
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
        return (string) $code;
    }

    /** @return array{password: ?string, code: string} */
    public static function totpDisable(mixed $body): array
    {
        $data = self::object($body);
        $password = $data['password'] ?? null;
        $code = $data['code'] ?? null;
        $result = new Result();
        if ($password !== null && !is_string($password)) {
            $result->addError(ApiMessage::VALIDATION_STRING_OR_NULL, valuePath: ['password']);
        }
        if (!is_string($code) || preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            $result->addError(ApiMessage::VALIDATION_CODE, valuePath: ['code']);
        }
        self::unknown($data, ['password', 'code'], $result);
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
        return ['password' => is_string($password) ? $password : null, 'code' => (string) $code];
    }

    /** @return array{token: string, password: ?string} */
    public static function mfaRecoveryConfirm(mixed $body): array
    {
        $data = self::object($body);
        $token = $data['token'] ?? null;
        $password = $data['password'] ?? null;
        $result = new Result();
        if (!is_string($token) || $token === '') {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['token']);
        }
        if ($password !== null && !is_string($password)) {
            $result->addError(ApiMessage::VALIDATION_STRING_OR_NULL, valuePath: ['password']);
        }
        self::unknown($data, ['token', 'password'], $result);
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
        return ['token' => (string) $token, 'password' => is_string($password) ? $password : null];
    }

    public static function recoveryToken(mixed $body): string
    {
        $data = self::object($body);
        $token = $data['recoveryToken'] ?? null;
        $result = new Result();
        if (!is_string($token) || $token === '' || strlen($token) > 512) {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['recoveryToken']);
        }
        self::unknown($data, ['recoveryToken'], $result);
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
        return (string) $token;
    }

    /** @return array{state: string, code: string} */
    public static function externalCallback(mixed $input): array
    {
        $data = self::object($input);
        $state = $data['state'] ?? null;
        $code = $data['code'] ?? null;
        $result = new Result();
        if (!is_string($state) || $state === '' || strlen($state) > 512) {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['state']);
        }
        if (!is_string($code) || $code === '' || strlen($code) > 4096) {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['code']);
        }
        self::unknown($data, ['state', 'code'], $result);
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
        return ['state' => (string) $state, 'code' => (string) $code];
    }

    /** @return array{token: string, password: string} */
    public static function tokenAndPassword(mixed $body): array
    {
        $data = self::object($body);
        $token = $data['token'] ?? null;
        $password = $data['password'] ?? null;
        $result = new Result();
        if (!is_string($token) || $token === '') {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['token']);
        }
        if (!is_string($password) || mb_strlen($password) < 12) {
            $result->addError(
                ApiMessage::VALIDATION_PASSWORD_MIN_LENGTH,
                ['min' => 12],
                ['password'],
            );
        }
        foreach (array_keys($data) as $field) {
            if (!in_array($field, ['token', 'password'], true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [$field]);
            }
        }
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return ['token' => (string) $token, 'password' => (string) $password];
    }

    /** @return array<string, mixed> */
    public static function preferences(mixed $body): array
    {
        $data = self::object($body);
        $result = new Result();
        foreach (array_keys($data) as $field) {
            if (!in_array($field, ['locale', 'timezone'], true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [$field]);
            }
        }
        foreach (['locale', 'timezone'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && !is_string($data[$field])) {
                $result->addError(ApiMessage::VALIDATION_STRING_OR_NULL, valuePath: [$field]);
            }
        }
        if ($data === []) {
            $result->addError(ApiMessage::VALIDATION_PREFERENCE_REQUIRED);
        }
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return $data;
    }

    /** @return array{responseJson: string, label: ?string} */
    public static function passkeyRegister(mixed $body): array
    {
        $data = self::object($body);
        $result = new Result();

        $label = null;
        if (array_key_exists('label', $data)) {
            if ($data['label'] !== null && !is_string($data['label'])) {
                $result->addError(ApiMessage::VALIDATION_STRING_OR_NULL, valuePath: ['label']);
            } elseif (is_string($data['label'])) {
                $trimmed = trim($data['label']);
                if (mb_strlen($trimmed, 'UTF-8') > 160) {
                    $result->addError(ApiMessage::VALIDATION_MAX_LENGTH, ['max' => 160], valuePath: ['label']);
                }
                $label = $trimmed === '' ? null : $trimmed;
            }
        }

        /** @var array<string, mixed> $credentialData */
        if (isset($data['responseJson']) && is_string($data['responseJson'])) {
            $decoded = json_decode($data['responseJson'], true);
            $credentialData = is_array($decoded) ? $decoded : [];
        } elseif (isset($data['credential']) && is_array($data['credential'])) {
            $credentialData = $data['credential'];
        } else {
            $credentialData = $data;
        }
        unset($credentialData['label']);

        if (!isset($credentialData['response']) || !is_array($credentialData['response'])) {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['response']);
        }

        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return [
            'responseJson' => json_encode($credentialData, JSON_THROW_ON_ERROR),
            'label' => $label,
        ];
    }

    public static function passkeyAuthenticate(mixed $body): string
    {
        $data = self::object($body);
        $result = new Result();

        /** @var array<string, mixed> $credentialData */
        if (isset($data['responseJson']) && is_string($data['responseJson'])) {
            $decoded = json_decode($data['responseJson'], true);
            $credentialData = is_array($decoded) ? $decoded : [];
        } elseif (isset($data['credential']) && is_array($data['credential'])) {
            $credentialData = $data['credential'];
        } else {
            $credentialData = $data;
        }

        if (!isset($credentialData['response']) || !is_array($credentialData['response'])) {
            $result->addError(ApiMessage::VALIDATION_REQUIRED, valuePath: ['response']);
        }

        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return json_encode($credentialData, JSON_THROW_ON_ERROR);
    }

    public static function passkeyRename(mixed $body): ?string
    {
        $data = self::object($body);
        $result = new Result();
        self::unknown($data, ['label'], $result);

        $label = null;
        if (array_key_exists('label', $data)) {
            if ($data['label'] !== null && !is_string($data['label'])) {
                $result->addError(ApiMessage::VALIDATION_STRING_OR_NULL, valuePath: ['label']);
            } elseif (is_string($data['label'])) {
                $trimmed = trim($data['label']);
                if (mb_strlen($trimmed, 'UTF-8') > 160) {
                    $result->addError(ApiMessage::VALIDATION_MAX_LENGTH, ['max' => 160], valuePath: ['label']);
                }
                $label = $trimmed === '' ? null : $trimmed;
            }
        }

        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return $label;
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
    private static function unknown(array $data, array $allowed, Result $result): void
    {
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $allowed, true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [$field]);
            }
        }
    }
}
