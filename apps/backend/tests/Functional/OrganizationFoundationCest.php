<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Organization\Application\OrganizationAccessPolicy;
use App\Core\Organization\Infrastructure\OrganizationMembershipErasureHandler;
use App\Core\Organization\Infrastructure\OrganizationMembershipPersonalDataContributor;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class OrganizationFoundationCest
{
    public function targetScopeAndPrivacyOwnership(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $person = $this->person($db);
            $outside = $this->person($db);
            $organization = (string) $db->createCommand(
                "INSERT INTO core.organizations (code, name) VALUES ('test.privacy.scope', 'Test') RETURNING id::text",
            )->queryScalar();
            $membership = (string) $db->createCommand(
                'INSERT INTO core.organization_memberships (organization_id, person_id) VALUES (CAST(:organization AS uuid), CAST(:person AS uuid)) RETURNING id::text',
                [':organization' => $organization, ':person' => $person],
            )->queryScalar();

            $policy = $container->get(OrganizationAccessPolicy::class);
            $policy->assertPersonBelongsToOrganization($person, $organization);
            try {
                $policy->assertPersonBelongsToOrganization($outside, $organization);
                throw new \RuntimeException('Outside person was accepted.');
            } catch (DomainException) {
                // Expected organization boundary.
            }

            $contributor = new OrganizationMembershipPersonalDataContributor($db);
            $context = static fn(string $subject): PersonalDataExportContext =>
                new PersonalDataExportContext($subject, $membership, $subject, $membership,
                    null, new DateTimeImmutable('2026-09-29T10:00:00Z'));
            $section = $contributor->collect($context($person));
            assertCount(1, $section->records);
            assertSame($organization, $section->records[0]['organizationId']);
            assertCount(0, $contributor->collect($context($outside))->records);

            $handler = new OrganizationMembershipErasureHandler($db);
            assertTrue($handler->owns($person, $membership));
            assertFalse($handler->owns($outside, $membership));
            assertSame('deleted', $handler->execute($person, $membership, 'delete', $membership));
            assertFalse($handler->owns($person, $membership));
        } finally {
            $transaction->rollBack();
        }
    }

    private function person(ConnectionInterface $db): string
    {
        return (string) $db->createCommand(
            "INSERT INTO core.persons (given_name, family_name) VALUES ('Test', 'Scope') RETURNING id::text",
        )->queryScalar();
    }
}
