<?php

declare(strict_types=1);

namespace App\Core\Organization\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\Uuid;
use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\Validator\Result;

final class OrganizationInput
{
    /** @return array{code: string, name: string} */
    public static function organization(mixed $body): array
    {
        $data = self::body($body, ['code', 'name']);
        $result = new Result();
        $code = $data['code'] ?? null;
        $name = $data['name'] ?? null;
        if (!is_string($code) || preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $code) !== 1 || strlen($code) > 120) {
            $result->addError(ApiMessage::VALIDATION_STRING, valuePath: ['code']);
        }
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            $result->addError(ApiMessage::VALIDATION_STRING, valuePath: ['name']);
        }
        self::check($result);
        return ['code' => $code, 'name' => trim($name)];
    }

    public static function member(mixed $body): string
    {
        $data = self::body($body, ['personId']);
        $result = new Result();
        if (!is_string($data['personId'] ?? null) || !Uuid::isValid($data['personId'])) {
            $result->addError(ApiMessage::VALIDATION_STRING, valuePath: ['personId']);
        }
        self::check($result);
        return $data['personId'];
    }

    /** @param list<string> $allowed
     * @return array<string, mixed>
     */
    private static function body(mixed $body, array $allowed): array
    {
        $result = new Result();
        if (!is_array($body) || array_is_list($body)) {
            $result->addError(ApiMessage::VALIDATION_BODY_OBJECT_REQUIRED);
            throw new InputValidationException($result);
        }
        foreach (array_keys($body) as $key) {
            if (!in_array($key, $allowed, true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [(string) $key]);
            }
        }
        self::check($result);
        return $body;
    }

    private static function check(Result $result): void
    {
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
    }
}
