<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;

/**
 * Policy hook allowing domain modules (e.g. Education) to filter visible contacts or enforce scoped room rules
 * while keeping Universal Chat module decoupled from any single product.
 */
interface ChatContextPolicyInterface
{
    public function canCreateRoomWith(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomType,
        array $memberPersonIds,
        ?string $contextType = null,
        ?string $contextId = null,
    ): bool;

    /** @return list<array{id: string, personId: string, name: string, role: string, contextType?: ?string, contextId?: ?string, contextName?: ?string}> */
    public function listAvailableContacts(
        AuthenticatedIdentity $identity,
        string $organizationId,
        ?string $query = null,
    ): array;
}
