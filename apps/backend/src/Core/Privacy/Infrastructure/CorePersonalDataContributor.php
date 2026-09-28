<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\PersonalDataContributor;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataSection;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Fixed Core allowlists. Every query is bound to the export subject. */
final readonly class CorePersonalDataContributor implements PersonalDataContributor
{
    public function __construct(private ConnectionInterface $db, private string $section)
    {
        if (!in_array($section, self::codes(), true)) {
            throw new DomainException('Unknown Core personal data section.');
        }
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return ['core.person', 'core.person_contacts', 'core.account', 'core.account_settings',
            'core.consent', 'core.representation', 'core.requests', 'core.processing_blocks'];
    }

    public function code(): string { return $this->section; }

    public function categories(): array
    {
        return match ($this->section) {
            'core.person', 'core.person_contacts', 'core.account' => ['identity'],
            'core.account_settings' => ['preferences'],
            'core.consent' => ['consent'],
            'core.representation' => ['representation'],
            'core.requests' => ['privacy_requests'],
            'core.processing_blocks' => ['processing_restrictions'],
        };
    }

    public function collect(PersonalDataExportContext $context): PersonalDataSection
    {
        $sql = match ($this->section) {
            'core.person' => <<<'SQL'
                SELECT id::text AS "id", status::text AS "status", given_name AS "givenName",
                       family_name AS "familyName", middle_name AS "middleName", display_name AS "displayName",
                       birth_date::text AS "birthDate", created_at::text AS "createdAt", updated_at::text AS "updatedAt"
                FROM core.persons WHERE id = CAST(:subject AS uuid)
                SQL,
            'core.person_contacts' => <<<'SQL'
                SELECT id::text AS "id", contact_type::text AS "contactType", value, label,
                       is_primary AS "isPrimary", verified_at::text AS "verifiedAt",
                       created_at::text AS "createdAt"
                FROM core.person_contacts WHERE person_id = CAST(:subject AS uuid)
                ORDER BY created_at, id
                SQL,
            'core.account' => <<<'SQL'
                SELECT id::text AS "id", status::text AS "status", account_type::text AS "accountType",
                       created_at::text AS "createdAt", updated_at::text AS "updatedAt"
                FROM core.accounts WHERE person_id = CAST(:subject AS uuid) AND account_type = 'human'
                ORDER BY created_at, id
                SQL,
            'core.account_settings' => <<<'SQL'
                SELECT s.account_id::text AS "accountId", s.settings::text AS "settingsJson",
                       s.schema_version AS "schemaVersion"
                FROM core.account_settings s JOIN core.accounts a ON a.id = s.account_id
                WHERE a.person_id = CAST(:subject AS uuid) AND a.account_type = 'human'
                ORDER BY s.account_id
                SQL,
            'core.consent' => <<<'SQL'
                SELECT consent_code AS "consentCode", purpose_code AS "purposeCode",
                       document_version AS "documentVersion", locale, source,
                       granted_at::text AS "grantedAt", withdrawn_at::text AS "withdrawnAt",
                       CASE WHEN subject_person_id = granted_by_person_id THEN 'self' ELSE 'representative' END AS "actorRelation"
                FROM core.privacy_consents
                WHERE subject_person_id = CAST(:subject AS uuid) AND legacy_record = false
                ORDER BY granted_at, id
                SQL,
            'core.representation' => <<<'SQL'
                SELECT r.id::text AS "id", r.authority_type AS "authorityType", r.status,
                       r.valid_from::text AS "validFrom", r.valid_until::text AS "validUntil",
                       r.verified_at::text AS "verifiedAt", r.revoked_at::text AS "revokedAt",
                       COALESCE(array_agg(s.scope_code ORDER BY s.scope_code) FILTER (WHERE s.scope_code IS NOT NULL), ARRAY[]::varchar[]) AS "scopes"
                FROM core.legal_representations r
                LEFT JOIN core.legal_representation_scopes s ON s.representation_id = r.id
                WHERE r.subject_person_id = CAST(:subject AS uuid)
                GROUP BY r.id ORDER BY r.created_at, r.id
                SQL,
            'core.requests' => <<<'SQL'
                SELECT r.id::text AS "id", r.request_type AS "requestType", r.status,
                       r.submitted_at::text AS "submittedAt", r.completed_at::text AS "completedAt",
                       COALESCE(jsonb_agg(jsonb_build_object('code', d.deadline_code,
                           'dueAt', d.due_at, 'extendedDueAt', d.extended_due_at,
                           'satisfiedAt', d.satisfied_at) ORDER BY d.deadline_code)
                           FILTER (WHERE d.id IS NOT NULL), '[]'::jsonb)::text AS "deadlinesJson"
                FROM core.data_subject_requests r
                LEFT JOIN core.data_subject_request_deadlines d ON d.request_id = r.id
                WHERE r.subject_person_id = CAST(:subject AS uuid)
                GROUP BY r.id ORDER BY r.submitted_at, r.id
                SQL,
            'core.processing_blocks' => <<<'SQL'
                SELECT scope_type AS "scopeType", purpose_code AS "purposeCode",
                       resource_type AS "resourceType", resource_id::text AS "resourceId",
                       reason_code AS "reasonCode", started_at::text AS "startedAt",
                       expires_at::text AS "expiresAt", released_at::text AS "releasedAt"
                FROM core.privacy_processing_blocks
                WHERE subject_person_id = CAST(:subject AS uuid)
                ORDER BY started_at, id
                SQL,
        };
        $rows = $this->db->createCommand($sql . "\nLIMIT 1001", [':subject' => $context->subjectPersonId])->queryAll();
        /** @var list<array<string, mixed>> $rows */
        $records = [];
        foreach ($rows as $row) {
            if (array_key_exists('settingsJson', $row)) {
                $row['settings'] = json_decode((string) $row['settingsJson'], true, 5, JSON_THROW_ON_ERROR);
                unset($row['settingsJson']);
            }
            if (array_key_exists('deadlinesJson', $row)) {
                $row['deadlines'] = json_decode((string) $row['deadlinesJson'], true, 5, JSON_THROW_ON_ERROR);
                unset($row['deadlinesJson']);
            }
            if (array_key_exists('scopes', $row)) {
                $row['scopes'] = self::parsePgArray((string) $row['scopes']);
            }
            $records[] = $row;
        }
        return new PersonalDataSection($this->section, $this->categories(), $records);
    }

    /** @return list<string> */
    private static function parsePgArray(string $value): array
    {
        if ($value === '{}') {
            return [];
        }
        return explode(',', trim($value, '{}'));
    }
}
