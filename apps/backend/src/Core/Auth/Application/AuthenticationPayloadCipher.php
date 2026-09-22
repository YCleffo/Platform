<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface AuthenticationPayloadCipher
{
    public function encrypt(string $plaintext): EncryptedAuthenticationPayload;

    public function decrypt(string $ciphertextHex, int $keyVersion): string;
}
