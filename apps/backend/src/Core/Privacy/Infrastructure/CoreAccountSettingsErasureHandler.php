<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\PersonalDataErasureHandler;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class CoreAccountSettingsErasureHandler implements PersonalDataErasureHandler
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'core.account_settings.delete';
    }
    public function resourceType(): string
    {
        return 'core.account_settings';
    }
    public function supportsAction(string $action): bool
    {
        return $action === 'delete';
    }
    public function reconcile(string $itemId): ?string
    {
        return null;
    }

    public function owns(string $subjectPersonId, string $resourceId): bool
    {
        $found = $this->db->createCommand(<<<'SQL'
            SELECT 1 FROM core.account_settings s JOIN core.accounts a ON a.id = s.account_id
            WHERE s.account_id = CAST(:id AS uuid) AND a.person_id = CAST(:subject AS uuid)
            FOR UPDATE OF s, a
            SQL, [':id' => $resourceId, ':subject' => $subjectPersonId])->queryScalar();
        return $found !== false && $found !== null;
    }

    public function execute(string $subjectPersonId, string $resourceId, string $action, string $itemId): string
    {
        if ($action !== 'delete') {
            throw new DomainException('Unsupported account settings erasure action.');
        }
        $deleted = $this->db->createCommand(<<<'SQL'
            DELETE FROM core.account_settings s USING core.accounts a
            WHERE s.account_id = CAST(:id AS uuid) AND a.id = s.account_id
                AND a.person_id = CAST(:subject AS uuid) RETURNING s.account_id
            SQL, [':id' => $resourceId, ':subject' => $subjectPersonId])->queryScalar();
        if ($deleted === false || $deleted === null) {
            throw new DomainException('Account settings ownership changed.');
        }
        return 'deleted';
    }
}
