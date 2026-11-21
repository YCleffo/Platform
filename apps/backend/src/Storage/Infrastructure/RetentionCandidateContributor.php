<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionCandidateContributor as Contributor;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\RetentionCandidate;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Uses deployment-defined Core purposes and retention durations, never invents them. */
final readonly class RetentionCandidateContributor implements Contributor
{
    public function __construct(private ConnectionInterface $db, private ProcessingPurposeRegistry $purposes, private RetentionPolicyRegistry $policies) {}
    public function code(): string
    {
        return 'storage.space_retention';
    }
    public function supportsPurpose(string $purposeCode): bool
    {
        $purpose = $this->purposes->get($purposeCode);
        return $purpose !== null && in_array('storage', $purpose->dataCategories, true)
            && $this->policies->get($purpose->retentionPolicyCode) !== null;
    }
    public function candidates(int $limit, ?string $subjectPersonId = null): iterable
    {
        if ($limit < 1) {
            return;
        }
        $rows = $this->db->createCommand('SELECT id::text,subject_person_id::text AS subject FROM storage.spaces
            WHERE subject_person_id IS NOT NULL' . ($subjectPersonId === null ? '' : ' AND subject_person_id=CAST(:subject AS uuid)')
            . ' ORDER BY id LIMIT :limit', [':limit' => min(1001, $limit), ...($subjectPersonId === null ? [] : [':subject' => $subjectPersonId])])->queryAll();
        $count = 0;
        foreach ($rows as $row) {
            foreach ($this->purposes->codes() as $purposeCode) {
                if (!$this->supportsPurpose($purposeCode)) {
                    continue;
                }
                $purpose = $this->purposes->get($purposeCode);
                $policy = $purpose === null ? null : $this->policies->get($purpose->retentionPolicyCode);
                if ($policy === null) {
                    continue;
                }
                $params = [':subject' => $row['subject']];
                $value = match ($policy->trigger) {
                    'withdrawal' => $this->db->createCommand(
                        'SELECT max(withdrawn_at)::text FROM core.privacy_consents
                        WHERE subject_person_id=CAST(:subject AS uuid) AND purpose_code=:purpose AND legacy_record=false',
                        [...$params, ':purpose' => $purposeCode],
                    )->queryScalar(),
                    'account_archived' => $this->db->createCommand('SELECT greatest((SELECT archived_at FROM core.persons WHERE id=CAST(:subject AS uuid)),
                        (SELECT max(archived_at) FROM core.accounts WHERE person_id=CAST(:subject AS uuid)))::text', $params)->queryScalar(),
                    'relationship_end' => $this->db->createCommand('SELECT max(left_at)::text FROM core.organization_memberships WHERE person_id=CAST(:subject AS uuid)', $params)->queryScalar(),
                    default => null,
                };
                yield new RetentionCandidate(
                    (string) $row['subject'],
                    'storage.space',
                    (string) $row['id'],
                    $purposeCode,
                    $policy->code,
                    $policy->trigger,
                    is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null,
                    $this->code(),
                );
                if (++$count >= $limit) {
                    return;
                }
            }
        }
    }
}
