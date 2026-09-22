<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface OidcClientRegistryInterface
{
    public function get(string $provider): OidcClientInterface;
}
