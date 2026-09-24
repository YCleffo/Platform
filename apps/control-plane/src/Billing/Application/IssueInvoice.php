<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class IssueInvoice
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array{id: string, subscription_id: string, status: 'issued', currency: string, total_minor: int, issued_at: string} */
    public function issue(string $controlUserId, string $commercialAccountId, string $subscriptionId, string $idempotencyKey, string $correlationId): array
    {
        if (!Uuid::isValid($controlUserId) || !Uuid::isValid($commercialAccountId) || !Uuid::isValid($subscriptionId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $scope = 'invoice.issue.' . str_replace('-', '', strtolower($commercialAccountId)) . '.' . str_replace('-', '', strtolower($subscriptionId));
        $hash = hash('sha256', json_encode([
            'operation' => 'invoice.issue',
            'commercial_account_id' => strtolower($commercialAccountId),
            'subscription_id' => strtolower($subscriptionId),
        ], JSON_THROW_ON_ERROR));

        return $this->connection->transactional(function (Connection $connection) use ($controlUserId, $commercialAccountId, $subscriptionId, $idempotencyKey, $correlationId, $scope, $hash): array {
            $this->authorization->requirePermission($controlUserId, $commercialAccountId, 'billing.manage');
            $subscription = $connection->fetchAssociative('SELECT id, billing_profile_id, status FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$subscriptionId, $commercialAccountId]);
            if ($subscription === false || !is_string($subscription['billing_profile_id'])) {
                throw new \DomainException('Subscription was not found.');
            }

            $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$idempotencyKey]);
            $otherScope = $connection->fetchOne('SELECT scope FROM commercial.idempotency_keys WHERE idempotency_key = ? AND scope <> ? LIMIT 1', [$idempotencyKey, $scope]);
            if ($otherScope !== false) {
                throw new \DomainException('Idempotency key was reused for a different request.');
            }
            $inserted = $connection->executeStatement(
                "INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status, expires_at) VALUES (?, ?, decode(?, 'hex'), 'pending', now() + interval '24 hours') ON CONFLICT (scope, idempotency_key) DO NOTHING",
                [$scope, $idempotencyKey, $hash],
            );
            $idempotency = $connection->fetchAssociative("SELECT encode(request_sha256, 'hex') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope = ? AND idempotency_key = ? FOR UPDATE", [$scope, $idempotencyKey]);
            if ($idempotency === false || !is_string($idempotency['request_hash'])) {
                throw new \LogicException('Idempotency record unavailable.');
            }
            if (!hash_equals($idempotency['request_hash'], $hash)) {
                throw new \DomainException('Idempotency key was reused with a different request.');
            }
            if ($inserted === 0) {
                if ($idempotency['status'] !== 'completed' || !is_string($idempotency['result_reference'])) {
                    throw new \DomainException('Idempotency operation is already in progress.');
                }

                return $this->result($connection, $commercialAccountId, $subscriptionId, $idempotency['result_reference']);
            }
            if ($subscription['status'] !== 'draft') {
                throw new \DomainException('Initial invoice requires a draft subscription.');
            }

            $existingInvoice = $connection->fetchAssociative("SELECT id, subscription_id, status, currency, total_minor, issued_at FROM commercial.invoices WHERE subscription_id = ? AND billing_reason = 'subscription_create' AND status = 'issued' FOR UPDATE", [$subscriptionId]);
            if ($existingInvoice !== false) {
                if (!is_string($existingInvoice['id'])) {
                    throw new \LogicException('Existing invoice identity is unavailable.');
                }
                $connection->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$existingInvoice['id'], $scope, $idempotencyKey]);

                return $this->normalize($existingInvoice);
            }

            $profile = $connection->fetchAssociative(<<<'SQL'
SELECT id, legal_name, country_code, contact_name, contact_email, tax_identifier, address::text AS address
FROM commercial.billing_profiles
WHERE id = ? AND commercial_account_id = ? AND archived_at IS NULL
FOR UPDATE
SQL, [$subscription['billing_profile_id'], $commercialAccountId]);
            if ($profile === false || !is_string($profile['id']) || !is_string($profile['legal_name']) || !is_string($profile['country_code']) || !is_string($profile['address'])) {
                throw new \DomainException('Subscription billing profile is not active for this account.');
            }

            $items = $connection->fetchAllAssociative(<<<'SQL'
SELECT si.id AS subscription_item_id, si.plan_price_id, si.quantity,
       pp.currency, pp.amount_minor, pp.billing_period,
       p.code AS plan_code, p.version AS plan_version, p.name AS plan_name, p.status AS plan_status
FROM commercial.subscription_items si
JOIN commercial.plan_prices pp ON pp.id = si.plan_price_id
JOIN commercial.plans p ON p.id = pp.plan_id
WHERE si.subscription_id = ?
ORDER BY si.created_at, si.id
FOR UPDATE OF si, pp, p
SQL, [$subscriptionId]);
            if ($items === []) {
                throw new \DomainException('Subscription must contain at least one item.');
            }
            $currency = null;
            $billingPeriod = null;
            $total = 0;
            $lines = [];
            foreach ($items as $item) {
                if ($item['plan_status'] !== 'published' || !is_string($item['currency']) || !is_string($item['billing_period'])
                    || !is_string($item['subscription_item_id']) || !is_string($item['plan_price_id'])
                    || !is_string($item['plan_code']) || !is_string($item['plan_name'])) {
                    throw new \DomainException('Subscription invoice contract is invalid.');
                }
                if (!in_array($item['billing_period'], ['month', 'year'], true)) {
                    throw new \DomainException('Subscription invoice requires recurring prices.');
                }
                if (($currency !== null && $currency !== $item['currency']) || ($billingPeriod !== null && $billingPeriod !== $item['billing_period'])) {
                    throw new \DomainException('Subscription invoice contract has incompatible terms.');
                }
                $quantity = (int) $item['quantity'];
                if ($quantity <= 0) {
                    throw new \DomainException('Subscription item quantity is invalid.');
                }
                $unitAmount = Money::nonNegativeBigInt($item['amount_minor']);
                $lineTotal = Money::multiply($unitAmount, $quantity);
                $total = Money::add($total, $lineTotal);
                $currency = $item['currency'];
                $billingPeriod = $item['billing_period'];
                $lines[] = [
                    'subscription_item_id' => $item['subscription_item_id'],
                    'plan_price_id' => $item['plan_price_id'],
                    'plan_code' => $item['plan_code'],
                    'plan_version' => (int) $item['plan_version'],
                    'plan_name' => $item['plan_name'],
                    'billing_period' => $item['billing_period'],
                    'description' => sprintf('%s — %s subscription', $item['plan_name'], $item['billing_period'] === 'month' ? 'monthly' : 'yearly'),
                    'quantity' => $quantity,
                    'unit_amount_minor' => $unitAmount,
                    'total_minor' => $lineTotal,
                ];
            }
            $invoiceId = $connection->fetchOne(<<<'SQL'
INSERT INTO commercial.invoices (
 commercial_account_id, subscription_id, billing_profile_id, billing_reason, currency, total_minor, status,
 bill_to_legal_name, bill_to_country_code, bill_to_contact_name, bill_to_contact_email, bill_to_tax_identifier, bill_to_address
) VALUES (?, ?, ?, 'subscription_create', ?, 0, 'draft', ?, ?, ?, ?, ?, ?::jsonb)
RETURNING id
SQL, [$commercialAccountId, $subscriptionId, $profile['id'], $currency, $profile['legal_name'], $profile['country_code'], $profile['contact_name'], $profile['contact_email'], $profile['tax_identifier'], $profile['address']]);
            if (!is_string($invoiceId)) {
                throw new \LogicException('Invoice ID unavailable.');
            }
            foreach ($lines as $line) {
                $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.invoice_lines (
 invoice_id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name,
 billing_period, description, quantity, unit_amount_minor, total_minor
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL, [$invoiceId, $line['subscription_item_id'], $line['plan_price_id'], $line['plan_code'], $line['plan_version'], $line['plan_name'], $line['billing_period'], $line['description'], $line['quantity'], $line['unit_amount_minor'], $line['total_minor']]);
            }
            $issued = $connection->fetchAssociative("UPDATE commercial.invoices SET total_minor = ?, status = 'issued', issued_at = now(), updated_at = now() WHERE id = ? RETURNING id, subscription_id, status, currency, total_minor, issued_at", [$total, $invoiceId]);
            if ($issued === false) {
                throw new \LogicException('Issued invoice state is unavailable.');
            }
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'invoice.issued', 'invoice', ?, ?, 'success')", [$controlUserId, $commercialAccountId, $invoiceId, $correlationId]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('invoice.issued', 'invoice', ?, jsonb_build_object('invoice_id', ?::text, 'subscription_id', ?::text, 'commercial_account_id', ?::text, 'currency', ?::text, 'total_minor', ?::bigint))", [$invoiceId, $invoiceId, $subscriptionId, $commercialAccountId, $currency, $total]);
            $connection->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$invoiceId, $scope, $idempotencyKey]);

            return $this->normalize($issued);
        });
    }

    /** @return array{id: string, subscription_id: string, status: 'issued', currency: string, total_minor: int, issued_at: string} */
    private function result(Connection $connection, string $accountId, string $subscriptionId, string $invoiceId): array
    {
        $invoice = $connection->fetchAssociative("SELECT id, subscription_id, status, currency, total_minor, issued_at FROM commercial.invoices WHERE id = ? AND commercial_account_id = ? AND subscription_id = ? AND status = 'issued'", [$invoiceId, $accountId, $subscriptionId]);
        if ($invoice === false) {
            throw new \DomainException('Idempotent invoice result is no longer current.');
        }

        return $this->normalize($invoice);
    }

    /** @param array<string, mixed> $invoice
     * @return array{id: string, subscription_id: string, status: 'issued', currency: string, total_minor: int, issued_at: string}
     */
    private function normalize(array $invoice): array
    {
        if (!is_string($invoice['id']) || !is_string($invoice['subscription_id']) || $invoice['status'] !== 'issued'
            || !is_string($invoice['currency']) || !is_string($invoice['issued_at'])) {
            throw new \LogicException('Issued invoice result is invalid.');
        }

        return [
            'id' => $invoice['id'],
            'subscription_id' => $invoice['subscription_id'],
            'status' => 'issued',
            'currency' => $invoice['currency'],
            'total_minor' => Money::nonNegativeBigInt($invoice['total_minor']),
            'issued_at' => $invoice['issued_at'],
        ];
    }
}
