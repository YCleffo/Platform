<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

/** Shared bounded entry point for manual and scheduled retention evaluation. */
final readonly class RetentionBatchEvaluator
{
    public function __construct(private RetentionCandidateContributorRegistry $candidates,
        private RetentionDecisionService $decisions) {}

    /** @return array{candidates:int, groups:int, counts:array<string,int>} */
    public function evaluate(int $limit): array
    {
        if ($limit < 1 || $limit > 1000) { throw new \InvalidArgumentException('Invalid retention limit.'); }
        $groups = [];
        $candidates = $this->candidates->collect($limit);
        foreach ($candidates as $candidate) {
            $groups[implode(':', [$candidate->subjectPersonId, $candidate->resourceType,
                $candidate->resourceId])][] = $candidate;
        }
        $counts = [];
        foreach ($groups as $group) {
            $evaluation = $this->decisions->evaluateResource($group);
            /** @var array{decisions: list<\App\Core\Privacy\Domain\RetentionDecision>, aggregate: string} $evaluation */
            foreach ($evaluation['decisions'] as $decision) {
                $counts[$decision->code] = ($counts[$decision->code] ?? 0) + 1;
            }
        }
        ksort($counts);
        return ['candidates' => count($candidates), 'groups' => count($groups), 'counts' => $counts];
    }
}
