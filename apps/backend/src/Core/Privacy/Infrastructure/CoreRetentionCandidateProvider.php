<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionCandidateContributor;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use IteratorAggregate;
use Traversable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @implements IteratorAggregate<int, RetentionCandidateContributor> */
final readonly class CoreRetentionCandidateProvider implements IteratorAggregate
{
    /** @param iterable<RetentionCandidateContributor> $moduleContributors */
    public function __construct(private ConnectionInterface $db, private ProcessingPurposeRegistry $purposes,
        private RetentionPolicyRegistry $policies, private iterable $moduleContributors = []) {}

    public function getIterator(): Traversable
    {
        foreach (['core.person_contact', 'core.account_settings', 'core.organization_membership'] as $type) {
            yield new CoreResourceRetentionCandidateContributor($this->db, $this->purposes, $this->policies, $type);
        }
        yield from $this->moduleContributors;
    }
}
