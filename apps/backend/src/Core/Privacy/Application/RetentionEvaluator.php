<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Privacy\Domain\RetentionDecision;
use App\Core\Time\Application\ClockInterface;

final readonly class RetentionEvaluator
{
    public function __construct(private ProcessingPurposeRegistry $purposes,
        private RetentionPolicyRegistry $policies, private PrivacyDeadlinePolicy $deadlines,
        private LegalHoldService $holds, private LawfulProcessingBasisResolver $bases,
        private RetentionDependencyContributorRegistry $dependencies,
        private ClockInterface $clock) {}

    public function evaluate(RetentionCandidate $candidate): RetentionDecision
    {
        $purpose = $this->purposes->get($candidate->purposeCode);
        $policy = $this->policies->get($candidate->retentionPolicyCode);
        if ($purpose === null || $policy === null || $purpose->retentionPolicyCode !== $policy->code
            || $policy->trigger !== $candidate->triggerCode) {
            return new RetentionDecision($candidate, 'blocked_by_missing_policy');
        }
        if ($candidate->triggeredAt === null || ($policy->duration === null && $policy->durationUnit !== 'event_only')) {
            return new RetentionDecision($candidate, 'blocked_by_missing_policy');
        }
        try {
            $eligibleAt = $policy->durationUnit === 'event_only' ? $candidate->triggeredAt
                : $this->deadlines->dueAt($policy->durationUnit, $policy->duration, $candidate->triggeredAt);
        } catch (\Throwable) {
            return new RetentionDecision($candidate, 'blocked_by_missing_policy');
        }
        if ($eligibleAt > $this->clock->now()) {
            return new RetentionDecision($candidate, 'not_yet_eligible', $eligibleAt);
        }
        try {
            $holdId = $this->holds->findApplicable($candidate);
        } catch (\Throwable) {
            return new RetentionDecision($candidate, 'blocked_by_legal_hold', $eligibleAt);
        }
        if ($holdId !== null) {
            return new RetentionDecision($candidate, 'blocked_by_legal_hold', $eligibleAt, $holdId);
        }
        $basis = $this->bases->resolve($candidate);
        if ($basis === 'active') {
            return new RetentionDecision($candidate, 'blocked_by_active_basis', $eligibleAt);
        }
        if ($basis !== 'inactive') {
            return new RetentionDecision($candidate, 'blocked_by_unknown_basis', $eligibleAt);
        }
        if (!$this->dependencies->resolved($candidate)) {
            return new RetentionDecision($candidate, 'blocked_by_dependency', $eligibleAt);
        }
        $code = match ($policy->action) {
            'delete', 'archive_then_delete' => 'eligible_delete',
            'anonymize' => 'eligible_anonymize',
            'pseudonymize' => 'eligible_pseudonymize',
            'custom_handler' => 'eligible_custom',
            default => 'blocked_by_missing_policy',
        };
        return new RetentionDecision($candidate, $code, $eligibleAt);
    }

    /** @param list<RetentionDecision> $decisions */
    public function aggregate(array $decisions): string
    {
        if ($decisions === []) {
            return 'retain';
        }
        $codes = array_map(static fn(RetentionDecision $decision): string => $decision->code, $decisions);
        foreach ($decisions as $decision) {
            if ($decision->candidate->subjectPersonId !== $decisions[0]->candidate->subjectPersonId
                || $decision->candidate->resourceType !== $decisions[0]->candidate->resourceType
                || $decision->candidate->resourceId !== $decisions[0]->candidate->resourceId) {
                throw new \DomainException('Cannot aggregate different retention resources.');
            }
        }
        if (array_diff($codes, ['eligible_delete', 'eligible_anonymize', 'eligible_pseudonymize', 'eligible_custom']) !== []) {
            return 'retain';
        }
        foreach (['eligible_custom', 'eligible_pseudonymize', 'eligible_anonymize', 'eligible_delete'] as $code) {
            if (in_array($code, $codes, true)) {
                return $code;
            }
        }
        return 'retain';
    }
}
