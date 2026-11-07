<?php

declare(strict_types=1);

namespace App\Storage\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Module\Application\ModuleRepository;
use App\Education\Application\EducationAccess;

final readonly class StorageAccess
{
    public function __construct(
        private ModuleRepository $modules,
        private EducationAccess $educationAccess,
    ) {}

    public function studentScope(AuthenticatedIdentity $identity, string $organizationId): StorageScope
    {
        if ($this->modules->findByCode('storage')?->enabled !== true) {
            throw new StorageError(404, 'module_unavailable');
        }
        $scope = $this->educationAccess->studentScope($identity, $organizationId, 'education.student.use');
        return new StorageScope($scope->organizationId, $scope->accountId, $scope->personId,
            $scope->locale, $scope->timezone, (string) $scope->studentId);
    }
}
