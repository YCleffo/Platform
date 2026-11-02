<?php

declare(strict_types=1);

namespace ControlPlane\Analytics\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Usage\Application\CurrentUsage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

final readonly class GetAccountAnalytics
{
    public function __construct(
        private Connection $connection,
        private AccountAuthorization $authorization,
        private CurrentUsage $usage,
    ) {
    }

    /** @return array<string, mixed> */
    public function forUser(string $userId, string $accountId, int $days = 30): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId)) {
            throw new AccessDeniedException('Account resource is not available.');
        }
        $this->authorization->requirePermission($userId, $accountId, 'account.read');
        $days = max(1, min($days, 90));

        $roles = $this->connection->fetchAllKeyValue(
            'SELECT role, count(*) FROM commercial.commercial_account_memberships
             WHERE commercial_account_id = ? AND revoked_at IS NULL GROUP BY role',
            [$accountId],
        );
        $byRole = [];
        $memberTotal = 0;
        foreach ($roles as $role => $count) {
            $byRole[$role] = (int) $count;
            $memberTotal += (int) $count;
        }
        $pendingInvitations = (int) $this->connection->fetchOne(
            "SELECT count(*) FROM commercial.commercial_account_invitations
             WHERE commercial_account_id = ? AND status = 'pending' AND expires_at > now()",
            [$accountId],
        );

        $subscription = $this->connection->fetchAssociative(<<<'SQL'
SELECT s.status, s.billing_status, s.current_period_start, s.current_period_end,
       MIN(pp.currency) AS currency,
       COALESCE(SUM(CASE WHEN pp.billing_period = 'year'
                         THEN (pp.amount_minor / 12) * si.quantity
                         ELSE pp.amount_minor * si.quantity END), 0)::bigint AS mrr_minor
FROM commercial.subscriptions s
LEFT JOIN commercial.subscription_items si ON si.subscription_id = s.id AND si.valid_until IS NULL
LEFT JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
WHERE s.commercial_account_id = ? AND s.status = 'active'
GROUP BY s.id
ORDER BY s.created_at DESC
LIMIT 1
SQL, [$accountId]);

        $paid = $this->connection->fetchAssociative(<<<'SQL'
SELECT COALESCE(SUM(p.amount_minor), 0)::bigint AS paid_minor, count(*)::int AS payments_count
FROM commercial.payments p
JOIN commercial.invoices i ON i.id = p.invoice_id
WHERE i.commercial_account_id = ? AND p.status = 'succeeded'
  AND p.succeeded_at >= now() - make_interval(days => ?)
SQL, [$accountId, $days], [ParameterType::STRING, ParameterType::INTEGER]);

        $invoices = $this->connection->fetchAssociative(<<<'SQL'
SELECT COALESCE(SUM(CASE WHEN status = 'issued' THEN total_minor ELSE 0 END), 0)::bigint AS outstanding_minor,
       count(*) FILTER (WHERE created_at >= now() - make_interval(days => ?))::int AS invoices_count
FROM commercial.invoices
WHERE commercial_account_id = ?
SQL, [$days, $accountId], [ParameterType::INTEGER, ParameterType::STRING]);

        $outcomes = $this->connection->fetchAllKeyValue(<<<'SQL'
SELECT outcome, count(*) FROM commercial.audit_events
WHERE commercial_account_id = ? AND occurred_at >= now() - make_interval(days => ?)
GROUP BY outcome
SQL, [$accountId, $days], [ParameterType::STRING, ParameterType::INTEGER]);
        $byOutcome = [];
        foreach ($outcomes as $outcome => $count) {
            $byOutcome[$outcome] = (int) $count;
        }

        $byDay = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT to_char(d.day, 'YYYY-MM-DD') AS date, count(a.id)::int AS count
FROM generate_series(current_date - make_interval(days => ?), current_date, interval '1 day') AS d(day)
LEFT JOIN commercial.audit_events a
  ON a.commercial_account_id = ?
 AND a.occurred_at >= d.day
 AND a.occurred_at < d.day + interval '1 day'
GROUP BY d.day
ORDER BY d.day
SQL, [$days - 1, $accountId], [ParameterType::INTEGER, ParameterType::STRING]);
        $byDay = array_map(static fn(array $row): array => [
            'date' => (string) $row['date'],
            'count' => (int) $row['count'],
        ], $byDay);

        $byAction = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT action, count(*)::int AS count
FROM commercial.audit_events
WHERE commercial_account_id = ? AND occurred_at >= now() - make_interval(days => ?)
GROUP BY action
ORDER BY count DESC, action
LIMIT 8
SQL, [$accountId, $days], [ParameterType::STRING, ParameterType::INTEGER]);
        $byAction = array_map(static fn(array $row): array => [
            'action' => (string) $row['action'],
            'count' => (int) $row['count'],
        ], $byAction);

        try {
            $usage = $this->usage->forAccount($userId, $accountId);
        } catch (\LogicException) {
            $usage = [
                'commercial_account_id' => $accountId,
                'subscription' => null,
                'period' => null,
                'counters' => new \stdClass(),
            ];
        }

        return [
            'account_id' => $accountId,
            'days' => $days,
            'generated_at' => gmdate('c'),
            'members' => [
                'total' => $memberTotal,
                'by_role' => $byRole === [] ? new \stdClass() : $byRole,
                'pending_invitations' => $pendingInvitations,
            ],
            'billing' => [
                'subscription_status' => $subscription === false ? null : (string) $subscription['status'],
                'billing_status' => $subscription === false ? null : (string) $subscription['billing_status'],
                'current_period_start' => $subscription === false ? null : (string) $subscription['current_period_start'],
                'current_period_end' => $subscription === false ? null : (string) $subscription['current_period_end'],
                'currency' => $subscription === false || $subscription['currency'] === null ? null : (string) $subscription['currency'],
                'mrr_minor' => $subscription === false ? 0 : (int) $subscription['mrr_minor'],
                'paid_minor' => (int) ($paid['paid_minor'] ?? 0),
                'payments_count' => (int) ($paid['payments_count'] ?? 0),
                'outstanding_minor' => (int) ($invoices['outstanding_minor'] ?? 0),
                'invoices_count' => (int) ($invoices['invoices_count'] ?? 0),
            ],
            'audit' => [
                'events' => array_sum($byOutcome),
                'by_outcome' => $byOutcome === [] ? new \stdClass() : $byOutcome,
                'by_day' => $byDay,
                'by_action' => $byAction,
            ],
            'usage' => $usage,
        ];
    }
}
