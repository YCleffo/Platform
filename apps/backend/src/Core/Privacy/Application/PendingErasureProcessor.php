<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Selects retryable cases; ErasureExecutor owns item transactions and domain decisions. */
final readonly class PendingErasureProcessor
{
    public function __construct(private ConnectionInterface $db, private ErasureExecutor $executor,
        private DestructionEvidencePolicy $evidence, private LegalDestructionPackageService $legal) {}

    public function assertActor(string $accountId): void
    {
        if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/Di', $accountId)) {
            throw new \DomainException('Privacy automation actor is unavailable.');
        }
        $valid = $this->db->createCommand(<<<'SQL'
            SELECT 1 FROM core.accounts WHERE id = CAST(:id AS uuid) AND account_type = 'system'
                AND status = 'active' AND disabled_at IS NULL AND archived_at IS NULL
            SQL, [':id' => $accountId])->queryScalar();
        if ($valid === false || $valid === null) {
            throw new \DomainException('Privacy automation actor is unavailable.');
        }
    }

    public function policyReady(): bool
    {
        return $this->evidence->available() && $this->legal->available();
    }

    public function process(string $accountId, int $caseLimit, int $itemLimit): int
    {
        if ($caseLimit < 1 || $caseLimit > 100 || $itemLimit < 1 || $itemLimit > 100) {
            throw new \InvalidArgumentException('Invalid erasure batch limit.');
        }
        $this->assertActor($accountId);
        if (!$this->policyReady()) { return 0; }
        $cases = $this->db->createCommand(<<<'SQL'
            SELECT c.id::text FROM core.privacy_erasure_cases c
            WHERE c.status IN ('planned', 'in_progress', 'failed', 'partially_completed')
                AND EXISTS (SELECT 1 FROM core.privacy_erasure_items i WHERE i.case_id = c.id
                    AND i.status IN ('planned', 'in_progress', 'failed'))
            ORDER BY c.updated_at, c.id LIMIT :limit
            SQL, [':limit' => $caseLimit])->queryAll();
        $actor = new AuthenticatedIdentity($accountId, null, '', [], [], null, null);
        $processed = 0;
        foreach ($cases as $case) {
            $caseId = (string) $case['id'];
            $key = 'privacy.erasure.execute:' . $caseId;
            $locked = in_array($this->db->createCommand(
                'SELECT pg_try_advisory_lock(hashtextextended(:key, 0))', [':key' => $key]
            )->queryScalar(), [true, 't', '1', 1], true);
            if (!$locked) { continue; }
            try {
                $status = $this->db->createCommand(
                    'SELECT status FROM core.privacy_erasure_cases WHERE id = CAST(:id AS uuid)',
                    [':id' => $caseId])->queryScalar();
                if (!in_array($status, ['planned', 'in_progress', 'failed', 'partially_completed'], true)) {
                    continue;
                }
                // The executor takes the same reentrant session lock and owns all item transitions.
                $this->executor->executeBatch($actor, $caseId, $itemLimit);
                ++$processed;
            } finally {
                $this->db->createCommand(
                    'SELECT pg_advisory_unlock(hashtextextended(:key, 0))', [':key' => $key]
                )->queryScalar();
            }
        }
        return $processed;
    }
}
