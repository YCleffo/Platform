<?php

declare(strict_types=1);

namespace App\Chat\Infrastructure;

use App\Media\Application\MediaAccessAuthorizer;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Authorizes media read/playback if referenced inside any non-deleted Universal Chat message
 * belonging to a room where the actor is an active member, or delegates to an optional domain fallback authorizer.
 */
final readonly class ChatMediaAccessAuthorizer implements MediaAccessAuthorizer
{
    public function __construct(
        private ConnectionInterface $db,
        private ?MediaAccessAuthorizer $fallback = null,
    ) {}

    public function canRead(string $organizationId, string $personId, string $assetId): bool
    {
        $inChat = $this->db->createCommand(
            'SELECT 1
             FROM chat.messages m
             JOIN chat.members mem
               ON mem.room_id = m.room_id
              AND mem.organization_id = m.organization_id
             WHERE m.media_asset_id = CAST(:id AS uuid)
               AND m.organization_id = CAST(:org AS uuid)
               AND m.deleted_at IS NULL
               AND mem.person_id = CAST(:person AS uuid)
               AND mem.left_at IS NULL
             LIMIT 1',
            [':id' => $assetId, ':org' => $organizationId, ':person' => $personId],
        )->queryScalar();

        if ($inChat !== false) {
            return true;
        }

        return $this->fallback?->canRead($organizationId, $personId, $assetId) ?? false;
    }
}
