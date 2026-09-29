<?php

declare(strict_types=1);

namespace ControlPlane\M2m;

final readonly class DeploymentIdentity
{
    /** @param list<string> $scopes */
    public function __construct(public string $deploymentId, public string $credentialId, public array $scopes) {}
}
