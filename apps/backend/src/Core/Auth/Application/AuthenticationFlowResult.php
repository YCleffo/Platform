<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class AuthenticationFlowResult
{
    private function __construct(
        public string $status,
        public ?AuthenticationResult $authentication,
        public ?string $mfaChallengeToken,
    ) {}

    public static function authenticated(AuthenticationResult $authentication): self
    {
        return new self('authenticated', $authentication, null);
    }

    public static function mfaRequired(string $challengeToken): self
    {
        return new self('mfa_required', null, $challengeToken);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->authentication !== null) {
            return ['status' => $this->status, ...$this->authentication->toArray()];
        }

        return [
            'status' => $this->status,
            'challengeToken' => $this->mfaChallengeToken,
            'allowedFactors' => ['totp', 'recovery_code'],
        ];
    }
}
