<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\PersonalDataContributor;
use App\Core\Organization\Infrastructure\OrganizationMembershipPersonalDataContributor;
use IteratorAggregate;
use Traversable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Module contributors are passed by DI; Core does not depend on modules.
 * @implements IteratorAggregate<int, PersonalDataContributor>
 */
final readonly class CorePersonalDataContributorProvider implements IteratorAggregate
{
    /** @param iterable<PersonalDataContributor> $moduleContributors */
    public function __construct(private ConnectionInterface $db, private iterable $moduleContributors = []) {}

    /** @return Traversable<int, PersonalDataContributor> */
    public function getIterator(): Traversable
    {
        yield new AuthPersonalDataContributor($this->db);
        yield new OrganizationMembershipPersonalDataContributor($this->db);
        foreach (CorePersonalDataContributor::codes() as $code) {
            yield new CorePersonalDataContributor($this->db, $code);
        }
        yield from $this->moduleContributors;
    }
}
