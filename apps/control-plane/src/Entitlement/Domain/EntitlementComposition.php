<?php

declare(strict_types=1);

namespace ControlPlane\Entitlement\Domain;

final class EntitlementComposition
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function fromRows(array $rows): array
    {
        $features = [];
        $canonical = [];
        foreach ($rows as $row) {
            if (!isset($row['feature_code'], $row['feature_value']) || !is_string($row['feature_code']) || !is_string($row['feature_value'])) {
                throw new \LogicException('Entitlement row is invalid.');
            }
            $code = $row['feature_code'];
            $value = json_decode($row['feature_value'], false, 512, JSON_THROW_ON_ERROR);
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (isset($canonical[$code]) && $canonical[$code] !== $encoded) {
                throw new \DomainException('Ambiguous entitlement composition.');
            }
            $canonical[$code] = $encoded;
            $features[$code] = $value;
        }

        ksort($features);

        return $features;
    }
}
