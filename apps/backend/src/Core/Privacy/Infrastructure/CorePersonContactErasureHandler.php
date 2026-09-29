<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\PersonalDataErasureHandler;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class CorePersonContactErasureHandler implements PersonalDataErasureHandler
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string { return 'core.person_contact.delete'; }
    public function resourceType(): string { return 'core.person_contact'; }
    public function supportsAction(string $action): bool { return $action === 'delete'; }
    public function reconcile(string $itemId): ?string { return null; }

    public function owns(string $subjectPersonId, string $resourceId): bool
    {
        $found = $this->db->createCommand(<<<'SQL'
            SELECT 1 FROM core.person_contacts WHERE id = CAST(:id AS uuid)
                AND person_id = CAST(:subject AS uuid) FOR UPDATE
            SQL, [':id' => $resourceId, ':subject' => $subjectPersonId])->queryScalar();
        return $found !== false && $found !== null;
    }

    public function execute(string $subjectPersonId, string $resourceId, string $action, string $itemId): string
    {
        if ($action !== 'delete') {
            throw new DomainException('Unsupported person contact erasure action.');
        }
        $deleted = $this->db->createCommand(<<<'SQL'
            DELETE FROM core.person_contacts WHERE id = CAST(:id AS uuid)
                AND person_id = CAST(:subject AS uuid) RETURNING id
            SQL, [':id' => $resourceId, ':subject' => $subjectPersonId])->queryScalar();
        if ($deleted === false || $deleted === null) {
            throw new DomainException('Person contact ownership changed.');
        }
        return 'deleted';
    }
}
