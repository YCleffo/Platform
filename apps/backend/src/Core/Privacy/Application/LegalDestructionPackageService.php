<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Separate protected legal record; ordinary Audit remains metadata only. */
final readonly class LegalDestructionPackageService
{
    /** @param array<string, mixed> $operator @param array<string, mixed> $configuration */
    public function __construct(private ConnectionInterface $db, private ProcessingPurposeRegistry $purposes,
        private array $operator = [], private array $configuration = []) {}

    public function available(): bool
    {
        foreach (['name', 'address'] as $key) {
            if (!is_string($this->operator[$key] ?? null) || trim($this->operator[$key]) === '') {
                return false;
            }
        }
        foreach (['informationSystemName', 'informationSystemCode', 'destructionMethod'] as $key) {
            if (!is_string($this->configuration[$key] ?? null) || trim($this->configuration[$key]) === '') {
                return false;
            }
        }
        try {
            MachineCode::assert($this->configuration['informationSystemCode']);
        } catch (\Throwable) { return false; }
        $hasProcessorName = is_string($this->configuration['processorName'] ?? null)
            && trim($this->configuration['processorName']) !== '';
        $hasProcessorAddress = is_string($this->configuration['processorAddress'] ?? null)
            && trim($this->configuration['processorAddress']) !== '';
        return $hasProcessorName === $hasProcessorAddress;
    }

    public function subjectName(string $caseId, string $subjectPersonId): string
    {
        $previous = $this->db->createCommand(<<<'SQL'
            SELECT subject_name FROM core.privacy_legal_destruction_packages
            WHERE case_id = CAST(:case AS uuid) ORDER BY created_at LIMIT 1
            SQL, [':case' => $caseId])->queryScalar();
        if (is_string($previous) && $previous !== '') { return $previous; }
        $name = $this->db->createCommand(<<<'SQL'
            SELECT concat_ws(' ', family_name, given_name, middle_name) FROM core.persons
            WHERE id = CAST(:subject AS uuid)
            SQL, [':subject' => $subjectPersonId])->queryScalar();
        if (!is_string($name) || trim($name) === '') {
            throw new DomainException('Legal destruction subject identity is unavailable.');
        }
        return $name;
    }

    public function record(string $evidenceId, string $caseId, string $itemId, string $subjectPersonId,
        string $subjectName, string $executorAccountId, string $journalEventId,
        DateTimeImmutable $destroyedAt, DateTimeImmutable $retainUntil): void
    {
        if (!$this->available()) { throw new DomainException('Legal destruction package configuration is incomplete.'); }
        $case = $this->db->createCommand('SELECT reason_code FROM core.privacy_erasure_cases WHERE id = CAST(:id AS uuid)',
            [':id' => $caseId])->queryOne();
        if (!is_array($case)) { throw new DomainException('Erasure case unavailable.'); }
        $rows = $this->db->createCommand('SELECT purpose_code FROM core.privacy_erasure_item_purposes WHERE item_id = CAST(:id AS uuid)',
            [':id' => $itemId])->queryAll();
        $categories = [];
        foreach ($rows as $row) {
            $purpose = $this->purposes->get((string) $row['purpose_code']);
            if ($purpose === null) { throw new DomainException('Erasure purpose is unavailable.'); }
            if ($purpose->processingRole === 'processor_by_instruction'
                && trim((string) ($this->configuration['processorName'] ?? '')) === '') {
                throw new DomainException('Processor identity is required for legal destruction evidence.');
            }
            $categories = [...$categories, ...$purpose->dataCategories];
        }
        $categories = array_values(array_unique($categories));
        if ($categories === []) { throw new DomainException('Destroyed data categories are unavailable.'); }
        $this->db->createCommand(<<<'SQL'
            INSERT INTO core.privacy_legal_destruction_packages
                (evidence_id, case_id, subject_person_id, subject_name, operator_name, operator_address,
                 processor_name, processor_address, category_codes, information_system_name,
                 information_system_code, destruction_method, destruction_reason_code,
                 destroyed_at, executor_account_id, document_reference, journal_event_id, retain_until)
            VALUES (CAST(:evidence AS uuid), CAST(:case AS uuid), CAST(:subject AS uuid), :subject_name,
                :operator_name, :operator_address, :processor_name, :processor_address,
                CAST(:categories AS text[]), :system_name, :system_code, :method, :reason,
                CAST(:destroyed AS timestamptz), CAST(:executor AS uuid), :document,
                CAST(:journal AS uuid), CAST(:retain AS timestamptz))
            SQL, [':evidence' => $evidenceId, ':case' => $caseId, ':subject' => $subjectPersonId,
            ':subject_name' => $subjectName, ':operator_name' => $this->operator['name'],
            ':operator_address' => $this->operator['address'],
            ':processor_name' => $this->configuration['processorName'] ?? null,
            ':processor_address' => $this->configuration['processorAddress'] ?? null,
            ':categories' => '{' . implode(',', $categories) . '}',
            ':system_name' => $this->configuration['informationSystemName'],
            ':system_code' => $this->configuration['informationSystemCode'],
            ':method' => $this->configuration['destructionMethod'],
            ':reason' => $case['reason_code'], ':destroyed' => $destroyedAt->format(DATE_ATOM),
            ':executor' => $executorAccountId,
            ':document' => 'urn:core:privacy-destruction:' . $evidenceId,
            ':journal' => $journalEventId, ':retain' => $retainUntil->format(DATE_ATOM)])->execute();
    }

    public function attest(string $caseId, string $evidenceId, string $actorAccountId,
        string $signedDocumentReference, string $signatureReference): void
    {
        foreach ([$signedDocumentReference, $signatureReference] as $reference) {
            if (!str_starts_with($reference, 'urn:') || strlen($reference) > 500) {
                throw new DomainException('Signed destruction evidence reference is invalid.');
            }
        }
        $inserted = $this->db->createCommand(<<<'SQL'
            INSERT INTO core.privacy_legal_destruction_signatures
                (package_id, signed_document_reference, signature_reference, attested_by_account_id)
            SELECT p.id, :document, :signature, CAST(:actor AS uuid)
            FROM core.privacy_legal_destruction_packages p
            WHERE p.evidence_id = CAST(:evidence AS uuid) AND p.case_id = CAST(:case AS uuid)
            ON CONFLICT (package_id) DO NOTHING
            SQL, [':document' => $signedDocumentReference, ':signature' => $signatureReference,
            ':actor' => $actorAccountId, ':evidence' => $evidenceId, ':case' => $caseId])->execute();
        if ($inserted !== 1) { throw new DomainException('Unsigned legal package not found or already attested.'); }
    }
}
