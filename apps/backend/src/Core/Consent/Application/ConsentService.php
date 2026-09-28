<?php

declare(strict_types=1);

namespace App\Core\Consent\Application;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Privacy\Application\RepresentativeAuthorityPolicy;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Time\Application\ClockInterface;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ConsentService
{
    public function __construct(
        private ConnectionInterface $db,
        private ConsentDefinitionRegistry $definitions,
        private RepresentativeAuthorityPolicy $representations,
        private ProcessingPurposeRegistry $purposes,
        private AuditService $audit,
        private ClockInterface $clock,
    ) {}

    public function list(string $accountId): array
    {
        $personId = $this->personForAccount($accountId);
        $definitions = array_map(
            fn (ConsentDefinition $definition): array => $definition->toArray(
                $this->purposes->get($definition->purposeCode)?->representativeAllowed ?? false,
            ),
            $this->definitions->all(),
        );
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT id::text, consent_code, purpose_code, document_version,
                   encode(document_sha256, 'hex') AS document_sha256,
                   locale, source, granted_at, withdrawn_at
            FROM core.privacy_consents
            WHERE subject_person_id = CAST(:person AS uuid) AND legacy_record = false
            ORDER BY granted_at DESC, id DESC
            SQL, [':person' => $personId])->queryAll();
        $current = [];
        foreach ($rows as $row) {
            $code = (string) $row['consent_code'];
            if (isset($current[$code])) {
                continue;
            }
            $definition = $this->definitions->get($code);
            $row['current'] = $row['withdrawn_at'] === null
                && $definition !== null
                && $row['purpose_code'] === $definition->purposeCode
                && $row['document_version'] === $definition->currentDocumentVersion
                && $row['document_sha256'] === $definition->currentDocumentSha256Hex;
            $current[$code] = $row;
        }
        return ['definitions' => $definitions, 'consents' => array_values($current)];
    }

    /** @return array<string, mixed> */
    public function grant(string $accountId, string $code, string $locale, string $requestId): array
    {
        $personId = $this->personForAccount($accountId);
        return $this->grantForSubject($accountId, $personId, $code, $locale, $requestId);
    }

    /**
     * Internal representative operation. The actor is resolved from the account and the
     * supplied authority is checked under a row lock in the consent transaction.
     * @return array<string, mixed>
     */
    public function grantForSubject(
        string $actorAccountId,
        string $subjectPersonId,
        string $code,
        string $locale,
        string $requestId,
        ?string $representationId = null,
    ): array {
        $definition = $this->definitions->get($code);
        if ($definition === null) {
            throw new UnknownConsentException('Unknown consent code.');
        }
        return $this->db->transaction(function () use ($actorAccountId, $subjectPersonId, $definition, $locale, $requestId, $representationId): array {
            $actorPersonId = $this->personForAccount($actorAccountId);
            $this->lockSubject($subjectPersonId);
            $representative = $actorPersonId !== $subjectPersonId;
            if ($representative) {
                if (!$this->purposes->get($definition->purposeCode)?->representativeAllowed || $representationId === null) {
                    throw new DomainException('Representative consent is not allowed.');
                }
                $this->representations->assertActiveForAction($representationId, $subjectPersonId, $actorPersonId, 'privacy.consent.grant');
            } elseif ($representationId !== null) {
                throw new DomainException('Self consent cannot use representative authority.');
            }
            $active = $this->active($subjectPersonId, $definition);
            if ($active !== null) {
                if ($active['document_version'] === $definition->currentDocumentVersion
                    && $active['document_sha256'] === $definition->currentDocumentSha256Hex) {
                    $active['current'] = true;
                    return $active;
                }
                $this->withdrawRow((string) $active['id']);
                $this->record($actorAccountId, 'privacy.consent.withdrawn', (string) $active['id']);
            }
            $row = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_consents
                    (granted_by_account_id, subject_person_id, granted_by_person_id, legal_representation_id,
                     consent_code, purpose_code, document_version, document_sha256, locale, source, request_id, granted_at)
                VALUES (CAST(:account AS uuid), CAST(:subject AS uuid), CAST(:actor_person AS uuid),
                        CAST(:representation AS uuid), :code, :purpose, :version, decode(:hash, 'hex'),
                        :locale, :source, CAST(:request_id AS uuid), CAST(:now AS timestamptz))
                RETURNING id::text, consent_code, purpose_code, document_version,
                          encode(document_sha256, 'hex') AS document_sha256, locale, source, granted_at, withdrawn_at
                SQL, [
                ':account' => $actorAccountId,
                ':subject' => $subjectPersonId,
                ':actor_person' => $actorPersonId,
                ':representation' => $representationId,
                ':code' => $definition->code,
                ':purpose' => $definition->purposeCode,
                ':version' => $definition->currentDocumentVersion,
                ':hash' => $definition->currentDocumentSha256Hex,
                ':locale' => $locale,
                ':source' => $representative ? 'representative_service' : 'self_api',
                ':request_id' => $requestId,
                ':now' => $this->clock->now()->format(DATE_ATOM),
            ])->queryOne();
            if (!is_array($row)) {
                throw new DomainException('Consent grant could not be recorded.');
            }
            $this->record($actorAccountId, 'privacy.consent.granted', (string) $row['id']);
            $row['current'] = true;
            return $row;
        });
    }

    public function withdraw(string $accountId, string $code): bool
    {
        $personId = $this->personForAccount($accountId);
        return $this->withdrawForSubject($accountId, $personId, $code);
    }

    public function withdrawForSubject(
        string $actorAccountId,
        string $subjectPersonId,
        string $code,
        ?string $representationId = null,
    ): bool {
        $definition = $this->definitions->get($code);
        if ($definition === null || !$definition->withdrawable) {
            throw new DomainException('Consent is unknown or cannot be withdrawn.');
        }
        return $this->db->transaction(function () use ($actorAccountId, $subjectPersonId, $definition, $representationId): bool {
            $actorPersonId = $this->personForAccount($actorAccountId);
            $this->lockSubject($subjectPersonId);
            if ($actorPersonId !== $subjectPersonId) {
                if (!$this->purposes->get($definition->purposeCode)?->representativeAllowed || $representationId === null) {
                    throw new DomainException('Representative consent withdrawal is not allowed.');
                }
                $this->representations->assertActiveForAction($representationId, $subjectPersonId, $actorPersonId, 'privacy.consent.withdraw');
            } elseif ($representationId !== null) {
                throw new DomainException('Self withdrawal cannot use representative authority.');
            }
            $active = $this->active($subjectPersonId, $definition);
            if ($active === null) {
                return false;
            }
            $this->withdrawRow((string) $active['id']);
            $this->record($actorAccountId, 'privacy.consent.withdrawn', (string) $active['id']);
            return true;
        });
    }

    private function personForAccount(string $accountId): string
    {
        $person = $this->db->createCommand(<<<'SQL'
            SELECT person_id::text FROM core.accounts
            WHERE id = CAST(:id AS uuid) AND account_type = 'human' AND person_id IS NOT NULL
            SQL, [':id' => $accountId])->queryScalar();
        if (!is_string($person)) {
            throw new DomainException('A human Person is required for consent.');
        }
        return $person;
    }

    private function lockSubject(string $subjectPersonId): void
    {
        $id = $this->db->createCommand(
            'SELECT id FROM core.persons WHERE id = CAST(:id AS uuid) FOR UPDATE',
            [':id' => $subjectPersonId],
        )->queryScalar();
        if ($id === false || $id === null) {
            throw new DomainException('Consent subject does not exist.');
        }
    }

    /** @return array<string, mixed>|null */
    private function active(string $subjectPersonId, ConsentDefinition $definition): ?array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT id::text, consent_code, purpose_code, document_version,
                   encode(document_sha256, 'hex') AS document_sha256,
                   locale, source, granted_at, withdrawn_at
            FROM core.privacy_consents
            WHERE subject_person_id = CAST(:subject AS uuid)
              AND consent_code = :code AND purpose_code = :purpose
              AND withdrawn_at IS NULL AND legacy_record = false
            FOR UPDATE
            SQL, [':subject' => $subjectPersonId, ':code' => $definition->code, ':purpose' => $definition->purposeCode])->queryOne();
        return is_array($row) ? $row : null;
    }

    private function withdrawRow(string $id): void
    {
        $this->db->createCommand(<<<'SQL'
            UPDATE core.privacy_consents SET withdrawn_at = GREATEST(CAST(:now AS timestamptz), granted_at)
            WHERE id = CAST(:id AS uuid) AND withdrawn_at IS NULL
            SQL, [':id' => $id, ':now' => $this->clock->now()->format(DATE_ATOM)])->execute();
    }

    private function record(string $accountId, string $action, string $id): void
    {
        $this->audit->record(new WriteAuditEventCommand(
            actorType: 'account', actorAccountId: $accountId,
            action: $action, resourceType: 'core.privacy_consent', resourceId: $id,
        ));
    }
}
