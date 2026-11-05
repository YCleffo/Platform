<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/** Account-scoped recovery/read model. No provider secrets or financial truth are written here. */
final readonly class GetBillingState
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization) {}

    /** @return array<string, mixed> */
    public function get(string $userId, string $accountId, int $limit = 25, int $offset = 0, string $query = ''): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || $limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000
            || preg_match('/\A[0-9a-f-]{0,36}\z/iD', $query) !== 1) throw new \InvalidArgumentException('Invalid billing history query.');
        $this->authorization->requirePermission($userId, $accountId, 'account.read');
        $profile = $this->connection->fetchAssociative('SELECT legal_name,country_code,contact_name,contact_email,tax_identifier,address::text AS address FROM commercial.billing_profiles WHERE commercial_account_id=? AND archived_at IS NULL ORDER BY is_default DESC,created_at DESC,id DESC LIMIT 1', [$accountId]);
        if ($profile !== false) $profile['address'] = json_decode((string) $profile['address'], false, 32, JSON_THROW_ON_ERROR);
        $subscription = $this->connection->fetchOne("SELECT id FROM commercial.subscriptions WHERE commercial_account_id=? AND status IN ('active','draft') ORDER BY (status='active') DESC,created_at DESC,id DESC LIMIT 1", [$accountId]);
        $invoice = $this->connection->fetchOne("SELECT id FROM commercial.invoices WHERE commercial_account_id=? AND status='issued' ORDER BY created_at DESC,id DESC LIMIT 1", [$accountId]);
        $payment = is_string($invoice) ? $this->connection->fetchOne("SELECT id FROM commercial.payments WHERE commercial_account_id=? AND invoice_id=? AND status IN ('pending','waiting_for_capture') ORDER BY created_at DESC,id DESC LIMIT 1", [$accountId, $invoice]) : false;
        $change = $this->connection->fetchOne("SELECT id FROM commercial.subscription_plan_changes WHERE commercial_account_id=? AND status NOT IN ('applied','cancelled','refunded','failed') ORDER BY requested_at DESC,id DESC LIMIT 1", [$accountId]);
        $latestInvoice = $this->connection->fetchOne("SELECT id FROM commercial.invoices WHERE commercial_account_id=? AND status<>'draft' ORDER BY created_at DESC,id DESC LIMIT 1", [$accountId]);
        $latestPayment = $this->connection->fetchOne('SELECT id FROM commercial.payments WHERE commercial_account_id=? ORDER BY created_at DESC,id DESC LIMIT 1', [$accountId]);
        $documents = <<<'SQL'
WITH documents AS (
 SELECT 'invoice'::text AS kind,id,status::text AS status,created_at,currency,total_minor AS amount_minor
 FROM commercial.invoices WHERE commercial_account_id=? AND status<>'draft'
 UNION ALL
 SELECT 'payment',id,status::text,created_at,currency,amount_minor FROM commercial.payments WHERE commercial_account_id=?
 UNION ALL
 SELECT 'plan_change',id,status::text,requested_at,currency,amount_due_minor FROM commercial.subscription_plan_changes WHERE commercial_account_id=?
)
SQL;
        $filter = " WHERE id::text ILIKE ?";
        $params = [$accountId, $accountId, $accountId, '%' . $query . '%'];
        $total = (int) $this->connection->fetchOne($documents . ' SELECT count(*) FROM documents' . $filter, $params);
        $rows = $this->connection->fetchAllAssociative($documents . ' SELECT * FROM documents' . $filter . ' ORDER BY created_at DESC,id DESC,kind LIMIT ? OFFSET ?',
            [...$params, $limit, $offset], [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]);
        foreach ($rows as &$row) $row['amount_minor'] = Money::nonNegativeBigInt($row['amount_minor']);
        unset($row);
        return [
            'billing_profile' => $profile === false ? null : $profile,
            'subscription_id' => is_string($subscription) ? $subscription : null,
            'pending_invoice_id' => is_string($invoice) ? $invoice : null,
            'pending_payment_id' => is_string($payment) ? $payment : null,
            'plan_change_id' => is_string($change) ? $change : null,
            'latest_invoice_id' => is_string($latestInvoice) ? $latestInvoice : null,
            'latest_payment_id' => is_string($latestPayment) ? $latestPayment : null,
            'history' => ['items' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }
}
