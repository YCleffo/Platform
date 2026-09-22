<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Core\Auth\Application\AuthenticationPayloadCipher;
use App\Core\Auth\Application\EncryptedAuthenticationPayload;
use RuntimeException;

final readonly class SodiumAuthenticationPayloadCipher implements AuthenticationPayloadCipher
{
    private const int KEY_VERSION = 1;
    private const string AAD = 'platform:auth:transaction:v1';
    private string $key;

    public function __construct(?string $base64Key, bool $production)
    {
        if ($base64Key === null) {
            if ($production) {
                throw new RuntimeException('AUTH_TOTP_ENCRYPTION_KEY is required in production.');
            }
            $this->key = hash('sha256', 'platform-local-development-auth-transaction-key', true);
            return;
        }
        $decoded = base64_decode($base64Key, true);
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('AUTH_TOTP_ENCRYPTION_KEY must be Base64-encoded 32 bytes.');
        }
        $this->key = $decoded;
    }

    public function encrypt(string $plaintext): EncryptedAuthenticationPayload
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::AAD,
            $nonce,
            $this->key,
        );
        return new EncryptedAuthenticationPayload(bin2hex($nonce . $ciphertext), self::KEY_VERSION);
    }

    public function decrypt(string $ciphertextHex, int $keyVersion): string
    {
        if ($keyVersion !== self::KEY_VERSION) {
            throw new RuntimeException('Unsupported authentication payload key version.');
        }
        $payload = hex2bin($ciphertextHex);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('Encrypted authentication payload is malformed.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            self::AAD,
            $nonce,
            $this->key,
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('Encrypted authentication payload failed authentication.');
        }
        return $plaintext;
    }
}
