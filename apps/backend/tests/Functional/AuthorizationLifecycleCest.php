<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Account\Application\AccountLifecycleRepository;
use App\Core\Rbac\Application\RoleRepository;
use App\Core\Shared\Application\ConflictException;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class AuthorizationLifecycleCest
{
    public function protectsLastUsableSuperadmin(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();

        try {
            $first = $this->account($db, 'first superadmin');
            $second = $this->account($db, 'second superadmin');
            $role = $db->createCommand(
                "SELECT id::text FROM core.roles WHERE code = 'core.superadmin'",
            )->queryScalar();
            assertTrue(is_string($role));
            $db->createCommand(
                'INSERT INTO core.account_roles (account_id, role_id) VALUES (CAST(:account AS uuid), CAST(:role AS uuid))',
                [':account' => $first, ':role' => $role],
            )->execute();

            $roles = $container->get(RoleRepository::class);
            try {
                $roles->revoke($first, $role);
                $tester->fail('The last active superadmin role must not be revoked.');
            } catch (ConflictException) {
                // Required invariant.
            }

            assertTrue($roles->assign($second, $role, $first));
            assertTrue($roles->revoke($first, $role));

            $lifecycle = $container->get(AccountLifecycleRepository::class);
            try {
                $lifecycle->disable($second);
                $tester->fail('The last active superadmin account must not be disabled.');
            } catch (ConflictException) {
                // Required invariant.
            }

            assertTrue($roles->assign($first, $role, $second));
            $change = $lifecycle->disable($second);
            assertNotNull($change);
            assertSame('disabled', $change->afterStatus);
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }

    private function account(ConnectionInterface $db, string $label): string
    {
        $id = $db->createCommand(
            "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', :label) RETURNING id::text",
            [':label' => $label],
        )->queryScalar();
        if (!is_string($id)) {
            throw new \RuntimeException('Test account could not be created.');
        }

        return $id;
    }
}
