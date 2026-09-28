<?php

declare(strict_types=1);

namespace App\Core\Consent\Application;

use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ConsentService
{
    public function __construct(
        private ConnectionInterface $db,
        private ConsentDefinitionRegistry $definitions,
    ) {}

    public function list(string $accountId): array
    {
        $definitions = array_map(static fn (ConsentDefinition $definition): array => $definition->toArray(), $this->definitions->all());
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT id::text, consent_code, document_version, encode(document_sha256, 'hex') AS document_sha256,
                   locale, source, granted_at, withdrawn_at
            FROM core.account_consents
            WHERE account_id = CAST(:account_id AS uuid)
            ORDER BY granted_at DESC, id DESC
            SQL, [':account_id' => $accountId])->queryAll();
        $current = [];
        foreach ($rows as $row) {
            $code = (string) $row['consent_code'];
            if (isset($current[$code])) {
                continue;
            }
            $definition = $this->definitions->get($code);
            $row['current'] = $row['withdrawn_at'] === null
                && $definition !== null
                && $row['document_version'] === $definition->currentDocumentVersion
                && $row['document_sha256'] === $definition->currentDocumentSha256Hex;
            $current[$code] = $row;
        }
        return ['definitions' => $definitions, 'consents' => array_values($current)];
    }

    /** @return array<string, mixed> */
    public function grant(string $accountId, string $code, string $locale, string $requestId): array
    {
        $definition = $this->definitions->get($code);
        if ($definition === null) {
            throw new DomainException('Unknown consent code.');
        }
        return $this->db->transaction(function () use ($accountId, $definition, $locale, $requestId): array {
            $this->lockAccount($accountId);
            $active = $this->active($accountId, $definition->code);
            if ($active !== null) {
                if ($active['document_version'] === $definition->currentDocumentVersion
                    && $active['document_sha256'] === $definition->currentDocumentSha256Hex) {
                    $active['current'] = true;
                    return $active;
                }
                $this->withdrawRow((string) $active['id']);
            }
            $row = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.account_consents
                    (account_id, consent_code, document_version, document_sha256, locale, source, request_id)
                VALUES (CAST(:account_id AS uuid), :code, :version, decode(:hash, 'hex'), :locale, 'self_api', CAST(:request_id AS uuid))
                RETURNING id::text, consent_code, document_version, encode(document_sha256, 'hex') AS document_sha256,
                          locale, source, granted_at, withdrawn_at
                SQL, [
                ':account_id' => $accountId,
                ':code' => $definition->code,
                ':version' => $definition->currentDocumentVersion,
                ':hash' => $definition->currentDocumentSha256Hex,
                ':locale' => $locale,
                ':request_id' => $requestId,
            ])->queryOne();
            if (!is_array($row)) {
                throw new \RuntimeException('Consent grant was not returned.');
            }
            $row['current'] = true;
            return $row;
        });
    }

    public function withdraw(string $accountId, string $code): bool
    {
        $definition = $this->definitions->get($code);
        if ($definition === null || !$definition->withdrawable) {
            throw new DomainException('Consent is unknown or cannot be withdrawn.');
        }
        return $this->db->transaction(function () use ($accountId, $code): bool {
            $this->lockAccount($accountId);
            $active = $this->active($accountId, $code);
            if ($active === null) {
                return false;
            }
            $this->withdrawRow((string) $active['id']);
            return true;
        });
    }

    private function lockAccount(string $accountId): void
    {
        $id = $this->db->createCommand('SELECT id FROM core.accounts WHERE id = CAST(:id AS uuid) FOR UPDATE', [':id' => $accountId])->queryScalar();
        if ($id === false || $id === null) {
            throw new DomainException('Account does not exist.');
        }
    }

    /** @return array<string, mixed>|null */
    private function active(string $accountId, string $code): ?array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT id::text, consent_code, document_version, encode(document_sha256, 'hex') AS document_sha256,
                   locale, source, granted_at, withdrawn_at
            FROM core.account_consents
            WHERE account_id = CAST(:account_id AS uuid) AND consent_code = :code AND withdrawn_at IS NULL
            FOR UPDATE
            SQL, [':account_id' => $accountId, ':code' => $code])->queryOne();
        return is_array($row) ? $row : null;
    }

    private function withdrawRow(string $id): void
    {
        $this->db->createCommand(<<<'SQL'
            UPDATE core.account_consents SET withdrawn_at = GREATEST(now(), granted_at)
            WHERE id = CAST(:id AS uuid) AND withdrawn_at IS NULL
            SQL, [':id' => $id])->execute();
    }
}
