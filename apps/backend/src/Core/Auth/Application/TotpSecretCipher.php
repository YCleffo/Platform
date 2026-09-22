<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface TotpSecretCipher
{
    public function encrypt(string $plaintext): EncryptedTotpSecret;

    public function decrypt(string $ciphertextHex, int $keyVersion): string;
}
