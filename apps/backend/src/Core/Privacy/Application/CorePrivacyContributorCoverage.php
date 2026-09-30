<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\RetentionCandidate;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Static Core resource matrix, checked against the actual container registrations. */
final readonly class CorePrivacyContributorCoverage
{
    private const RESOURCE_CATEGORIES = [
        'core.person' => 'identity',
        'core.human_account' => 'identity',
        'core.person_contact' => 'identity',
        'core.account_settings' => 'preferences',
        'core.organization_membership' => 'identity',
    ];

    public function __construct(private ProcessingPurposeRegistry $purposes,
        private RetentionPolicyRegistry $policies,
        private RetentionCandidateContributorRegistry $candidates,
        private LawfulProcessingBasisContributorRegistry $bases,
        private RetentionDependencyContributorRegistry $dependencies,
        private ProcessingRestrictionContributorRegistry $restrictions,
        private PersonalDataErasureHandlerRegistry $handlers,
        private ?ConnectionInterface $db = null) {}

    /** @return list<string> Machine codes only; no subject data. */
    public function missing(bool $destructive): array
    {
        $missing = [];
        $rootPurpose = false;
        $resourceCovered = [];
        foreach ($this->purposes->codes() as $purposeCode) {
            $purpose = $this->purposes->get($purposeCode);
            $policy = $this->policies->get($purpose->retentionPolicyCode);
            if ($policy?->action === 'anonymize'
                && in_array('identity', $purpose->dataCategories, true)) { $rootPurpose = true; }
            foreach (self::RESOURCE_CATEGORIES as $resourceType => $category) {
                if (!in_array($category, $purpose->dataCategories, true)) { continue; }
                $root = in_array($resourceType, ['core.person', 'core.human_account'], true);
                if (($policy?->action === 'anonymize') !== $root) { continue; }
                $resourceCovered[$resourceType] = true;
                $prefix = $purposeCode . ':' . $resourceType . ':';
                if (!$this->candidates->coversResourcePurpose($resourceType, $purposeCode)) {
                    $missing[] = $prefix . 'retention';
                }
                // The built-in resolver proves consent from runtime rows; all other bases need a contributor.
                if (!$purpose->requiresConsent && !$this->bases->supports($purposeCode, $resourceType)) {
                    $missing[] = $prefix . 'lawful_basis';
                }
                if ($this->restrictions->forScope('purpose', $purposeCode, null) === []) {
                    $missing[] = $prefix . 'restriction';
                }
                if (in_array($resourceType, ['core.person', 'core.human_account',
                    'core.organization_membership'], true) && !$this->dependencies->supports($resourceType)) {
                    $missing[] = $prefix . 'dependency';
                }
                if ($destructive && $policy !== null) {
                    $action = match ($policy->action) {
                        'delete', 'archive_then_delete' => 'delete',
                        'anonymize' => 'anonymize',
                        'pseudonymize' => 'pseudonymize',
                        default => 'custom',
                    };
                    if ($this->handlers->forAction($resourceType, $action) === null) {
                        $missing[] = $prefix . 'erasure_handler';
                    }
                }
            }
        }
        if ($destructive && !$rootPurpose) {
            $missing[] = 'core.person:anonymization_policy';
            $missing[] = 'core.human_account:anonymization_policy';
        }
        if ($destructive) {
            foreach (array_keys(self::RESOURCE_CATEGORIES) as $resourceType) {
                if (!isset($resourceCovered[$resourceType])) {
                    $missing[] = $resourceType . ':retention_policy';
                }
            }
        }
        sort($missing);
        return array_values(array_unique($missing));
    }

    /** @param list<RetentionCandidate> $candidates @return list<string> */
    public function missingForSubject(string $subjectPersonId, array $candidates): array
    {
        if ($this->db === null) { return ['coverage_unavailable']; }
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT 'core.person' AS resource_type, p.id::text AS resource_id
                FROM core.persons p WHERE p.id = CAST(:subject AS uuid)
            UNION ALL SELECT 'core.human_account', a.id::text FROM core.accounts a
                WHERE a.person_id = CAST(:subject AS uuid) AND a.account_type = 'human'
            UNION ALL SELECT 'core.person_contact', c.id::text FROM core.person_contacts c
                WHERE c.person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 'core.account_settings', s.account_id::text
                FROM core.account_settings s JOIN core.accounts a ON a.id = s.account_id
                WHERE a.person_id = CAST(:subject AS uuid) AND a.account_type = 'human'
            UNION ALL SELECT 'core.organization_membership', m.id::text
                FROM core.organization_memberships m WHERE m.person_id = CAST(:subject AS uuid)
            SQL, [':subject' => $subjectPersonId])->queryAll();
        $byResource = [];
        foreach ($candidates as $candidate) {
            $byResource[$candidate->resourceType . ':' . $candidate->resourceId][] = $candidate;
        }
        $missing = [];
        foreach ($rows as $row) {
            $type = (string) $row['resource_type'];
            $key = $type . ':' . $row['resource_id'];
            $group = $byResource[$key] ?? [];
            if ($group === []) { $missing[] = $type . ':retention'; continue; }
            $action = in_array($type, ['core.person', 'core.human_account'], true)
                ? 'anonymize' : 'delete';
            if ($this->handlers->forAction($type, $action) === null) {
                $missing[] = $type . ':erasure_handler';
            }
            if (in_array($type, ['core.person', 'core.human_account',
                'core.organization_membership'], true) && !$this->dependencies->supports($type)) {
                $missing[] = $type . ':dependency';
            }
            foreach ($group as $candidate) {
                $purpose = $this->purposes->get($candidate->purposeCode);
                $policy = $this->policies->get($candidate->retentionPolicyCode);
                if ($purpose === null || $policy === null
                    || ($policy->action !== $action && !($action === 'delete'
                        && $policy->action === 'archive_then_delete'))
                    || (!$purpose->requiresConsent
                        && !$this->bases->supports($candidate->purposeCode, $type))) {
                    $missing[] = $type . ':policy_or_basis';
                }
            }
            $candidatePurposes = array_map(static fn(RetentionCandidate $candidate): string =>
                $candidate->purposeCode, $group);
            foreach ($this->purposes->codes() as $purposeCode) {
                $purpose = $this->purposes->get($purposeCode);
                $policy = $this->policies->get($purpose->retentionPolicyCode);
                $root = $action === 'anonymize';
                if (in_array(self::RESOURCE_CATEGORIES[$type], $purpose->dataCategories, true)
                    && (($policy?->action === 'anonymize') === $root)
                    && !in_array($purposeCode, $candidatePurposes, true)) {
                    $missing[] = $type . ':purpose_coverage';
                }
            }
        }
        sort($missing);
        return array_values(array_unique($missing));
    }
}
