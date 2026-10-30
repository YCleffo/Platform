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
        $this->validateArtifactToken($token, 'v1', 'verify');
    }

    public function passwordResetToken(string $resetId): string
    {
        return $this->artifactToken($resetId, 'r1', 'password-reset');
    }

    public function validatePasswordResetToken(string $token): void
    {
        $this->validateArtifactToken($token, 'r1', 'password-reset');
    }

    public function mfaChallengeToken(string $challengeId): string
    {
        return $this->artifactToken($challengeId, 'm1', 'mfa-challenge');
    }

    public function validateMfaChallengeToken(string $token): void
    {
        $this->validateArtifactToken($token, 'm1', 'mfa-challenge');
    }

    /**
     * Domain-separated invitation token. The version rotates on resend, so an older
     * link can never match the newly stored hash.
     */
    public function invitationToken(string $invitationId, int $version): string
    {
        if (!Uuid::isValid($invitationId) || $version < 1) {
            throw new \InvalidArgumentException('Invalid invitation artifact.');
        }
        $id = strtolower($invitationId);
        $mac = hash_hmac('sha256', 'invitation:' . $id . ':' . $version, $this->secret, true);

        return 'i1.' . $id . '.' . $version . '.' . self::base64Url($mac);
    }

    public function validateInvitationToken(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== 'i1' || !Uuid::isValid($parts[1])
            || !ctype_digit($parts[2]) || (int) $parts[2] < 1
            || !hash_equals($this->invitationToken($parts[1], (int) $parts[2]), $token)) {
            throw new \DomainException('Invalid invitation token.');
        }

        return strtolower($parts[1]);
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

    private function artifactToken(string $id, string $version, string $domain): string
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException('Invalid authentication artifact.');
        }
        $id = strtolower($id);
        return $version . '.' . $id . '.' . self::base64Url(hash_hmac('sha256', $domain . ':' . $id, $this->secret, true));
    }

    private function validateArtifactToken(string $token, string $version, string $domain): void
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== $version || !Uuid::isValid($parts[1])
            || !hash_equals($this->artifactToken($parts[1], $version, $domain), $token)) {
            throw new \DomainException('Invalid authentication artifact token.');
        }
    }
}
