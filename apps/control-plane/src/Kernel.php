<?php

declare(strict_types=1);

namespace ControlPlane;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        if ($this->getEnvironment() === 'prod' && strlen((string) getenv('CONTROL_APP_SECRET')) < 32) {
            throw new \RuntimeException('Control Plane production secret is not configured.');
        }
        if ($this->getEnvironment() === 'prod' && strlen((string) getenv('CONTROL_TOKEN_SECRET')) < 32) {
            throw new \RuntimeException('Control Plane token secret is not configured.');
        }
        if ($this->getEnvironment() === 'prod') {
            $totpKey = base64_decode((string) getenv('CONTROL_TOTP_ENCRYPTION_KEY'), true);
            if ($totpKey === false || strlen($totpKey) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new \RuntimeException('Control Plane TOTP encryption key is not configured.');
            }
        }
        if ($this->getEnvironment() === 'prod') {
            $returnUrl = (string) getenv('CONTROL_YOOKASSA_RETURN_URL');
            $parts = parse_url($returnUrl);
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])) {
                throw new \RuntimeException('The production YooKassa return URL must be an absolute HTTPS URL.');
            }
        }

        parent::boot();
    }
}
