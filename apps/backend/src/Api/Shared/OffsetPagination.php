<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\Validator\Result;

final readonly class OffsetPagination
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 100;

    private function __construct(
        public int $limit,
        public int $offset,
    ) {}

    /**
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $result = new Result();
        $limit = self::parseInteger($query['limit'] ?? self::DEFAULT_LIMIT, 'limit', $result);
        $offset = self::parseInteger($query['offset'] ?? 0, 'offset', $result);

        if ($limit !== null && ($limit < 1 || $limit > self::MAX_LIMIT)) {
            $result->addError(
                ApiMessage::VALIDATION_LIMIT_RANGE,
                ['min' => 1, 'max' => self::MAX_LIMIT],
                ['limit'],
            );
        }
        if ($offset !== null && $offset < 0) {
            $result->addError(ApiMessage::VALIDATION_OFFSET_MIN, valuePath: ['offset']);
        }

        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }

        return new self($limit ?? self::DEFAULT_LIMIT, $offset ?? 0);
    }

    private static function parseInteger(mixed $value, string $field, Result $result): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            return (int) $value;
        }

        $result->addError(ApiMessage::VALIDATION_INTEGER, valuePath: [$field]);
        return null;
    }
}
