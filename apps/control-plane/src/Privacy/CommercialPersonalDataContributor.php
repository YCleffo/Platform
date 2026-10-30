<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

use Doctrine\DBAL\Connection;

final readonly class CommercialPersonalDataContributor implements PersonalDataContributor
{
    public function __construct(private Connection $connection)
    {
    }

    public function code(): string
    {
        return 'control_commercial';
    }

    public function export(string $controlUserId): array
    {
        $memberships = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT m.id, m.commercial_account_id, m.role, m.created_at, m.revoked_at
FROM commercial.commercial_account_memberships m WHERE m.control_user_id = ? ORDER BY m.created_at, m.id
SQL, [$controlUserId]);
        $contacts = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT p.id, p.commercial_account_id, p.contact_name, p.contact_email, p.created_at, p.archived_at
FROM commercial.billing_profiles p
JOIN commercial.commercial_account_memberships m ON m.commercial_account_id = p.commercial_account_id
JOIN commercial.control_users u ON u.id = m.control_user_id
WHERE m.control_user_id = ? AND p.contact_email = u.email_normalized
ORDER BY p.created_at, p.id
SQL, [$controlUserId]);
        $invoices = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT i.id, i.commercial_account_id, i.status, i.bill_to_contact_name, i.bill_to_contact_email, i.created_at
FROM commercial.invoices i
JOIN commercial.commercial_account_memberships m ON m.commercial_account_id = i.commercial_account_id
JOIN commercial.control_users u ON u.id = m.control_user_id
WHERE m.control_user_id = ? AND i.bill_to_contact_email = u.email_normalized
ORDER BY i.created_at, i.id
SQL, [$controlUserId]);
        $receipts = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT r.id, r.commercial_account_id, r.status, r.customer_name, r.customer_email, r.created_at
FROM commercial.receipts r
JOIN commercial.commercial_account_memberships m ON m.commercial_account_id = r.commercial_account_id
JOIN commercial.control_users u ON u.id = m.control_user_id
WHERE m.control_user_id = ? AND r.customer_email = u.email_normalized
ORDER BY r.created_at, r.id
SQL, [$controlUserId]);
        $invitations = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT i.id, i.commercial_account_id, i.role, i.status, i.created_at, i.expires_at, i.accepted_at, i.declined_at, i.revoked_at
FROM commercial.commercial_account_invitations i
JOIN commercial.control_users u ON u.id = ?
WHERE i.invited_by = ? OR i.accepted_by = ? OR i.email_normalized = u.email_normalized
ORDER BY i.created_at, i.id
SQL, [$controlUserId, $controlUserId, $controlUserId]);

        return [
            ['kind' => 'memberships', 'items' => $memberships],
            ['kind' => 'billing_contacts', 'items' => $contacts],
            ['kind' => 'invoice_contact_snapshots', 'items' => $invoices],
            ['kind' => 'receipt_contact_snapshots', 'items' => $receipts],
            ['kind' => 'invitations', 'items' => $invitations],
        ];
    }
}
