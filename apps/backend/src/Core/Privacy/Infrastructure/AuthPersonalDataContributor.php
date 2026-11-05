<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\PersonalDataContributor;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataSection;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Only the selected metadata columns are eligible for export. */
final readonly class AuthPersonalDataContributor implements PersonalDataContributor
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'core.auth';
    }

    public function categories(): array
    {
        return ['authentication'];
    }

    public function collect(PersonalDataExportContext $context): PersonalDataSection
    {
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT 'identifier' AS "kind", i.identifier_type::text AS "type", i.value AS "value",
                   (i.verified_at IS NOT NULL) AS "verified", i.created_at::text AS "createdAt",
                   NULL::text AS "provider", NULL::text AS "label", NULL::text AS "lastUsedAt",
                   NULL::text AS "expiresAt", NULL::text AS "clientType", NULL::text AS "deviceName",
                   i.id::text AS "sortId"
            FROM core.auth_identifiers i JOIN core.accounts a ON a.id = i.account_id
            WHERE a.person_id = CAST(:subject AS uuid) AND a.account_type = 'human'
            UNION ALL
            SELECT 'external_identity', NULL, NULL, NULL, e.linked_at::text,
                   p.code, e.display_name, e.last_login_at::text, NULL, NULL, NULL, e.id::text
            FROM core.auth_external_identities e
            JOIN core.accounts a ON a.id = e.account_id
            JOIN core.auth_providers p ON p.id = e.provider_id
            WHERE a.person_id = CAST(:subject AS uuid) AND a.account_type = 'human'
            UNION ALL
            SELECT 'passkey', NULL, NULL, NULL, k.created_at::text,
                   NULL, k.label, k.last_used_at::text, NULL, NULL, NULL, k.id::text
            FROM core.auth_passkeys k JOIN core.accounts a ON a.id = k.account_id
            WHERE a.person_id = CAST(:subject AS uuid) AND a.account_type = 'human'
            UNION ALL
            SELECT 'totp', NULL, NULL, (t.verified_at IS NOT NULL), t.created_at::text,
                   NULL, t.label, t.last_used_at::text, NULL, NULL, NULL, t.id::text
            FROM core.auth_totp_factors t JOIN core.accounts a ON a.id = t.account_id
            WHERE a.person_id = CAST(:subject AS uuid) AND a.account_type = 'human'
            UNION ALL
            SELECT 'session', NULL, NULL, (s.mfa_verified_at IS NOT NULL), s.created_at::text,
                   NULL, NULL, s.last_seen_at::text, s.expires_at::text, s.client_type, s.device_name, s.id::text
            FROM core.auth_sessions s JOIN core.accounts a ON a.id = s.account_id
            WHERE a.person_id = CAST(:subject AS uuid) AND a.account_type = 'human'
            ORDER BY "createdAt", "kind", "sortId"
            LIMIT 1001
            SQL, [':subject' => $context->subjectPersonId])->queryAll();
        /** @var list<array<string, mixed>> $rows */
        $records = [];
        foreach ($rows as $row) {
            unset($row['sortId']);
            $records[] = array_filter($row, static fn(mixed $value): bool => $value !== null);
        }
        return new PersonalDataSection($this->code(), $this->categories(), $records);
    }
}
