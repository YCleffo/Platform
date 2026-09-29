<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

final readonly class ControlPrivacyReadinessChecker
{
    /**
     * @param array<string, mixed> $residency
     * @param array<string, mixed> $providerDecisions
     * @param array<string, mixed> $securityReferences
     */
    public function __construct(private ControlPrivacyRetentionPolicy $retention,
        private ControlProcessingRoleRegistry $roles,
        private ControlProviderEgressPolicy $egress,
        private array $residency, private array $providerDecisions,
        private array $securityReferences, private bool $erasurePolicyApproved,
        private ?int $identityRetentionDays, private ?int $billingContactRetentionDays)
    {
    }

    /** @return array{result:string, missingRequirements:list<string>} */
    public function check(): array
    {
        $missing = [];
        foreach ($this->retention->missingCategories() as $category) {
            $missing[] = 'retention.' . $category;
        }
        if (!$this->roles->complete()) {
            $missing[] = 'processing_roles';
        }
        if (!$this->erasurePolicyApproved || $this->identityRetentionDays === null
            || $this->billingContactRetentionDays === null
            || $this->identityRetentionDays < 0 || $this->billingContactRetentionDays < 0) {
            $missing[] = 'erasure_retention_policy';
        }
        foreach (['primary_db_country','backup_country','log_country','verified_at','verification_reference'] as $field) {
            if (!is_string($this->residency[$field] ?? null) || trim($this->residency[$field]) === '') {
                $missing[] = 'residency.' . $field;
            }
        }
        foreach (['smtp','yookassa'] as $provider) {
            $decision = $this->providerDecisions[$provider] ?? null;
            if (!is_array($decision) || !in_array($decision['status'] ?? null, ['allowed','blocked'], true)
                || ($decision['status'] === 'allowed' && (!is_string($decision['country'] ?? null)
                    || trim((string) ($decision['evidence_reference'] ?? '')) === ''))) {
                $missing[] = 'provider_decision.' . $provider;
                continue;
            }
            if ($decision['status'] === 'allowed') {
                $uses = $provider === 'smtp'
                    ? [['control_email_verification', ['contact_email', 'verification_link']]]
                    : [['payment', ['payment_reference','billing_contact']],
                       ['refund', ['payment_reference','billing_contact']],
                       ['fiscal_receipt', ['billing_contact','fiscal_details']]];
                foreach ($uses as [$purpose, $categories]) {
                    try {
                        $this->egress->assertAllowed($provider, $purpose, $categories);
                    } catch (\DomainException|\TypeError) {
                        $missing[] = 'provider_decision.' . $provider;
                        break;
                    }
                }
            }
        }
        foreach (['threat_model','harm_assessment','ispdn_protection_level','responsible_person'] as $field) {
            if (!is_string($this->securityReferences[$field] ?? null)
                || trim($this->securityReferences[$field]) === '') {
                $missing[] = 'security.' . $field;
            }
        }
        sort($missing);
        return ['result' => $missing === [] ? 'PASS' : 'FAIL', 'missingRequirements' => $missing];
    }
}
