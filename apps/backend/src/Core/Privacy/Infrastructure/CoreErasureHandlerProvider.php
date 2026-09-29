<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\PersonalDataErasureHandler;
use App\Core\Organization\Infrastructure\OrganizationMembershipErasureHandler;
use IteratorAggregate;
use Traversable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @implements IteratorAggregate<int, PersonalDataErasureHandler> */
final readonly class CoreErasureHandlerProvider implements IteratorAggregate
{
    /** @param iterable<PersonalDataErasureHandler> $moduleHandlers */
    public function __construct(private ConnectionInterface $db, private iterable $moduleHandlers = []) {}

    /** @return Traversable<int, PersonalDataErasureHandler> */
    public function getIterator(): Traversable
    {
        yield new CorePersonContactErasureHandler($this->db);
        yield new OrganizationMembershipErasureHandler($this->db);
        yield new CoreAccountSettingsErasureHandler($this->db);
        yield from $this->moduleHandlers;
    }
}
