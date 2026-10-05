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
        private readonly ?string $sessionId = null,
        private readonly ?\DateTimeImmutable $primaryAuthenticatedAt = null,
        private readonly ?\DateTimeImmutable $mfaAuthenticatedAt = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function sessionId(): ?string { return $this->sessionId; }
    public function primaryAuthenticatedAt(): ?\DateTimeImmutable { return $this->primaryAuthenticatedAt; }
    public function mfaAuthenticatedAt(): ?\DateTimeImmutable { return $this->mfaAuthenticatedAt; }

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
