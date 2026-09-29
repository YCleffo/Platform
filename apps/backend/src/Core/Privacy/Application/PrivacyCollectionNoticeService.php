<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Domain\MachineCode;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PrivacyCollectionNoticeService
{
    public function __construct(private ConnectionInterface $db, private ClockInterface $clock,
        private ProcessingPurposeRegistry $purposes, private DataCategoryRegistry $categories) {}

    /** @param list<string> $categoryCodes */
    public function record(AuthenticatedIdentity $operator, string $subjectPersonId, string $purposeCode,
        array $categoryCodes, string $sourceCode, DateTimeImmutable $collectedAt,
        bool $noticeRequired, ?string $exemptionCode): string
    {
        MachineCode::assert($purposeCode);
        MachineCode::assert($sourceCode);
        if ($exemptionCode !== null) { MachineCode::assert($exemptionCode); }
        $purpose = $this->purposes->get($purposeCode);
        if ($purpose === null || $categoryCodes === [] || count($categoryCodes) > 32
            || count($categoryCodes) !== count(array_unique($categoryCodes))
            || $collectedAt > $this->clock->now()
            || $noticeRequired === ($exemptionCode !== null)) {
            throw new DomainException('Invalid non-subject collection record.');
        }
        foreach ($categoryCodes as $code) {
            MachineCode::assert($code);
            if ($this->categories->get($code) === null || !in_array($code, $purpose->dataCategories, true)) {
                throw new DomainException('Data category is not approved for this purpose.');
            }
        }
        return (string) $this->db->createCommand(<<<'SQL'
            INSERT INTO core.privacy_collection_notices
                (subject_person_id, purpose_code, category_codes, source_code, collected_at,
                 notice_required, exemption_code, created_by_account_id)
            VALUES (CAST(:subject AS uuid), :purpose, CAST(:categories AS text[]), :source,
                CAST(:collected AS timestamptz), :required, :exemption, CAST(:actor AS uuid))
            RETURNING id::text
            SQL, [':subject' => $subjectPersonId, ':purpose' => $purposeCode,
            ':categories' => '{' . implode(',', $categoryCodes) . '}', ':source' => $sourceCode,
            ':collected' => $collectedAt->format(DATE_ATOM), ':required' => $noticeRequired,
            ':exemption' => $exemptionCode, ':actor' => $operator->accountId])->queryScalar();
    }

    public function issue(string $id, string $evidenceReference): void
    {
        if (trim($evidenceReference) === '' || strlen($evidenceReference) > 500) {
            throw new DomainException('Notice evidence reference is required.');
        }
        $changed = $this->db->createCommand(<<<'SQL'
            UPDATE core.privacy_collection_notices
            SET notice_issued_at = CAST(:now AS timestamptz), notice_evidence_reference = :evidence
            WHERE id = CAST(:id AS uuid) AND notice_required AND notice_issued_at IS NULL
            SQL, [':id' => $id, ':now' => $this->clock->now()->format(DATE_ATOM),
            ':evidence' => $evidenceReference])->execute();
        if ($changed !== 1) { throw new DomainException('Notice cannot be issued.'); }
    }

    public function complete(string $id): bool
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT notice_required, exemption_code, notice_issued_at, notice_evidence_reference
            FROM core.privacy_collection_notices WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $id])->queryOne();
        if (!is_array($row)) { throw new DomainException('Collection notice not found.'); }
        return in_array($row['notice_required'], [false, 'f', 0], true)
            ? $row['exemption_code'] !== null
            : $row['notice_issued_at'] !== null && $row['notice_evidence_reference'] !== null;
    }
}
