<?php

declare(strict_types=1);

namespace ControlPlane\Account\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Identity\Domain\EmailPolicy;
use ControlPlane\Shared\Domain\TextPolicy;
use Doctrine\DBAL\Connection;

final readonly class BillingProfileService
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @param array<string, mixed> $address */
    public function save(string $userId, string $accountId, string $legalName, string $countryCode, ?string $contactName, ?string $contactEmail, ?string $taxIdentifier, array $address, string $correlationId): string
    {
        $this->authorization->requirePermission($userId, $accountId, 'billing.manage');
        $legalName = TextPolicy::label($legalName, 240);
        $countryCode = strtoupper(trim($countryCode));
        if (preg_match('/\A[A-Z]{2}\z/D', $countryCode) !== 1) {
            throw new \InvalidArgumentException('Invalid country code.');
        }
        $contactName = $contactName === null ? null : TextPolicy::label($contactName, 200);
        $contactEmail = $contactEmail === null ? null : EmailPolicy::normalize($contactEmail)['normalized'];
        $taxIdentifier = $taxIdentifier === null ? null : TextPolicy::label($taxIdentifier, 80);
        $addressJson = json_encode($address, JSON_THROW_ON_ERROR);

        return $this->connection->transactional(function (Connection $connection) use ($userId, $accountId, $legalName, $countryCode, $contactName, $contactEmail, $taxIdentifier, $addressJson, $correlationId): string {
            $profileId = $connection->fetchOne('SELECT id FROM commercial.billing_profiles WHERE commercial_account_id = ? AND archived_at IS NULL ORDER BY created_at LIMIT 1 FOR UPDATE', [$accountId]);
            if (is_string($profileId)) {
                $connection->executeStatement('UPDATE commercial.billing_profiles SET legal_name = ?, country_code = ?, contact_name = ?, contact_email = ?, tax_identifier = ?, address = ?::jsonb, is_default = true, updated_at = now() WHERE id = ?', [$legalName, $countryCode, $contactName, $contactEmail, $taxIdentifier, $addressJson, $profileId]);
            } else {
                $profileId = $connection->fetchOne('INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, contact_name, contact_email, tax_identifier, address, is_default) VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, true) RETURNING id', [$accountId, $legalName, $countryCode, $contactName, $contactEmail, $taxIdentifier, $addressJson]);
                if (!is_string($profileId)) {
                    throw new \LogicException('Billing profile ID unavailable.');
                }
            }
            $connection->executeStatement("UPDATE commercial.commercial_accounts SET legal_name = COALESCE(legal_name, ?), onboarding_state = CASE WHEN onboarding_state = 'profile_required' THEN 'catalog_selection_pending' ELSE onboarding_state END, updated_at = now() WHERE id = ?", [$legalName, $accountId]);
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'billing_profile.saved', 'billing_profile', ?, ?, 'success')", [$userId, $accountId, $profileId, $correlationId]);

            return $profileId;
        });
    }
}
