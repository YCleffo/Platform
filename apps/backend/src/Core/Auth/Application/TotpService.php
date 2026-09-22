<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Time\Application\ClockInterface;
use InvalidArgumentException;

final readonly class TotpService
{
    public function __construct(private ClockInterface $clock) {}

    public function generateSecret(): string
    {
        return random_bytes(20);
    }

    public function provisioningUri(string $issuer, string $accountLabel, string $base32Secret): string
    {
        $label = rawurlencode($issuer . ':' . $accountLabel);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            rawurlencode($base32Secret),
            rawurlencode($issuer),
        );
    }

    public function matchingCounter(
        string $secret,
        string $code,
        string $algorithm = 'sha1',
        int $digits = 6,
        int $periodSeconds = 30,
    ): ?int {
        if (preg_match('/\A[0-9]{' . $digits . '}\z/', $code) !== 1) {
            return null;
        }
        if (!in_array($algorithm, ['sha1', 'sha256', 'sha512'], true)) {
            throw new InvalidArgumentException('Unsupported TOTP algorithm.');
        }
        $current = intdiv($this->clock->now()->getTimestamp(), $periodSeconds);
        foreach ([$current, $current - 1, $current + 1] as $counter) {
            if ($counter >= 0 && hash_equals($this->code($secret, $counter, $algorithm, $digits), $code)) {
                return $counter;
            }
        }

        return null;
    }

    public function code(string $secret, int $counter, string $algorithm = 'sha1', int $digits = 6): string
    {
        $high = intdiv($counter, 0x1_0000_0000);
        $low = $counter % 0x1_0000_0000;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac($algorithm, $binaryCounter, $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}
