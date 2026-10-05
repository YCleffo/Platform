<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Security;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function counterFor(string $secret, string $code, int $timestamp, int $window = 1): ?int
    {
        if (preg_match('/\A\d{6}\z/D', $code) !== 1 || $window < 0 || $window > 2) {
            return null;
        }
        $bytes = self::base32Decode($secret);
        if ($bytes === null) {
            return null;
        }
        $current = intdiv($timestamp, 30);
        for ($counter = $current - $window; $counter <= $current + $window; ++$counter) {
            if ($counter >= 0 && hash_equals(self::code($bytes, $counter), $code)) {
                return $counter;
            }
        }
        return null;
    }

    private static function code(string $key, int $counter): string
    {
        $mac = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($mac[19]) & 15;
        $value = ((ord($mac[$offset]) & 127) << 24) | (ord($mac[$offset + 1]) << 16) | (ord($mac[$offset + 2]) << 8) | ord($mac[$offset + 3]);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) { $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT); }
        $result = '';
        foreach (str_split($bits, 5) as $part) { $result .= self::ALPHABET[bindec(str_pad($part, 5, '0'))]; }
        return $result;
    }

    private static function base32Decode(string $value): ?string
    {
        $value = strtoupper(str_replace([' ', '-'], '', $value));
        if ($value === '' || preg_match('/\A[A-Z2-7]+\z/D', $value) !== 1) { return null; }
        $bits = '';
        foreach (str_split($value) as $char) { $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT); }
        $result = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $part) { $result .= chr(bindec($part)); }
        return $result;
    }
}
