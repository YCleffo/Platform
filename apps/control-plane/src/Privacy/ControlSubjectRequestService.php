<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

use Doctrine\DBAL\Connection;

final readonly class ControlSubjectRequestService
{
    public function __construct(private Connection $connection, private ControlPrivacyOperator $operators) {}

    /** @param array<string,mixed> $data */
    public function intake(string $operatorId, array $data): string
    {
        $this->operators->assertAuthorized($operatorId);
        $kind = $this->required($data, 'subjectKind');
        $type = $this->required($data, 'requestType');
        $channel = $this->required($data, 'sourceChannel');
        $reference = $this->required($data, 'subjectReference');
        $evidence = $this->required($data, 'identityEvidenceReference');
        if (!in_array($kind, ['billing_contact','financial_snapshot','other'], true)
            || !in_array($type, ['access','processing_information','correction','erasure'], true)
            || !in_array($channel, ['paper','email','signed_electronic_document'], true)
            || !str_starts_with($evidence, 'urn:') || strlen($evidence) > 500) {
            throw new \InvalidArgumentException('Invalid privacy request metadata.');
        }
        $profileId = $this->nullable($data, 'billingProfileId');
        $invoiceId = $this->nullable($data, 'invoiceId');
        $receiptId = $this->nullable($data, 'receiptId');
        if (($kind === 'billing_contact' && $profileId === null)
            || ($kind === 'financial_snapshot' && $invoiceId === null && $receiptId === null)
            || ($kind === 'other' && ($profileId !== null || $invoiceId !== null || $receiptId !== null))) {
            throw new \InvalidArgumentException('Subject binding is required.');
        }
        foreach ([$profileId, $invoiceId, $receiptId] as $id) {
            if ($id !== null && !self::isUuid($id)) {
                throw new \InvalidArgumentException('Invalid subject binding.');
            }
        }
        if ($profileId !== null && $this->connection->fetchOne('SELECT 1 FROM commercial.billing_profiles WHERE id = ?', [$profileId]) === false) {
            throw new \DomainException('Billing profile not found.');
        }
        if ($invoiceId !== null && $this->connection->fetchOne('SELECT 1 FROM commercial.invoices WHERE id = ? AND (?::uuid IS NULL OR billing_profile_id = ?::uuid)', [$invoiceId, $profileId, $profileId]) === false) {
            throw new \DomainException('Invoice binding mismatch.');
        }
        if ($receiptId !== null && $this->connection->fetchOne(<<<'SQL'
SELECT 1 FROM commercial.receipts r JOIN commercial.invoices i ON i.id = r.invoice_id
WHERE r.id = ? AND (?::uuid IS NULL OR r.invoice_id = ?::uuid)
    AND (?::uuid IS NULL OR i.billing_profile_id = ?::uuid)
SQL, [$receiptId, $invoiceId, $invoiceId, $profileId, $profileId]) === false) {
            throw new \DomainException('Receipt binding mismatch.');
        }
        $receivedText = $this->required($data, 'receivedAt');
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $receivedText) !== 1) {
            throw new \InvalidArgumentException('Invalid receivedAt.');
        }
        $receivedAt = new \DateTimeImmutable($receivedText);
        if ($receivedAt > new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Future receivedAt is invalid.');
        }
        return (string) $this->connection->fetchOne(<<<'SQL'
INSERT INTO commercial.privacy_subject_requests
 (subject_kind, subject_reference, identity_evidence_reference, billing_profile_id, invoice_id, receipt_id,
 request_type, source_channel, received_at, operator_user_id)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
SQL, [$kind, $reference, $evidence, $profileId, $invoiceId, $receiptId, $type, $channel, $receivedAt->format('c'), $operatorId]);
    }

    /** @return array<string,mixed> */
    public function resolve(string $operatorId, string $requestId, ?string $contactName = null,
        ?string $contactEmail = null, ?string $responseEvidenceReference = null): array
    {
        $this->operators->assertAuthorized($operatorId);
        if ($responseEvidenceReference !== null && (!str_starts_with($responseEvidenceReference, 'urn:')
            || strlen($responseEvidenceReference) > 500)) {
            throw new \InvalidArgumentException('Invalid response evidence reference.');
        }
        return $this->connection->transactional(function (Connection $db) use ($requestId, $contactName, $contactEmail, $responseEvidenceReference): array {
            $request = $db->fetchAssociative('SELECT * FROM commercial.privacy_subject_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($request === false || !in_array($request['status'], ['submitted','in_review'], true)) {
                throw new \DomainException('Open privacy request not found.');
            }
            $type = $request['request_type'];
            $profile = $request['billing_profile_id'] === null ? false : $db->fetchAssociative('SELECT id, contact_name, contact_email FROM commercial.billing_profiles WHERE id = ?', [$request['billing_profile_id']]);
            $invoice = $request['invoice_id'] === null ? false : $db->fetchAssociative('SELECT id, bill_to_contact_name, bill_to_contact_email FROM commercial.invoices WHERE id = ?', [$request['invoice_id']]);
            $receipt = $request['receipt_id'] === null ? false : $db->fetchAssociative('SELECT id, customer_name, customer_email FROM commercial.receipts WHERE id = ?', [$request['receipt_id']]);
            $status = 'completed';
            $reason = 'request_fulfilled';
            $data = [];
            if (in_array($type, ['access','processing_information'], true)) {
                if ($request['subject_kind'] === 'other' && $responseEvidenceReference === null) {
                    throw new \DomainException('Verified external response evidence is required for an unlinked subject.');
                }
                $purposes = [];
                if ($profile !== false || $invoice !== false) { $purposes[] = 'billing'; }
                if ($receipt !== false) { $purposes[] = 'fiscal_receipt'; }
                $data = ['subjectReference' => $request['subject_reference'],
                    'responseDocumentReference' => $responseEvidenceReference,
                    'billingContact' => $profile ?: null,
                    'invoiceContactSnapshot' => $invoice ?: null, 'receiptContactSnapshot' => $receipt ?: null,
                    'processing' => ['factOfProcessing' => $purposes !== [], 'purposes' => $purposes, 'method' => 'automated',
                        'financialEvidenceRetained' => $invoice !== false || $receipt !== false]];
            } elseif ($type === 'correction') {
                if ($profile === false || $contactName === null || $contactEmail === null || trim($contactName) === ''
                    || filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \DomainException('Mutable billing contact and corrected values required.');
                }
                $db->executeStatement('UPDATE commercial.billing_profiles SET contact_name = ?, contact_email = ?, updated_at = now() WHERE id = ?', [trim($contactName), strtolower(trim($contactEmail)), $profile['id']]);
                $reason = 'mutable_contact_corrected';
            } elseif ($type === 'erasure') {
                if ($profile === false) {
                    $status = 'blocked';
                    $reason = 'no_mutable_contact';
                } elseif ($db->fetchOne('SELECT 1 FROM commercial.privacy_subject_holds WHERE billing_profile_id = ? AND released_at IS NULL LIMIT 1', [$profile['id']]) !== false) {
                    $status = 'blocked';
                    $reason = 'active_hold';
                } else {
                    $db->executeStatement('UPDATE commercial.billing_profiles SET contact_name = NULL, contact_email = NULL, updated_at = now() WHERE id = ?', [$profile['id']]);
                    $financial = (int) $db->fetchOne('SELECT (SELECT count(*) FROM commercial.invoices WHERE billing_profile_id = ?) + (SELECT count(*) FROM commercial.receipts r JOIN commercial.invoices i ON i.id = r.invoice_id WHERE i.billing_profile_id = ?)', [$profile['id'], $profile['id']]);
                    $status = $financial > 0 ? 'partially_completed' : 'completed';
                    $reason = $financial > 0 ? 'financial_evidence_retained' : 'mutable_contact_erased';
                }
            }
            $db->executeStatement('UPDATE commercial.privacy_subject_requests SET status = ?, resolution_code = ?, response_evidence_reference = ?, completed_at = now() WHERE id = ?', [$status, $reason, $responseEvidenceReference, $requestId]);
            return ['requestId' => $requestId, 'status' => $status, 'reasonCode' => $reason, 'data' => $data];
        });
    }

    /** @param array<string,mixed> $data */
    private function required(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null) || trim($data[$key]) === '' || strlen($data[$key]) > 500) {
            throw new \InvalidArgumentException('Invalid privacy request field: ' . $key);
        }
        return trim($data[$key]);
    }

    /** @param array<string,mixed> $data */
    private function nullable(array $data, string $key): ?string
    {
        return isset($data[$key]) ? $this->required($data, $key) : null;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }
}
