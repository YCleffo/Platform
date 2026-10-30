<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

final readonly class ControlPrivacyRetentionPolicy
{
    /** @param array<string, int> $daysByCategory */
    public function __construct(private array $daysByCategory)
    {
        foreach ($daysByCategory as $category => $days) {
            if (!in_array($category, self::categories(), true) || $days < 1) {
                throw new \InvalidArgumentException('Invalid Control Plane retention policy.');
            }
        }
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return ['verification', 'password_reset', 'auth_challenge', 'totp_pending', 'session', 'rate_limit', 'idempotency', 'outbox', 'audit', 'service_credential', 'invitation'];
    }

    public function days(string $category): int
    {
        if (!in_array($category, self::categories(), true) || !isset($this->daysByCategory[$category])) {
            throw new \DomainException('Control Plane retention category is not configured: ' . $category);
        }

        return $this->daysByCategory[$category];
    }

    /** @return list<string> */
    public function missingCategories(): array
    {
        return array_values(array_diff(self::categories(), array_keys($this->daysByCategory)));
    }
}
