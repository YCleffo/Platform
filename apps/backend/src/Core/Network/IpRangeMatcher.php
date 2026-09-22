<?php

declare(strict_types=1);

namespace App\Core\Network;

use InvalidArgumentException;

final readonly class IpRangeMatcher
{
    public static function matches(string $address, string $range): bool
    {
        $packedAddress = @inet_pton($address);
        if ($packedAddress === false) {
            return false;
        }

        [$network, $prefix] = self::parse($range);
        $packedNetwork = inet_pton($network);
        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedAddress)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($packedAddress[$wholeBytes]) & $mask)
            === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    /** @return array{string, int} */
    public static function parse(string $range): array
    {
        $parts = explode('/', trim($range), 2);
        $address = $parts[0];
        $prefixValue = $parts[1] ?? null;
        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new InvalidArgumentException(sprintf('IP range "%s" is not an IP or CIDR.', $range));
        }

        $bits = strlen($packed) * 8;
        $prefix = $prefixValue === null ? $bits : filter_var($prefixValue, FILTER_VALIDATE_INT);
        if (!is_int($prefix) || $prefix < 0 || $prefix > $bits) {
            throw new InvalidArgumentException(sprintf('CIDR "%s" is invalid.', $range));
        }

        $normalized = inet_ntop($packed);
        if ($normalized === false) {
            throw new InvalidArgumentException('IP range cannot be normalized.');
        }

        return [$normalized, $prefix];
    }
}
