<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\RetentionCandidate;

interface RetentionCandidateContributor
{
    public function code(): string;
    /** Declares that this contributor can enumerate applicability for this purpose. */
    public function supportsPurpose(string $purposeCode): bool;
    /** @return iterable<RetentionCandidate> */
    public function candidates(int $limit, ?string $subjectPersonId = null): iterable;
}
