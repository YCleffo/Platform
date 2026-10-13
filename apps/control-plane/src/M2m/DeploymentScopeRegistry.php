<?php

declare(strict_types=1);

namespace ControlPlane\M2m;

use InvalidArgumentException;

final readonly class DeploymentScopeRegistry
{
    private const FOUNDATION = ['entitlements.read', 'usage.write', 'deployment.status.write'];

    /** @var list<string> */
    private array $allowed;

    /** @param list<mixed> $additionalScopes */
    public function __construct(array $additionalScopes = [])
    {
        $validated = [];
        foreach ($additionalScopes as $scope) {
            if (!is_string($scope) || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D', $scope) !== 1) {
                throw new InvalidArgumentException('Invalid deployment scope registration.');
            }
            $validated[] = $scope;
        }
        $this->allowed = array_values(array_unique([...self::FOUNDATION, ...$validated]));
    }

    /** @param list<mixed> $scopes
     * @return list<string>
     */
    public function validate(array $scopes): array
    {
        if ($scopes === [] || count($scopes) > 32) {
            throw new InvalidArgumentException('Invalid deployment scopes.');
        }
        $validated = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !in_array($scope, $this->allowed, true)) {
                throw new InvalidArgumentException('Unknown deployment scope.');
            }
            $validated[] = $scope;
        }
        return array_values(array_unique($validated));
    }
}
