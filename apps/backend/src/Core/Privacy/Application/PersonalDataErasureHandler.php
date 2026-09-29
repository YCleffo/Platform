<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

/** Implementations own one semantic resource and must be idempotent by item ID. */
interface PersonalDataErasureHandler
{
    public function code(): string;
    public function resourceType(): string;
    public function supportsAction(string $action): bool;
    public function owns(string $subjectPersonId, string $resourceId): bool;
    /** A durable module receipt may recover an action completed before local evidence committed. */
    public function reconcile(string $itemId): ?string;
    /** Return deleted, anonymized, pseudonymized, or custom_completed after real execution. */
    public function execute(string $subjectPersonId, string $resourceId, string $action, string $itemId): string;
}
