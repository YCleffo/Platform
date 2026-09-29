<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

use App\Core\Account\Application\AccountLifecycleRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ArchivePerson
{
    public function __construct(
        private PersonRepository $repository,
        private AccountLifecycleRepository $accounts,
        private ConnectionInterface $db,
    ) {}

    public function execute(string $id): ?PersonView
    {
        return $this->db->transaction(function () use ($id): ?PersonView {
            $exists = $this->db->createCommand(
                'SELECT id FROM core.persons WHERE id = CAST(:id AS uuid) FOR UPDATE',
                [':id' => $id],
            )->queryScalar();
            if ($exists === false || $exists === null) {
                return null;
            }
            $accountIds = $this->db->createCommand(<<<'SQL'
                SELECT id::text FROM core.accounts
                WHERE person_id = CAST(:person AS uuid) AND account_type = 'human' AND status <> 'archived'
                ORDER BY id FOR UPDATE
                SQL, [':person' => $id])->queryColumn();
            foreach ($accountIds as $accountId) {
                $this->accounts->archive((string) $accountId);
            }
            return $this->repository->archive($id);
        });
    }
}
