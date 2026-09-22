<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use InvalidArgumentException;

final readonly class ExternalIdentityProfile
{
    /** @param array<string, scalar|null> $claims */
    public function __construct(
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $displayName,
        public ?string $avatarUrl,
        public array $claims,
    ) {
        if ($subject === '' || strlen($subject) > 255) {
            throw new InvalidArgumentException('OIDC subject must be a non-empty string of at most 255 bytes.');
        }
        if ($avatarUrl !== null
            && (strlen($avatarUrl) > 2_048 || preg_match('~\Ahttps://~i', $avatarUrl) !== 1)
        ) {
            throw new InvalidArgumentException('Provider avatar must be a bounded HTTPS URL.');
        }
        foreach ($claims as $key => $_value) {
            if (!in_array($key, ['locale', 'username', 'givenName', 'familyName'], true)) {
                throw new InvalidArgumentException('External identity claims must use the profile allowlist.');
            }
        }
    }
}
