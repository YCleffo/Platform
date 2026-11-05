<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\PersonalDataErasureHandler;
use App\Core\Organization\Infrastructure\OrganizationMembershipErasureHandler;
use App\Core\Account\Application\AccountLifecycleRepository;
use App\Core\Auth\Application\AuthCleanupRepository;
use App\Core\Rbac\Application\RoleRepository;
use IteratorAggregate;
use Traversable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @implements IteratorAggregate<int, PersonalDataErasureHandler> */
final readonly class CoreErasureHandlerProvider implements IteratorAggregate
{
    /** @param iterable<PersonalDataErasureHandler> $moduleHandlers */
    public function __construct(
        private ConnectionInterface $db,
        private iterable $moduleHandlers = [],
        private ?AccountLifecycleRepository $accounts = null,
        private ?AuthCleanupRepository $auth = null,
        private ?RoleRepository $roles = null,
    ) {}

    /** @return Traversable<int, PersonalDataErasureHandler> */
    public function getIterator(): Traversable
    {
        yield new CorePersonContactErasureHandler($this->db);
        yield new OrganizationMembershipErasureHandler($this->db);
        yield new CoreAccountSettingsErasureHandler($this->db);
        if ($this->accounts !== null && $this->auth !== null && $this->roles !== null) {
            yield new CoreHumanAccountAnonymizationHandler($this->db, $this->accounts, $this->auth, $this->roles);
        }
        yield new CorePersonAnonymizationHandler($this->db);
        yield from $this->moduleHandlers;
    }
}
