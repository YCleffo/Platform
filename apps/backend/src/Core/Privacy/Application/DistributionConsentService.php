<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Domain\MachineCode;
use App\Core\Time\Application\ClockInterface;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Dedicated public-distribution consent; ordinary processing consent is never consulted. */
final readonly class DistributionConsentService
{
    public function __construct(private ConnectionInterface $db, private ClockInterface $clock,
        private RepresentativeAuthorityPolicy $authority,
        private ProcessingPurposeRegistry $purposes, private DataCategoryRegistry $categories,
        private DistributionConsentDefinitionRegistry $definitions) {}

    /**
     * @param list<string> $categories
     * @param list<string> $fields
     * @param list<string> $conditions
     * @param list<string> $restrictions
     */
    public function grant(AuthenticatedIdentity $actor, string $subjectPersonId, string $purposeCode,
        array $categories, array $fields, array $conditions, array $restrictions,
        ?string $representationId = null): string
    {
        MachineCode::assert($purposeCode);
        if ($this->purposes->get($purposeCode)?->distributionAllowed !== true) {
            throw new DomainException('Purpose does not allow public distribution.');
        }
        $definition = $this->definitions->get($purposeCode);
        if ($definition === null || array_diff($categories, $definition->allowedCategories) !== []
            || array_diff($fields, $definition->allowedFields) !== []
            || array_diff($conditions, $definition->allowedConditions) !== []
            || array_diff($restrictions, $definition->allowedRestrictions) !== []) {
            throw new DomainException('Distribution consent is outside the approved document scope.');
        }
        foreach ($categories as $category) {
            if ($this->categories->get($category) === null) {
                throw new DomainException('Unknown distribution data category.');
            }
        }
        /** @var list<list<string>> $codeGroups */
        $codeGroups = [$categories, $fields, $conditions, $restrictions];
        foreach ($codeGroups as $codes) {
            foreach ($codes as $code) {
                MachineCode::assert($code);
            }
            if (count($codes) !== count(array_unique($codes))) {
                throw new DomainException('Duplicate distribution code.');
            }
        }
        if ($actor->personId === null || $categories === [] || $fields === []
            || count($categories) > 32 || count($fields) > 64
            || count($conditions) > 32 || count($restrictions) > 32) {
            throw new DomainException('Invalid distribution consent.');
        }
        $person = $this->db->createCommand(<<<'SQL'
            SELECT person_id::text FROM core.accounts
            WHERE id = CAST(:account AS uuid) AND account_type = 'human'
            SQL, [':account' => $actor->accountId])->queryScalar();
        if ($person !== $actor->personId) {
            throw new DomainException('Human actor is required.');
        }
        if ($subjectPersonId === $actor->personId) {
            if ($representationId !== null) {
                throw new DomainException('Own distribution consent cannot use representation.');
            }
        } else {
            if ($representationId === null) {
                throw new DomainException('Representative authority is required.');
            }
            $this->authority->assertActiveForAction($representationId, $subjectPersonId,
                $actor->personId, 'privacy.consent.grant');
        }
        $now = $this->clock->now()->format(DATE_ATOM);
        return (string) $this->db->createCommand(<<<'SQL'
            INSERT INTO core.privacy_distribution_consents
                (subject_person_id, granted_by_person_id, granted_by_account_id,
                 legal_representation_id, purpose_code, category_codes, field_codes,
                 condition_codes, restriction_codes, document_version, document_sha256, granted_at)
            VALUES (CAST(:subject AS uuid), CAST(:person AS uuid), CAST(:account AS uuid),
                CAST(:representation AS uuid), :purpose, CAST(:categories AS text[]),
                CAST(:fields AS text[]), CAST(:conditions AS text[]), CAST(:restrictions AS text[]),
                :version, :sha, CAST(:now AS timestamptz)) RETURNING id::text
            SQL, [':subject' => $subjectPersonId, ':person' => $actor->personId,
                ':account' => $actor->accountId, ':representation' => $representationId,
                ':purpose' => $purposeCode, ':categories' => self::pgArray($categories),
                ':fields' => self::pgArray($fields), ':conditions' => self::pgArray($conditions),
                ':restrictions' => self::pgArray($restrictions), ':version' => $definition->documentVersion,
                ':sha' => $definition->documentSha256, ':now' => $now])->queryScalar();
    }

    public function withdraw(AuthenticatedIdentity $actor, string $consentId): void
    {
        if ($actor->personId === null) {
            throw new DomainException('Human actor is required.');
        }
        $this->db->transaction(function () use ($actor, $consentId): void {
            $row = $this->db->createCommand(<<<'SQL'
                SELECT subject_person_id::text, legal_representation_id::text, withdrawn_at
                FROM core.privacy_distribution_consents WHERE id = CAST(:id AS uuid) FOR UPDATE
                SQL, [':id' => $consentId])->queryOne();
            if (!is_array($row) || $row['withdrawn_at'] !== null) {
                throw new DomainException('Distribution consent unavailable.');
            }
            if ($row['subject_person_id'] !== $actor->personId) {
                if ($row['legal_representation_id'] === null) {
                    throw new DomainException('Distribution consent unavailable.');
                }
                $this->authority->assertActiveForAction((string) $row['legal_representation_id'],
                    (string) $row['subject_person_id'], $actor->personId, 'privacy.consent.withdraw');
            }
            $this->db->createCommand(<<<'SQL'
                UPDATE core.privacy_distribution_consents
                SET withdrawn_at = CAST(:now AS timestamptz) WHERE id = CAST(:id AS uuid)
                SQL, [':id' => $consentId, ':now' => $this->clock->now()->format(DATE_ATOM)])->execute();
        });
    }

    /** @param list<string> $satisfiedConditions */
    public function allows(string $subjectPersonId, string $purposeCode, string $categoryCode,
        string $fieldCode, string $channelCode, array $satisfiedConditions = []): bool
    {
        foreach ([$purposeCode, $categoryCode, $fieldCode, $channelCode, ...$satisfiedConditions] as $code) {
            MachineCode::assert($code);
        }
        $definition = $this->definitions->get($purposeCode);
        if ($definition === null) { return false; }
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT condition_codes, restriction_codes FROM core.privacy_distribution_consents
            WHERE subject_person_id = CAST(:subject AS uuid) AND purpose_code = :purpose
                AND withdrawn_at IS NULL AND :category = ANY(category_codes)
                AND :field = ANY(field_codes)
                AND document_version = :version AND document_sha256 = :sha
            SQL, [':subject' => $subjectPersonId, ':purpose' => $purposeCode,
                ':category' => $categoryCode, ':field' => $fieldCode,
                ':version' => $definition->documentVersion,
                ':sha' => $definition->documentSha256])->queryAll();
        foreach ($rows as $row) {
            /** @var array{condition_codes: string|list<string>, restriction_codes: string|list<string>} $row */
            $conditions = self::parsePgArray($row['condition_codes']);
            $restrictions = self::parsePgArray($row['restriction_codes']);
            if (!in_array($channelCode, $restrictions, true)
                && array_diff($conditions, $satisfiedConditions) === []) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $codes */
    private static function pgArray(array $codes): string
    {
        return '{' . implode(',', $codes) . '}';
    }

    /** @return list<string> */
    private static function parsePgArray(string|array $value): array
    {
        if (is_array($value)) {
            /** @var list<string> $value */
            return $value;
        }
        return $value === '{}' ? [] : explode(',', trim($value, '{}'));
    }
}
