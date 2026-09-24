<?php

declare(strict_types=1);

namespace ControlPlane\Identity\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ControlUserPrincipal implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $emailNormalized,
        private readonly string $passwordHash,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        if ($this->emailNormalized === '') {
            throw new \LogicException('User identifier cannot be empty.');
        }

        return $this->emailNormalized;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_CONTROL_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }
}
