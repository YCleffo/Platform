<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Security;

use Symfony\Component\Uid\Uuid;

final readonly class TokenCodec
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('CONTROL_TOKEN_SECRET must contain at least 32 characters.');
        }
    }

    public function randomToken(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public function verificationToken(string $verificationId): string
    {
        if (!Uuid::isValid($verificationId)) {
            throw new \InvalidArgumentException('Invalid verification artifact.');
        }
        $mac = hash_hmac('sha256', 'verify:' . strtolower($verificationId), $this->secret, true);

        return 'v1.' . strtolower($verificationId) . '.' . self::base64Url($mac);
    }

    public function validateVerificationToken(string $token): void
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'v1' || !Uuid::isValid($parts[1])) {
            throw new \DomainException('Invalid verification token.');
        }
        if (!hash_equals($this->verificationToken($parts[1]), $token)) {
            throw new \DomainException('Invalid verification token.');
        }
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function pseudonym(string $value): string
    {
        return hash_hmac('sha256', $value, $this->secret);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
