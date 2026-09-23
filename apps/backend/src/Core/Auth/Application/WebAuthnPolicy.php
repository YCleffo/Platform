<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Security\Application\IdentifierDigestService;
use App\Environment;
use InvalidArgumentException;

final readonly class WebAuthnPolicy
{
    /** @param list<string> $origins */
    public function __construct(
        public string $rpId,
        public string $rpName,
        public array $origins,
        public int $challengeTtlSeconds = 300,
        private ?IdentifierDigestService $digests = null,
    ) {
        // RP ID must be a valid hostname: lowercase letters, digits, hyphens, dots.
        // No scheme, port, path, query, or fragment.
        if ($rpId === '') {
            throw new InvalidArgumentException('WebAuthn RP ID must not be empty.');
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/i', $rpId)) {
            throw new InvalidArgumentException(
                sprintf('WebAuthn RP ID must be a plain hostname without scheme, port, or path: "%s"', $rpId),
            );
        }
        if ($origins === []) {
            throw new InvalidArgumentException('WebAuthn origins list must not be empty.');
        }
        foreach ($origins as $origin) {
            $parsed = parse_url($origin);
            if ($parsed === false
                || !isset($parsed['scheme'], $parsed['host'])
                || isset($parsed['path'], $parsed['query'], $parsed['fragment'])
                || ($parsed['path'] ?? '') !== ''
                || ($parsed['query'] ?? '') !== ''
                || ($parsed['fragment'] ?? '') !== ''
            ) {
                throw new InvalidArgumentException(
                    sprintf('WebAuthn origin must be scheme+host (no path/query/fragment): "%s"', $origin),
                );
            }
            if (Environment::isProd() && $parsed['scheme'] !== 'https') {
                throw new InvalidArgumentException(
                    sprintf('Production WebAuthn origin must use HTTPS: "%s"', $origin),
                );
            }

            // RP ID must be a registrable-domain suffix of the origin host.
            $host = strtolower($parsed['host']);
            $lcRpId = strtolower($rpId);
            if ($host !== $lcRpId && !str_ends_with($host, '.' . $lcRpId)) {
                throw new InvalidArgumentException(
                    sprintf('WebAuthn origin host "%s" is not compatible with RP ID "%s".', $host, $rpId),
                );
            }
        }
    }

    public function isValidOrigin(string $origin): bool
    {
        return in_array($origin, $this->origins, true);
    }

    /**
     * Derives a deterministic, opaque, privacy-oriented user handle.
     * When IdentifierDigestService has an HMAC key the result cannot be
     * reversed to the account UUID without the server secret.
     * Result is 32 bytes, within the WebAuthn ≤64 byte limit.
     */
    public function makeUserHandle(string $accountId): string
    {
        if ($this->digests !== null) {
            return $this->digests->digestRaw('webauthn:user_handle:' . $accountId);
        }

        // Fallback (no HMAC key configured): plain SHA-256. Not reversible but
        // derivable by anyone who knows the account UUID. Acceptable for dev/test.
        return hash('sha256', 'account:user_handle:' . $accountId, true);
    }

    public function verifyUserHandle(string $userHandle, string $accountId): bool
    {
        return hash_equals($this->makeUserHandle($accountId), $userHandle);
    }
}
