<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use stdClass;

final readonly class EntitlementDocumentV1
{
    public function __construct(
        public int $sequence,
        public string $installationId,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public string $deploymentStatus,
        public stdClass $features,
    ) {}

    public static function parse(string $canonical): self
    {
        if (strlen($canonical) > 1048576) {
            throw new DomainException('Entitlement document exceeds size limit.');
        }
        try {
            $object = json_decode($canonical, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('Invalid entitlement JSON.');
        }
        if (!$object instanceof stdClass || array_keys(get_object_vars($object)) !== [
            'documentVersion', 'sequence', 'installationId', 'issuedAt', 'validFrom',
            'validUntil', 'deploymentStatus', 'features']) {
            throw new DomainException('Unsupported entitlement document shape.');
        }
        if ($object->documentVersion !== 1 || !is_int($object->sequence) || $object->sequence < 1
            || !is_string($object->installationId)
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $object->installationId) !== 1
            || !in_array($object->deploymentStatus, ['active', 'suspended'], true)
            || !$object->features instanceof stdClass) {
            throw new DomainException('Invalid entitlement document fields.');
        }
        $features = self::canonicalObject($object->features);
        if ($object->deploymentStatus === 'suspended' && get_object_vars($features) !== []) {
            throw new DomainException('Suspended entitlement must have no features.');
        }
        $expected = json_encode([
            'documentVersion' => 1, 'sequence' => $object->sequence,
            'installationId' => $object->installationId, 'issuedAt' => $object->issuedAt,
            'validFrom' => $object->validFrom, 'validUntil' => $object->validUntil,
            'deploymentStatus' => $object->deploymentStatus, 'features' => $features,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!hash_equals($expected, $canonical)) {
            throw new DomainException('Entitlement document is not canonical.');
        }
        return new self(
            $object->sequence,
            $object->installationId,
            self::timestamp($object->issuedAt),
            self::timestamp($object->validFrom),
            $object->validUntil === null ? null : self::timestamp($object->validUntil),
            $object->deploymentStatus,
            $features,
        );
    }

    public function assertCurrentlyValid(DateTimeImmutable $now): void
    {
        if ($this->issuedAt > $now || $this->validFrom > $now
            || ($this->validUntil !== null && $this->validUntil <= $now)
            || ($this->validUntil !== null && $this->validUntil <= $this->validFrom)) {
            throw new DomainException('Entitlement document is outside its validity period.');
        }
    }

    private static function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value) !== 1) {
            throw new DomainException('Invalid entitlement timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new DomainException('Invalid entitlement timestamp.');
        }
        return $date;
    }

    private static function canonicalObject(stdClass $value): stdClass
    {
        $values = get_object_vars($value);
        ksort($values, SORT_STRING);
        foreach ($values as $key => $item) {
            if (preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/D', $key) !== 1) {
                throw new DomainException('Invalid entitlement feature code.');
            }
            $values[$key] = self::canonicalValue($item);
        }
        /** @var stdClass $canonical */
        $canonical = (object) $values;
        return $canonical;
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $values = get_object_vars($value);
            ksort($values, SORT_STRING);
            foreach ($values as $key => $child) {
                $values[$key] = self::canonicalValue($child);
            }
            return (object) $values;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::canonicalValue($child);
            }
        }
        return $value;
    }
}
