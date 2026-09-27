<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class GetInvoice
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array<string, mixed> */
    public function get(string $controlUserId, string $commercialAccountId, string $invoiceId): array
    {
        if (!Uuid::isValid($controlUserId) || !Uuid::isValid($commercialAccountId) || !Uuid::isValid($invoiceId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $this->authorization->requirePermission($controlUserId, $commercialAccountId, 'account.read');
        $invoice = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, subscription_id, billing_profile_id, billing_reason, status, currency, total_minor,
       service_period_start, service_period_end, issued_at, paid_at, voided_at, created_at,
       bill_to_legal_name, bill_to_country_code, bill_to_contact_name,
       bill_to_contact_email, bill_to_tax_identifier, bill_to_address::text AS bill_to_address
FROM commercial.invoices
WHERE id = ? AND commercial_account_id = ? AND status <> 'draft'
SQL, [$invoiceId, $commercialAccountId]);
        if ($invoice === false || !is_string($invoice['bill_to_address'])) {
            throw new \DomainException('Invoice was not found.');
        }
        $lines = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT id, subscription_item_id, plan_price_id, plan_code, plan_version, plan_name,
       billing_period, description, quantity, unit_amount_minor, total_minor, created_at
FROM commercial.invoice_lines
WHERE invoice_id = ?
ORDER BY created_at, id
SQL, [$invoiceId]);

        return [
            'id' => $invoice['id'],
            'subscription_id' => $invoice['subscription_id'],
            'billing_profile_id' => $invoice['billing_profile_id'],
            'billing_reason' => $invoice['billing_reason'],
            'service_period_start' => $invoice['service_period_start'],
            'service_period_end' => $invoice['service_period_end'],
            'status' => $invoice['status'],
            'currency' => $invoice['currency'],
            'total_minor' => Money::nonNegativeBigInt($invoice['total_minor']),
            'issued_at' => $invoice['issued_at'],
            'paid_at' => $invoice['paid_at'],
            'voided_at' => $invoice['voided_at'],
            'created_at' => $invoice['created_at'],
            'bill_to' => [
                'legal_name' => $invoice['bill_to_legal_name'],
                'country_code' => $invoice['bill_to_country_code'],
                'contact_name' => $invoice['bill_to_contact_name'],
                'contact_email' => $invoice['bill_to_contact_email'],
                'tax_identifier' => $invoice['bill_to_tax_identifier'],
                'address' => json_decode($invoice['bill_to_address'], false, 512, JSON_THROW_ON_ERROR),
            ],
            'lines' => array_map(static fn (array $line): array => [
                'id' => $line['id'],
                'subscription_item_id' => $line['subscription_item_id'],
                'plan_price_id' => $line['plan_price_id'],
                'plan_code' => $line['plan_code'],
                'plan_version' => (int) $line['plan_version'],
                'plan_name' => $line['plan_name'],
                'billing_period' => $line['billing_period'],
                'description' => $line['description'],
                'quantity' => (int) $line['quantity'],
                'unit_amount_minor' => Money::nonNegativeBigInt($line['unit_amount_minor']),
                'total_minor' => Money::nonNegativeBigInt($line['total_minor']),
                'created_at' => $line['created_at'],
            ], $lines),
        ];
    }
}
