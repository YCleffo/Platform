<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

interface RepresentativeAuthorityPolicy
{
    /** Caller must hold an enclosing transaction through the protected action. */
    public function assertActiveForAction(string $id, string $subjectPersonId, string $representativePersonId, string $actionCode): void;
}
