<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final readonly class AuthenticatedIdentity
{
    public const ATTRIBUTE = self::class;

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        public string $accountId,
        public ?string $personId,
        public string $sessionId,
        public array $roles,
        public array $permissions,
        public ?string $locale,
        public ?string $timezone,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $identity = $request->getAttribute(self::ATTRIBUTE);
        if (!$identity instanceof self) {
            throw new RuntimeException('Authentication middleware was not executed.');
        }

        return $identity;
    }

    public function hasRole(string $code): bool
    {
        return in_array($code, $this->roles, true);
    }
}
