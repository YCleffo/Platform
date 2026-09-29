<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use InvalidArgumentException;

final readonly class ServiceCredentialScopeRegistry
{
    private const FOUNDATION = [
        'runtime.entitlements.read', 'runtime.entitlements.apply',
        'usage.report', 'deployment.status.write',
    ];

    /** @var list<string> */
    private array $allowed;

    /** @param list<string> $additionalScopes */
    public function __construct(array $additionalScopes = [])
    {
        foreach ($additionalScopes as $scope) {
            if (!is_string($scope) || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D', $scope) !== 1) {
                throw new InvalidArgumentException('Invalid service credential scope registration.');
            }
        }
        $this->allowed = array_values(array_unique([...self::FOUNDATION, ...$additionalScopes]));
    }

    /** @param list<string> $scopes
     * @return list<string>
     */
    public function validate(array $scopes): array
    {
        if ($scopes === [] || count($scopes) > 32) {
            throw new InvalidArgumentException('Invalid service credential scopes.');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !in_array($scope, $this->allowed, true)) {
                throw new InvalidArgumentException('Unknown service credential scope.');
            }
        }
        return array_values(array_unique($scopes));
    }
}
