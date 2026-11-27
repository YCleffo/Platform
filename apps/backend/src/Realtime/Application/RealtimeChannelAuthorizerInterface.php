<?php

declare(strict_types=1);

namespace App\Realtime\Application;

interface RealtimeChannelAuthorizerInterface
{
    public function canSubscribe(string $accountId, string $personId, string $organizationId, string $channel): bool;
}
