<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

interface PersonalDataContributor
{
    public function code(): string;

    /** @return list<array<string, mixed>> */
    public function export(string $controlUserId): array;
}
