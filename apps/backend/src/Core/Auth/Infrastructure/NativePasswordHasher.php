<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Core\Auth\Application\PasswordHasherInterface;

final readonly class NativePasswordHasher implements PasswordHasherInterface
{
    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm());
    }

    private function algorithm(): string
    {
        if (!in_array('argon2id', password_algos(), true)) {
            throw new \RuntimeException('Argon2id password hashing support is required.');
        }

        return PASSWORD_ARGON2ID;
    }
}
