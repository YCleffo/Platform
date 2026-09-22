<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final class RecoveryCodeService
{
    /** @return list<string> */
    public function generate(int $count = 10): array
    {
        if ($count < 1 || $count > 100) {
            throw new \InvalidArgumentException('Recovery code count must be between 1 and 100.');
        }
        $codes = [];
        for ($index = 0; $index < $count; ++$index) {
            $encoded = Base32::encode(random_bytes(16));
            $codes[] = implode('-', str_split($encoded, 5));
        }

        return $codes;
    }

    public function hashHex(string $code): string
    {
        return hash('sha256', $this->normalize($code));
    }

    public function normalize(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($code)));
    }
}
