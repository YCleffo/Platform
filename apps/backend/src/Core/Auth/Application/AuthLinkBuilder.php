<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class AuthLinkBuilder
{
    public function __construct(private string $publicBaseUrl) {}

    public function activation(string $token): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/activate?token=' . rawurlencode($token);
    }

    public function passwordReset(string $token): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/password-reset?token=' . rawurlencode($token);
    }

    public function mfaRecovery(string $token): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/mfa-recovery?token=' . rawurlencode($token);
    }
}
