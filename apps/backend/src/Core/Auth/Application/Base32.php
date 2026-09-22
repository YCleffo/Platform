<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use InvalidArgumentException;

final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        $buffer = 0;
        $bits = 0;
        $result = '';
        /** @var array<int, int> $unpacked */
        $unpacked = unpack('C*', $bytes) ?: [];
        foreach ($unpacked as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $result .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $result;
    }

    public static function decode(string $encoded): string
    {
        $normalized = strtoupper(str_replace([' ', '-', '='], '', $encoded));
        if ($normalized === '') {
            throw new InvalidArgumentException('Base32 value must not be blank.');
        }
        $buffer = 0;
        $bits = 0;
        $result = '';
        foreach (str_split($normalized) as $character) {
            $value = strpos(self::ALPHABET, $character);
            if ($value === false) {
                throw new InvalidArgumentException('Base32 value contains an invalid character.');
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $result .= chr(($buffer >> $bits) & 255);
            }
        }

        return $result;
    }
}
