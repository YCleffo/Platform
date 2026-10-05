<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Security;

final readonly class TotpSecretCipher
{
    private string $key;

    public function __construct(string $encodedKey)
    {
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException('CONTROL_TOTP_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $this->key);
    }

    public function decrypt(string $ciphertext): string
    {
        if (str_starts_with($ciphertext, '\\x') && ctype_xdigit(substr($ciphertext, 2))) {
            $decoded = hex2bin(substr($ciphertext, 2));
            if ($decoded === false) { throw new \DomainException('Stored TOTP factor cannot be decrypted.'); }
            $ciphertext = $decoded;
        }
        $length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($ciphertext) <= $length) {
            throw new \DomainException('Stored TOTP factor cannot be decrypted.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($ciphertext, $length), '', substr($ciphertext, 0, $length), $this->key);
        if ($plaintext === false) {
            throw new \DomainException('Stored TOTP factor cannot be decrypted.');
        }
        return $plaintext;
    }
}
