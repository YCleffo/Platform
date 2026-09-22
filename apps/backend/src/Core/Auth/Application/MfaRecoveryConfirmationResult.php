<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class MfaRecoveryConfirmationResult
{
    /** @param list<string> $providers */
    private function __construct(
        public string $status,
        public ?string $recoveryToken = null,
        public array $providers = [],
        public ?string $expiresAt = null,
    ) {}

    public static function completed(): self
    {
        return new self('completed');
    }

    /** @param non-empty-list<string> $providers */
    public static function externalReauthenticationRequired(
        string $recoveryToken,
        array $providers,
        string $expiresAt,
    ): self {
        return new self('external_reauth_required', $recoveryToken, $providers, $expiresAt);
    }

    public static function additionalRecoveryRequired(): self
    {
        return new self('additional_recovery_required');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = ['status' => $this->status];
        if ($this->recoveryToken !== null) {
            $result['recoveryToken'] = $this->recoveryToken;
            $result['providers'] = $this->providers;
            $result['expiresAt'] = $this->expiresAt;
        }
        return $result;
    }
}
