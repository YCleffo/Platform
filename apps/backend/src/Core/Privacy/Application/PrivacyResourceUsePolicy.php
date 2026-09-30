<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

/** Shared application boundary for purpose and resource scoped processing blocks. */
final readonly class PrivacyResourceUsePolicy
{
    public function __construct(private ProcessingBlockPolicy $blocks,
        private ProcessingPurposeRegistry $purposes) {}

    public function allowed(string $subjectPersonId, string $resourceType, string $resourceId,
        string $category): bool
    {
        if ($this->blocks->isBlocked($subjectPersonId, null, $resourceType, $resourceId)) {
            return false;
        }
        foreach ($this->purposes->codes() as $code) {
            if (in_array($category, $this->purposes->get($code)?->dataCategories ?? [], true)
                && $this->blocks->isBlocked($subjectPersonId, $code, $resourceType, $resourceId)) {
                return false;
            }
        }
        return true;
    }
}
