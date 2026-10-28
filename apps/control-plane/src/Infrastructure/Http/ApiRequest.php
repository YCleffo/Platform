<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

final class ApiRequest
{
    /** @return array<string, mixed> */
    public static function json(Request $request): array
    {
        $data = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('A JSON object is required.');
        }

        return $data;
    }

    public static function emptyObject(Request $request): void
    {
        $data = json_decode($request->getContent(), false, 64, JSON_THROW_ON_ERROR);
        if (!$data instanceof \stdClass || get_object_vars($data) !== []) {
            throw new \InvalidArgumentException('An empty JSON object is required.');
        }
    }

    /** Preserve the JSON object/array distinction for an optional nested object.
     * @return array<string, mixed>
     */
    public static function optionalObject(Request $request, string $key): array
    {
        $body = json_decode($request->getContent(), false, 64, JSON_THROW_ON_ERROR);
        if (!$body instanceof \stdClass) {
            throw new \InvalidArgumentException('A JSON object is required.');
        }
        if (!property_exists($body, $key)) {
            return [];
        }
        if (!$body->{$key} instanceof \stdClass) {
            throw new \InvalidArgumentException('An object field is required.');
        }
        return get_object_vars($body->{$key});
    }

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new \InvalidArgumentException('Required string field is missing.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    public static function nullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_string($data[$key])) {
            throw new \InvalidArgumentException('Optional field must be a string or null.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    public static function integer(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new \InvalidArgumentException('Required integer field is missing.');
        }

        return $data[$key];
    }

    public static function correlationId(Request $request): string
    {
        $id = $request->attributes->get(CorrelationIdSubscriber::ATTRIBUTE);
        if (!is_string($id)) {
            throw new \LogicException('Correlation ID is unavailable.');
        }

        return $id;
    }

    public static function bearer(Request $request): string
    {
        $header = $request->headers->get('Authorization', '');
        if (!is_string($header) || preg_match('/\ABearer ([!-~]+)\z/D', $header, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }
}
